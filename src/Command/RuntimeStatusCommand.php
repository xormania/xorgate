<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FoundationStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'xorgate:runtime:status', description: 'Inspect database, artifact and queue storage readiness as JSON.')]
final class RuntimeStatusCommand extends Command
{
    public function __construct(private readonly FoundationStatus $status)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'JSON is the default output format.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->status->snapshot();
        $output->writeln(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $snapshot['ready'] ? Command::SUCCESS : Command::FAILURE;
    }
}
