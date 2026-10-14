<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'xorgate:report:submit', description: 'Preserve report bytes or an owned artifact handle and queue durable asynchronous normalization.')]
final class ReportSubmitCommand extends ArtifactCommand
{
    protected function configure(): void
    {
        $this->configureScope();
        $this
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'JSON metadata with request_id and known source context, maximum 64 KiB. --input=- selects standard input.', '-')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Local report file, or --file=- to stream bytes through standard input. Do not select stdin for both inputs.')
            ->addOption('artifact-id', null, InputOption::VALUE_REQUIRED, 'Existing verified artifact UUID owned by this project/execution, instead of --file.');
    }

    protected function operation(InputInterface $input): array
    {
        $file = $input->getOption('file');
        $artifact = $input->getOption('artifact-id');
        if ((null === $file) === (null === $artifact)) {
            throw new \InvalidArgumentException('Supply exactly one of file or artifact-id.');
        }
        if ('-' === $file && '-' === $input->getOption('input')) {
            throw new \InvalidArgumentException('Metadata and report bytes cannot both use standard input. Supply a metadata file with --input.');
        }
        $metadata = $this->metadata($input);
        $project = $input->getArgument('project-id');
        $execution = $input->getArgument('execution-id');
        if (null !== $artifact) {
            return $this->intake->submitArtifact($project, $execution, $metadata, $artifact);
        }
        $stream = $this->openInput($file);
        try {
            return $this->intake->submit($project, $execution, $metadata, $stream);
        } finally {
            fclose($stream);
        }
    }
}
