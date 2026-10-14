<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:observations:list', description: 'List a bounded page of normalized native cases for one report operation.')]
final class ObservationListCommand extends ArtifactCommand
{
    protected function configure(): void
    {
        $this->configureScope();
        $this->addArgument('operation-id', InputArgument::REQUIRED, 'Durable operation UUID.');
        $this->configurePage();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->intake->listObservations($input->getArgument('project-id'), $input->getArgument('execution-id'), $input->getArgument('operation-id'), $this->integerOption($input, 'limit', 1, 100), $input->getOption('cursor'));
    }
}

