<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\IntakeCapacityException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Private snapshots: complete immutable bytes are published before their database catalogue. */
final class ReportArtifactStore
{
    public function __construct(#[Autowire('%env(XORGATE_ARTIFACT_DIR)%')] private readonly string $directory)
    {
    }

    /** @param resource $stream */
    public function stage($stream, int $maximum, int $storageMaximum = 536_870_912, int $fileMaximum = 1_000): array
    {
        if (!is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new \InvalidArgumentException('Report content must be a readable stream.');
        }
        if (min($maximum, $storageMaximum, $fileMaximum) < 1) {
            throw new \InvalidArgumentException('Report storage limits must be positive.');
        }
        // Prehash seekable inputs without reserving another inode or copying bytes.
        // A verified orphan can then be reused even when the physical budget is full.
        $position = ftell($stream);
        if (false !== $position && (stream_get_meta_data($stream)['seekable'] ?? false)) {
            [$hash, $size] = $this->checksum($stream, $maximum);
            if (0 !== fseek($stream, $position)) {
                throw new \RuntimeException('Report content could not be rewound.');
            }
            if ($this->availability($hash, $size)['available']) {
                return ['temporary' => null, 'sha256' => $hash, 'bytes' => $size, 'existing_blob' => true];
            }
        }
        [$temporary, $target] = $this->locked(function () use ($storageMaximum, $fileMaximum): array {
            $this->assertReservation(0, true, $storageMaximum, $fileMaximum);
            $parent = $this->ownedDirectory('report-staging');
            $temporary = @tempnam($parent, '.stage-');
            if (false === $temporary || realpath(dirname($temporary)) !== $parent) {
                if (is_string($temporary)) { @unlink($temporary); }
                throw new \RuntimeException('Report temporary storage is unavailable.');
            }
            $target = @fopen($temporary, 'r+b');
            if (false === $target || !flock($target, LOCK_EX | LOCK_NB)) {
                if (is_resource($target)) { fclose($target); }
                @unlink($temporary);
                throw new \RuntimeException('Report temporary storage is unavailable.');
            }

            return [$temporary, $target];
        });
        $digest = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $block = fread($stream, 65536);
                if (false === $block || ('' === $block && !feof($stream))) {
                    throw new \RuntimeException('Report content could not be read.');
                }
                $bytes += strlen($block);
                if ($bytes > $maximum) {
                    throw new \InvalidArgumentException('Report content exceeds the configured upload byte limit.');
                }
                hash_update($digest, $block);
                // Never hold this lock while waiting for incoming bytes. Every
                // write is quota-checked and flushed before another writer scans.
                $this->locked(function () use ($target, $block, $storageMaximum, $fileMaximum): void {
                    $this->assertReservation(strlen($block), false, $storageMaximum, $fileMaximum);
                    try {
                        $offset = 0;
                        while ($offset < strlen($block)) {
                            $written = fwrite($target, substr($block, $offset));
                            if (false === $written || 0 === $written) {
                                throw new \RuntimeException('Report temporary storage could not be written.');
                            }
                            $offset += $written;
                        }
                    } finally {
                        if (!fflush($target) || !fsync($target)) {
                            throw new \RuntimeException('Report temporary storage could not be flushed.');
                        }
                    }
                });
            }

            return ['temporary' => $temporary, 'sha256' => hash_final($digest), 'bytes' => $bytes, 'existing_blob' => false];
        } catch (\Throwable $exception) {
            // Close before unlinking, under admission lock: even an exceptional
            // buffered write cannot leave an allocated, uncounted open inode.
            $this->locked(function () use ($target): void {
                if (is_resource($target)) {
                    flock($target, LOCK_UN);
                    fclose($target);
                }
            });
            $this->discard(['temporary' => $temporary]);
            throw $exception;
        } finally {
            if (is_resource($target)) {
                flock($target, LOCK_UN);
                fclose($target);
            }
        }
    }

    public function discard(array $staged): void
    {
        if (null === $staged['temporary']) {
            return;
        }
        $this->locked(function () use ($staged): void {
            $path = $this->stagePath($staged['temporary']);
            if (file_exists($path) && !@unlink($path)) {
                throw new \RuntimeException('Report temporary cleanup failed.');
            }
        });
    }

    public function publish(array $staged): string
    {
        return $this->locked(function () use ($staged): string {
            $hash = self::hash($staged['sha256']);
            $destination = $this->path($hash);
            if (is_link($destination)) {
                throw new \RuntimeException('Report storage location is unsafe.');
            }
            if (null !== $staged['temporary'] && !@link($this->stagePath($staged['temporary']), $destination) && !is_file($destination)) {
                throw new \RuntimeException('Report bytes could not be published.');
            }
            // Verify both newly linked bytes and a reused immutable destination.
            $stream = $this->open($hash, $staged['bytes']);
            fclose($stream);
            $this->syncDirectory(dirname($destination));

            return 'report-blobs/'.$hash;
        });
    }

    /** @return resource */
    public function open(string $hash, int $bytes)
    {
        $path = $this->path($hash);
        $before = @lstat($path);
        if (false !== $before && 0100000 !== ($before['mode'] & 0170000)) {
            throw new \RuntimeException('Report storage location is unsafe.');
        }
        $stream = @fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Original report bytes are missing or unavailable.');
        }
        try {
            $stat = fstat($stream);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (false === $before || false === $after || false === $stat
                || $before['dev'] !== $stat['dev'] || $before['ino'] !== $stat['ino']
                || $after['dev'] !== $stat['dev'] || $after['ino'] !== $stat['ino']
                || 0100000 !== ($after['mode'] & 0170000) || $stat['size'] !== $bytes) {
                throw new \RuntimeException('Original report bytes failed their integrity check.');
            }
            $digest = hash_init('sha256');
            hash_update_stream($digest, $stream);
            if (!hash_equals($hash, hash_final($digest)) || !rewind($stream)) {
                throw new \RuntimeException('Original report bytes failed their integrity check.');
            }

            return $stream;
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    public function availability(string $hash, int $bytes, bool $verify = true): array
    {
        try {
            $path = $this->path($hash);
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (false === $stat) {
                return ['status' => 'missing', 'available' => false, 'integrity_checked' => false];
            }
            if (0100000 !== ($stat['mode'] & 0170000) || $stat['size'] !== $bytes) {
                return ['status' => 'corrupt', 'available' => false, 'integrity_checked' => false];
            }
            if (!$verify) {
                return ['status' => 'present', 'available' => false, 'integrity_checked' => false];
            }
            try {
                $stream = $this->open($hash, $bytes);
                fclose($stream);
            } catch (\Throwable) {
                return ['status' => 'corrupt', 'available' => false, 'integrity_checked' => false];
            }

            return ['status' => 'available', 'available' => true, 'integrity_checked' => true];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'available' => false, 'integrity_checked' => false];
        }
    }

    public function remove(string $hash): void
    {
        $this->locked(fn () => $this->removeUnlocked($hash));
    }

    /** Count complete blobs too, so repeated failed DB commits cannot bypass persistent quotas. */
    public function usage(int $maximumFiles): array
    {
        return $this->locked(fn (): array => $this->scanUsage($maximumFiles), LOCK_SH);
    }

    /** Called under the same admission lock as publication; never removes referenced bytes. */
    public function cleanupOrphans(array $knownHashes, int $cutoff, int $batch = 100): array
    {
        return $this->locked(fn (): array => $this->cleanupUnlocked($knownHashes, $cutoff, $batch));
    }

    private function cleanupUnlocked(array $knownHashes, int $cutoff, int $batch): array
    {
        $removed = $staged = $examined = 0;
        $known = array_fill_keys($knownHashes, true);
        foreach (new \DirectoryIterator($this->ownedDirectory('report-blobs')) as $file) {
            if ($file->isDot() || isset($known[$file->getFilename()])) {
                continue;
            }
            if (++$examined > $batch) {
                break;
            }
            if (!$file->isLink() && $file->isFile() && preg_match('/\A[a-f0-9]{64}\z/D', $file->getFilename()) && $file->getMTime() < $cutoff) {
                $this->removeUnlocked($file->getFilename());
                ++$removed;
            }
        }
        $examined = 0;
        foreach (new \DirectoryIterator($this->ownedDirectory('report-staging')) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if (++$examined > $batch) {
                break;
            }
            if (!$file->isLink() && $file->isFile() && str_starts_with($file->getFilename(), '.stage-') && $file->getMTime() < $cutoff) {
                $stream = @fopen($file->getPathname(), 'rb');
                if (false === $stream) {
                    continue;
                }
                try {
                    // A slow live upload retains this lease while waiting for
                    // bytes; cleanup must not remove its still-active inode.
                    if (!flock($stream, LOCK_EX | LOCK_NB)) {
                        continue;
                    }
                    if (!@unlink($file->getPathname())) {
                        throw new \RuntimeException('Report temporary retention cleanup failed.');
                    }
                    ++$staged;
                } finally {
                    fclose($stream);
                }
            }
        }

        return ['orphan_blobs' => $removed, 'staged_files' => $staged];
    }

    /** Caller holds the private filesystem lock; hardlinked stages/blobs count once. */
    private function scanUsage(int $maximumFiles): array
    {
        if ($maximumFiles < 1) {
            throw new \InvalidArgumentException('Report file scan limit must be positive.');
        }
        $result = ['bytes' => 0, 'files' => 0, 'staging_bytes' => 0, 'staging_files' => 0,
            'physical_bytes' => 0, 'physical_files' => 0, 'scan_truncated' => false];
        $seen = [];
        $examined = 0;
        clearstatcache();
        foreach (['report-blobs', 'report-staging'] as $child) {
            foreach (new \DirectoryIterator($this->ownedDirectory($child)) as $file) {
                if ($file->isDot()) { continue; }
                // One managed inode may have both its staging and blob name.
                if (++$examined > 2 * $maximumFiles + 1) {
                    $result['scan_truncated'] = true;

                    return $result;
                }
                $name = $file->getFilename();
                $valid = 'report-blobs' === $child ? preg_match('/\A[a-f0-9]{64}\z/D', $name) : str_starts_with($name, '.stage-');
                $stat = @lstat($file->getPathname());
                if (!$valid || false === $stat || 0100000 !== ($stat['mode'] & 0170000)) {
                    throw new \RuntimeException('Report storage contains an unsafe managed location.');
                }
                $bytesKey = 'report-blobs' === $child ? 'bytes' : 'staging_bytes';
                $filesKey = 'report-blobs' === $child ? 'files' : 'staging_files';
                $result[$bytesKey] += $stat['size'];
                ++$result[$filesKey];
                $identity = $stat['dev'].':'.$stat['ino'];
                if (!isset($seen[$identity])) {
                    $seen[$identity] = true;
                    $result['physical_bytes'] += $stat['size'];
                    ++$result['physical_files'];
                    if ($result['physical_files'] > $maximumFiles) {
                        $result['scan_truncated'] = true;

                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    private function assertReservation(int $bytes, bool $newFile, int $storageMaximum, int $fileMaximum): void
    {
        $usage = $this->scanUsage($fileMaximum);
        $reason = match (true) {
            $usage['scan_truncated'] || $usage['physical_files'] + (int) $newFile > $fileMaximum => 'intake_blob_limit',
            $usage['physical_bytes'] + $bytes > $storageMaximum => 'intake_storage_limit',
            default => null,
        };
        if (null !== $reason) {
            $capacityUsage = ['blob_bytes' => $usage['bytes'], 'blob_files' => $usage['files'],
                'blob_scan_truncated' => $usage['scan_truncated'], 'staging_bytes' => $usage['staging_bytes'],
                'staging_files' => $usage['staging_files'], 'physical_bytes' => $usage['physical_bytes'],
                'physical_files' => $usage['physical_files']];
            throw new IntakeCapacityException(['schema_version' => 1, 'status' => 'limited', 'reason' => $reason,
                'actor_scope' => 'shared_installation', 'retry_after_seconds' => null, 'usage' => $capacityUsage,
                'limits' => ['storage_bytes' => $storageMaximum, 'blob_files' => $fileMaximum]]);
        }
    }

    /** @param resource $stream */
    private function checksum($stream, int $maximum): array
    {
        $digest = hash_init('sha256');
        $bytes = 0;
        while (!feof($stream)) {
            $block = fread($stream, 65_536);
            if (false === $block || ('' === $block && !feof($stream))) {
                throw new \RuntimeException('Report content could not be read.');
            }
            if (($bytes += strlen($block)) > $maximum) {
                throw new \InvalidArgumentException('Report content exceeds the configured upload byte limit.');
            }
            hash_update($digest, $block);
        }

        return [hash_final($digest), $bytes];
    }

    private function stagePath(string $path): string
    {
        if (realpath(dirname($path)) !== $this->ownedDirectory('report-staging')
            || !str_starts_with(basename($path), '.stage-') || is_link($path)) {
            throw new \RuntimeException('Report temporary storage location is unsafe.');
        }

        return $path;
    }

    private function removeUnlocked(string $hash): void
    {
        $path = $this->path($hash);
        if (is_link($path)) {
            throw new \RuntimeException('Report retention location is unsafe.');
        }
        if (file_exists($path) && !@unlink($path)) {
            throw new \RuntimeException('Report retention cleanup failed.');
        }
    }

    /** Lock is private, constant and never returned in a public response. */
    private function locked(callable $operation, int $mode = LOCK_EX): mixed
    {
        $root = dirname($this->ownedDirectory('report-staging'));
        $path = $root.'/report-storage.lock';
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (false !== $before && 0100000 !== ($before['mode'] & 0170000)) {
            throw new \RuntimeException('Report storage lock is unsafe.');
        }
        $handle = @fopen($path, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException('Report storage lock is unavailable.');
        }
        try {
            @chmod($path, 0600);
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (false === $stat || false === $after || 0100000 !== ($after['mode'] & 0170000)
                || $stat['dev'] !== $after['dev'] || $stat['ino'] !== $after['ino']
                || (false !== $before && ($before['dev'] !== $stat['dev'] || $before['ino'] !== $stat['ino']))
                || !flock($handle, $mode)) {
                throw new \RuntimeException('Report storage lock is unavailable or unsafe.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $hash): string
    {
        return $this->ownedDirectory('report-blobs').'/'.self::hash($hash);
    }

    private static function hash(string $hash): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
            throw new \InvalidArgumentException('The artifact digest must be SHA-256.');
        }

        return $hash;
    }

    private function ownedDirectory(string $child): string
    {
        $root = rtrim($this->directory, '/');
        if (realpath($root) !== $root || is_link($root)) {
            throw new \RuntimeException('Report storage root is unavailable or unsafe.');
        }
        $path = $root.'/'.$child;
        if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
            throw new \RuntimeException('Report storage is unavailable.');
        }
        if (is_link($path) || realpath($path) !== $path) {
            throw new \RuntimeException('Report storage location is unsafe.');
        }

        return $path;
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if (false === $handle) {
            throw new \RuntimeException('Report publication could not be flushed.');
        }
        try {
            if (!@fsync($handle)) {
                throw new \RuntimeException('Report publication could not be flushed.');
            }
        } finally {
            fclose($handle);
        }
    }
}
