<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:operation:get', description: 'Inspect durable report operation progress, parser outcome and original artifact availability.')]
final class IntakeOperationCommand extends ArtifactCommand
{
    protected function configure(): void
    {
        $this->configureScope();
        $this->addArgument('operation-id', InputArgument::REQUIRED, 'Durable operation UUID.');
    }

    protected function operation(InputInterface $input): array
    {
        return $this->intake->getOperation($input->getArgument('project-id'), $input->getArgument('execution-id'), $input->getArgument('operation-id'));
    }
}

