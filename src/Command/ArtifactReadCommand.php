<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'xorgate:artifact:read', description: 'Read a verified original artifact chunk as bounded binary-safe base64 JSON.')]
final class ArtifactReadCommand extends ArtifactCommand
{
    protected function configure(): void
    {
        $this->configureScope();
        $this
            ->addArgument('artifact-id', InputArgument::REQUIRED, 'Original artifact UUID.')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Byte offset, at least zero.', '0')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Chunk size from 1 to 32768 bytes.', '32768');
    }

    protected function operation(InputInterface $input): array
    {
        return $this->intake->readArtifact(
            $input->getArgument('project-id'),
            $input->getArgument('execution-id'),
            $input->getArgument('artifact-id'),
            $this->integerOption($input, 'offset', 0, 2_147_483_647),
            $this->integerOption($input, 'limit', 1, 32_768),
        );
    }
}
