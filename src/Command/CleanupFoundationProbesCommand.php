<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FoundationProbeLimits;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'xorgate:probe:cleanup', description: 'Expire old completed probe artifacts and prune old metadata in a bounded batch.')]
final class CleanupFoundationProbesCommand extends Command
{
    public function __construct(private readonly FoundationProbeLimits $limits)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->limits->cleanup();
        } catch (\Throwable) {
            $output->writeln(json_encode(['error' => 'probe_retention_unavailable'], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
