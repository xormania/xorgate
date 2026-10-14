<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:project:get', description: 'Retrieve a managed project by its persistent UUID as JSON.')]
final class ProjectGetCommand extends RegistryCommand
{
    protected function configure(): void
    {
        $this->addArgument('project-id', InputArgument::REQUIRED, 'Persistent project UUID.');
    }

    protected function operation(InputInterface $input): array
    {
        return $this->registry->get($input->getArgument('project-id'));
    }
}
