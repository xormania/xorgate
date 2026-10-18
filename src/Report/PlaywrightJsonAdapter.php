<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Playwright's JSON reporter output (results.json). Native vocabulary is kept: a test's outcome is
 * expected/unexpected/flaky/skipped and every result is an attempt with its retry index, so a run that passed
 * after a failed first attempt keeps that history. Original native fields remain in the retained artifact.
 */
final class PlaywrightJsonAdapter implements ReportAdapterInterface
{
    public const OUTCOMES = ['expected', 'unexpected', 'flaky', 'skipped'];
    public const STATUSES = ['passed', 'failed', 'timedOut', 'skipped', 'interrupted'];

    public function format(): string
    {
        return 'playwright-json';
    }

    public function version(): string
    {
        return '1';
    }

    public function parse(string $path, ?ReportParseLimits $limits = null): ParsedReport
    {
        return JsonDocument::parsePath($this, $path, $limits);
    }

    public function parseStream(mixed $stream, ?ReportParseLimits $limits = null): ParsedReport
    {
        $limits ??= new ReportParseLimits();
        $report = JsonDocument::decode($stream, $limits);
        if (!JsonDocument::isObject($report['config'] ?? null) || !is_array($report['suites'] ?? null) || !array_is_list($report['suites'])) {
            throw JsonDocument::unsupported();
        }
        $config = $report['config'];
        $rootDir = is_string($config['rootDir'] ?? null) && '' !== $config['rootDir'] ? $config['rootDir'] : null;

        return (new PlaywrightJsonState(new JsonDocument($limits, $rootDir)))->read($report, $config, null !== $rootDir, ['name' => $this->format(), 'version' => $this->version()]);
    }
}
