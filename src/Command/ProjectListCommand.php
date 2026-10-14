<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:project:list', description: 'Retrieve a bounded UUID cursor page of managed projects as JSON.')]
final class ProjectListCommand extends RegistryCommand
{
    protected function configure(): void
    {
        $this->configurePage();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->registry->list($this->pageLimit($input), $input->getOption('cursor'));
    }
}
