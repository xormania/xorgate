<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\RegistryResult;
use App\Service\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class RegistryCommand extends Command
{
    private const MAX_INPUT_BYTES = 65_536;

    public function __construct(protected readonly ProjectRegistry $registry)
    {
        parent::__construct();
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->operation($input);
            $exitCode = Command::SUCCESS;
        } catch (\Throwable $exception) {
            $result = RegistryResult::error($exception);
            $exitCode = $exception instanceof \InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }

        // Raw output keeps stored metadata literal rather than interpreting Console markup.
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return $exitCode;
    }

    abstract protected function operation(InputInterface $input): array;

    protected function configureInput(): void
    {
        $this->addOption('input', null, InputOption::VALUE_REQUIRED, 'JSON object file (maximum 64 KiB), or - for standard input. Include your request_id UUID.', '-');
    }

    protected function configurePage(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Page size from 1 to 100.', '25')
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'UUID next_cursor from the preceding page.');
    }

    protected function pageLimit(InputInterface $input): int
    {
        $value = $input->getOption('limit');
        if (!is_string($value) || !preg_match('/\A(?:[1-9][0-9]?|100)\z/', $value)) {
            throw new \InvalidArgumentException('limit must be an integer from 1 to 100.');
        }

        return (int) $value;
    }

    protected function payload(InputInterface $input): array
    {
        $path = $input->getOption('input');
        if (!is_string($path) || '' === $path) {
            throw new \InvalidArgumentException('input must be a JSON file path or - for standard input.');
        }
        if ('-' !== $path && !is_file($path)) {
            throw new \InvalidArgumentException('input must name a readable regular JSON file.');
        }

        $stream = @fopen('-' === $path ? 'php://stdin' : $path, 'rb');
        if (false === $stream) {
            throw new \InvalidArgumentException('input must name a readable regular JSON file.');
        }
        try {
            $json = stream_get_contents($stream, self::MAX_INPUT_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (false === $json || strlen($json) > self::MAX_INPUT_BYTES) {
            throw new \InvalidArgumentException('input must be at most 64 KiB.');
        }

        try {
            $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new \InvalidArgumentException('input must be a JSON object.');
            }

            return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('input must be a valid JSON object with nesting depth at most 16.');
        }
    }
}
