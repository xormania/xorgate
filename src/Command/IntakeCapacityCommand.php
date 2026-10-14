<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:intake:capacity', description: 'Inspect installation-wide report admission, storage and retention limits.')]
final class IntakeCapacityCommand extends ArtifactCommand
{
    protected function operation(InputInterface $input): array
    {
        return $this->intake->capacity();
    }
}

