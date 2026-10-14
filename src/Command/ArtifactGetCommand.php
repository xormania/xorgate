<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:artifact:get', description: 'Inspect original artifact metadata and availability by scoped opaque UUID.')]
final class ArtifactGetCommand extends ArtifactCommand
{
    protected function configure(): void
    {
        $this->configureScope();
        $this->addArgument('artifact-id', InputArgument::REQUIRED, 'Original artifact UUID.');
    }

    protected function operation(InputInterface $input): array
    {
        return $this->intake->getArtifact($input->getArgument('project-id'), $input->getArgument('execution-id'), $input->getArgument('artifact-id'));
    }
}

