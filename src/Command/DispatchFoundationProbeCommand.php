<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\ProbeCapacityException;
use App\Service\FoundationProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'xorgate:probe:dispatch', description: 'Queue a durable asynchronous foundation probe and return its record as JSON.')]
final class DispatchFoundationProbeCommand extends Command
{
    public function __construct(private readonly FoundationProbe $probes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('probe-id', InputArgument::OPTIONAL, 'Optional UUID; repeating it returns the existing probe.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $probe = $this->probes->dispatch($input->getArgument('probe-id'));
        } catch (ProbeCapacityException $exception) {
            $output->writeln(json_encode($exception->response(), JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $output->writeln(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        $output->writeln(json_encode($probe, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
