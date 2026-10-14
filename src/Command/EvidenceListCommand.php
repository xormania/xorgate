<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:evidence:list', description: 'List a bounded page of execution report operation summaries, including parser failures.')]
final class EvidenceListCommand extends ArtifactCommand
{
    protected function configure(): void
    {
        $this->configureScope();
        $this->configurePage();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->intake->listEvidence($input->getArgument('project-id'), $input->getArgument('execution-id'), $this->integerOption($input, 'limit', 1, 100), $input->getOption('cursor'));
    }
}

