<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:execution:record', description: 'Record immutable execution provenance from bounded JSON; this does not launch tests.')]
final class ExecutionRecordCommand extends RegistryCommand
{
    protected function configure(): void
    {
        $this->addArgument('project-id', InputArgument::REQUIRED, 'Persistent project UUID.');
        $this->configureInput();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->registry->recordExecution($input->getArgument('project-id'), $this->payload($input));
    }
}
