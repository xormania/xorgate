<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:attempts:list', description: 'List a bounded page of native test attempts, including retries, for one report operation.')]
final class AttemptListCommand extends ArtifactCommand
{
    protected function configure(): void
    {
        $this->configureScope();
        $this->addArgument('operation-id', InputArgument::REQUIRED, 'Durable native report operation UUID.');
        $this->configurePage();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->intake->listAttempts($input->getArgument('project-id'), $input->getArgument('execution-id'), $input->getArgument('operation-id'), $this->integerOption($input, 'limit', 1, 100), $input->getOption('cursor'));
    }
}
