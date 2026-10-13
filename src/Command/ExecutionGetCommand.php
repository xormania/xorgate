<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:execution:get', description: 'Retrieve immutable execution provenance scoped to its project as JSON.')]
final class ExecutionGetCommand extends RegistryCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('project-id', InputArgument::REQUIRED, 'Persistent project UUID.')
            ->addArgument('execution-id', InputArgument::REQUIRED, 'Persistent execution UUID.');
    }

    protected function operation(InputInterface $input): array
    {
        return $this->registry->getExecution($input->getArgument('project-id'), $input->getArgument('execution-id'));
    }
}
