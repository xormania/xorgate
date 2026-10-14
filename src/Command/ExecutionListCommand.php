<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:execution:list', description: 'Retrieve a bounded UUID cursor page of executions scoped to a managed project as JSON.')]
final class ExecutionListCommand extends RegistryCommand
{
    protected function configure(): void
    {
        $this->addArgument('project-id', InputArgument::REQUIRED, 'Persistent project UUID.');
        $this->configurePage();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->registry->listExecutions($input->getArgument('project-id'), $this->pageLimit($input), $input->getOption('cursor'));
    }
}
