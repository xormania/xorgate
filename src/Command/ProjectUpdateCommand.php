<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:project:update', description: 'Replace project metadata from bounded JSON with request_id and expected_version; preserve its identity.')]
final class ProjectUpdateCommand extends RegistryCommand
{
    protected function configure(): void
    {
        $this->addArgument('project-id', InputArgument::REQUIRED, 'Persistent project UUID.');
        $this->configureInput();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->registry->update($input->getArgument('project-id'), $this->payload($input));
    }
}
