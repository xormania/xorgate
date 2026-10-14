<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\ArtifactResult;
use App\Service\ReportIntake;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ArtifactCommand extends Command
{
    public function __construct(protected readonly ReportIntake $intake)
    {
        parent::__construct();
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->operation($input);
            $code = Command::SUCCESS;
        } catch (\Throwable $exception) {
            $result = ArtifactResult::error($exception);
            $code = $exception instanceof \InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return $code;
    }

    abstract protected function operation(InputInterface $input): array;

    protected function configureScope(): void
    {
        $this
            ->addArgument('project-id', InputArgument::REQUIRED, 'Owning project UUID.')
            ->addArgument('execution-id', InputArgument::REQUIRED, 'Immutable execution UUID within that project.');
    }

    protected function configurePage(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Page size from 1 to 100.', '25')
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'UUID next_cursor from the preceding page in this scope.');
    }

    protected function integerOption(InputInterface $input, string $name, int $minimum, int $maximum): int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
            || strlen($value) > 10 || (int) $value < $minimum || (int) $value > $maximum) {
            throw new \InvalidArgumentException($name.' must be an integer from '.$minimum.' to '.$maximum.'.');
        }

        return (int) $value;
    }

    protected function metadata(InputInterface $input): array
    {
        $path = $input->getOption('input');
        if (!is_string($path) || '' === $path) {
            throw new \InvalidArgumentException('input must name a JSON file or - for standard input.');
        }
        $stream = $this->openInput($path);
        try {
            $json = stream_get_contents($stream, 65_537);
        } finally {
            fclose($stream);
        }
        if (false === $json || strlen($json) > 65_536) {
            throw new \InvalidArgumentException('Metadata input must be at most 64 KiB.');
        }
        try {
            $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new \InvalidArgumentException('Metadata input must be a JSON object.');
            }

            return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Metadata input must be valid JSON with nesting depth at most 16.');
        }
    }

    /** @return resource */
    protected function openInput(string $path)
    {
        $before = null;
        if ('-' !== $path) {
            if ('' === $path || str_contains($path, '://')) {
                throw new \InvalidArgumentException('Input must be a readable regular local file without symlinks, or - for standard input.');
            }
            // lstat does not follow the final symlink. Reject FIFOs before opening
            // so an invalid file cannot block while waiting for a writer.
            clearstatcache(true, $path);
            $before = @lstat($path);
            if (false === $before || 0100000 !== ($before['mode'] & 0170000)) {
                throw new \InvalidArgumentException('Input must be a readable regular local file without symlinks, or - for standard input.');
            }
            for ($directory = dirname($path); ; $directory = $parent) {
                if (is_link($directory)) {
                    throw new \InvalidArgumentException('Input must be a readable regular local file without symlinks, or - for standard input.');
                }
                $parent = dirname($directory);
                if ($parent === $directory) {
                    break;
                }
            }
        }
        $stream = @fopen('-' === $path ? 'php://stdin' : $path, 'rb');
        if (false === $stream) {
            throw new \InvalidArgumentException('Input must be a readable regular file or - for standard input.');
        }
        if (null !== $before) {
            $after = fstat($stream);
            if (false === $after || 0100000 !== ($after['mode'] & 0170000)
                || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']) {
                fclose($stream);
                throw new \InvalidArgumentException('Input must remain the same regular local file while opening.');
            }
        }

        return $stream;
    }
}
