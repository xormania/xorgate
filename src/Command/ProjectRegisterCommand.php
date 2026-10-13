<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:project:register', description: 'Register a managed project from bounded JSON and return its persistent record.')]
final class ProjectRegisterCommand extends RegistryCommand
{
    protected function configure(): void
    {
        $this->configureInput();
    }

    protected function operation(InputInterface $input): array
    {
        return $this->registry->register($this->payload($input));
    }
}
