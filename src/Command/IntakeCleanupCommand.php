<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'xorgate:intake:cleanup', description: 'Run one bounded retention/recovery sweep, preserving queued and running report work.')]
final class IntakeCleanupCommand extends ArtifactCommand
{
    protected function operation(InputInterface $input): array
    {
        return $this->intake->cleanup();
    }
}

