<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Private snapshots: complete immutable bytes are published before their database catalogue. */
final class ReportArtifactStore
{
    public function __construct(#[Autowire('%env(XORGATE_ARTIFACT_DIR)%')] private readonly string $directory)
    {
    }

    /** @param resource $stream */
    public function stage($stream, int $maximum): array
    {
        if (!is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new \InvalidArgumentException('Report content must be a readable stream.');
        }
        $parent = $this->ownedDirectory('report-staging');
        $temporary = @tempnam($parent, '.stage-');
        if (false === $temporary || realpath(dirname($temporary)) !== $parent) {
            if (is_string($temporary)) { @unlink($temporary); }
            throw new \RuntimeException('Report temporary storage is unavailable.');
        }
        $target = @fopen($temporary, 'wb');
        if (false === $target) {
            @unlink($temporary);
            throw new \RuntimeException('Report temporary storage is unavailable.');
        }
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
                $offset = 0;
                while ($offset < strlen($block)) {
                    $written = fwrite($target, substr($block, $offset));
                    if (false === $written || 0 === $written) {
                        throw new \RuntimeException('Report temporary storage could not be written.');
                    }
                    $offset += $written;
                }
            }
            if (!fflush($target) || !fsync($target)) {
                throw new \RuntimeException('Report temporary storage could not be flushed.');
            }

            return ['temporary' => $temporary, 'sha256' => hash_final($digest), 'bytes' => $bytes];
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($target);
        }
    }

    public function discard(array $staged): void
    {
        $path = $staged['temporary'];
        if (realpath(dirname($path)) !== $this->ownedDirectory('report-staging') || is_link($path)) {
            throw new \RuntimeException('Report temporary storage location is unsafe.');
        }
        if (file_exists($path) && !@unlink($path)) {
            throw new \RuntimeException('Report temporary cleanup failed.');
        }
    }

    public function publish(array $staged): string
    {
        $hash = self::hash($staged['sha256']);
        $destination = $this->path($hash);
        if (is_link($destination)) {
            throw new \RuntimeException('Report storage location is unsafe.');
        }
        if (!@link($staged['temporary'], $destination)) {
            if (!is_file($destination)) {
                throw new \RuntimeException('Report bytes could not be published.');
            }
            $stream = $this->open($hash, $staged['bytes']);
            fclose($stream);
        }
        $this->syncDirectory(dirname($destination));

        return 'report-blobs/'.$hash;
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

    public function availability(string $hash, int $bytes): array
    {
        try {
            $path = $this->path($hash);
            if (!file_exists($path) && !is_link($path)) {
                return ['status' => 'missing', 'available' => false];
            }
            if (is_link($path) || !is_file($path) || filesize($path) !== $bytes || @hash_file('sha256', $path) !== $hash) {
                return ['status' => 'corrupt', 'available' => false];
            }

            return ['status' => 'available', 'available' => true];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'available' => false];
        }
    }

    public function remove(string $hash): void
    {
        $path = $this->path($hash);
        if (is_link($path)) {
            throw new \RuntimeException('Report retention location is unsafe.');
        }
        if (file_exists($path) && !@unlink($path)) {
            throw new \RuntimeException('Report retention cleanup failed.');
        }
    }

    /** Count complete blobs too, so repeated failed DB commits cannot bypass persistent quotas. */
    public function usage(int $maximumFiles): array
    {
        $count = $bytes = 0;
        foreach (new \DirectoryIterator($this->ownedDirectory('report-blobs')) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if ($file->isLink() || !$file->isFile() || !preg_match('/\A[a-f0-9]{64}\z/D', $file->getFilename())) {
                throw new \RuntimeException('Report storage contains an unsafe managed location.');
            }
            ++$count;
            $bytes += $file->getSize();
            if ($count > $maximumFiles) {
                return ['bytes' => $bytes, 'files' => $count, 'scan_truncated' => true];
            }
        }

        return ['bytes' => $bytes, 'files' => $count, 'scan_truncated' => false];
    }

    /** Called under the same admission lock as publication; never removes referenced bytes. */
    public function cleanupOrphans(array $knownHashes, int $cutoff, int $batch = 100): array
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
                $this->remove($file->getFilename());
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
                if (!@unlink($file->getPathname())) {
                    throw new \RuntimeException('Report temporary retention cleanup failed.');
                }
                ++$staged;
            }
        }

        return ['orphan_blobs' => $removed, 'staged_files' => $staged];
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
