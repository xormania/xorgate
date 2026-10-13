<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Stores readiness artifacts separately from disposable Symfony caches. */
final class ArtifactStorage
{
    public function __construct(
        #[Autowire('%env(XORGATE_ARTIFACT_DIR)%')]
        private readonly string $directory,
    ) {
    }

    public function checkWritable(): void
    {
        $path = '.readiness-'.bin2hex(random_bytes(16));
        $this->writeAtomic($path, "xorgate artifact readiness\n");

        if (!@unlink($this->absolutePath($path))) {
            throw new \RuntimeException('Artifact readiness cleanup failed.');
        }
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    public function writeProbe(string $probeId, array $contents): array
    {
        $probeId = FoundationProbe::canonicalId($probeId);
        $path = 'foundation/'.$probeId.'.json';
        $json = json_encode($contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $this->writeAtomic($path, $json);

        return ['path' => $path, 'sha256' => hash('sha256', $json), 'bytes' => strlen($json)];
    }

    public function isAvailable(string $path, string $expectedHash): bool
    {
        if (!preg_match('/\Afoundation\/[0-9a-f-]{36}\.json\z/D', $path)) {
            return false;
        }

        $actualHash = @hash_file('sha256', $this->absolutePath($path));

        return \is_string($actualHash) && hash_equals($expectedHash, $actualHash);
    }

    private function absolutePath(string $path): string
    {
        return rtrim($this->directory, '/').'/'.$path;
    }

    public function removeProbe(string $probeId): void
    {
        $path = $this->absolutePath('foundation/'.FoundationProbe::canonicalId($probeId).'.json');
        $root = realpath($this->directory);
        if (false === $root) {
            throw new \RuntimeException('Artifact retention storage is unavailable.');
        }
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        // Never follow a substituted foundation directory outside the configured store.
        if (realpath(\dirname($path)) !== $root.'/foundation' || is_link(\dirname($path))) {
            throw new \RuntimeException('Artifact retention location is unsafe.');
        }
        if (!@unlink($path)) {
            throw new \RuntimeException('Artifact retention cleanup failed.');
        }
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $destination = $this->absolutePath($path);
        $parent = \dirname($destination);

        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new \RuntimeException('Artifact storage is unavailable.');
        }

        $temporary = @tempnam($parent, '.xorgate-');
        if (false === $temporary) {
            throw new \RuntimeException('Artifact temporary storage is unavailable.');
        }

        // tempnam can fall back to the system directory: refuse non-atomic cross-volume writes.
        if (realpath(\dirname($temporary)) !== realpath($parent)) {
            @unlink($temporary);
            throw new \RuntimeException('Artifact temporary storage is unavailable.');
        }

        try {
            $handle = @fopen($temporary, 'wb');
            if (false === $handle) {
                throw new \RuntimeException('Artifact storage could not be opened.');
            }

            try {
                $offset = 0;
                $length = strlen($contents);
                while ($offset < $length) {
                    $written = @fwrite($handle, substr($contents, $offset));
                    if (false === $written || 0 === $written) {
                        throw new \RuntimeException('Artifact storage could not be written.');
                    }
                    $offset += $written;
                }

                if (!@fflush($handle) || (\function_exists('fsync') && !@fsync($handle))) {
                    throw new \RuntimeException('Artifact storage could not be flushed.');
                }
            } finally {
                fclose($handle);
            }

            if (!@rename($temporary, $destination)) {
                throw new \RuntimeException('Artifact storage could not be committed.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
