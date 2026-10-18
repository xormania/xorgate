<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Report\ParsedReport;
use App\Report\ReportParseException;
use App\Report\ReportParseLimits;
use App\Report\TextLogAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Inline logs below are synthesized boundary evidence; controlled-server.log is a captured producer log. */
final class TextLogAdapterTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function testControlledServerLogIsMeasuredWithoutCases(): void
    {
        $path = __DIR__.'/../Fixtures/Playwright/controlled-server.log';
        if (!is_file($path)) {
            self::markTestSkipped('Controlled fixture is absent: tests/Fixtures/Playwright/controlled-server.log');
        }
        $report = (new TextLogAdapter())->parse($path);
        self::assertSame('parsed', $report->outcome);
        self::assertSame([], $report->cases);
        self::assertSame([], $report->attempts);
        self::assertSame(filesize($path), $report->summary['bytes']);
        self::assertSame(substr_count((string) file_get_contents($path), "\n"), $report->summary['lines']);
        self::assertTrue($report->summary['trailing_newline']);
        self::assertSame('lf', $report->summary['line_ending']);
        self::assertTrue($report->summary['utf8_valid']);
        self::assertSame('not_applicable', $report->summary['retry_history']);
        self::assertSame(['name' => 'text-log', 'version' => '1'], $report->parser);
    }

    #[DataProvider('logs')]
    public function testSyntheticLogShapes(string $text, int $lines, bool $trailing, bool $utf8, int $longest, string $ending): void
    {
        $summary = $this->parse($text)->summary;
        self::assertSame(strlen($text), $summary['bytes']);
        self::assertSame($lines, $summary['lines']);
        self::assertSame($trailing, $summary['trailing_newline']);
        self::assertSame($utf8, $summary['utf8_valid']);
        self::assertSame($longest, $summary['longest_line_bytes']);
        self::assertSame($ending, $summary['line_ending']);
    }

    public static function logs(): iterable
    {
        yield 'no terminator' => ['one line', 1, false, true, 8, 'none'];
        yield 'lf' => ["a\nbb\n", 2, true, true, 2, 'lf'];
        yield 'crlf' => ["a\r\nbb\r\nccc", 3, false, true, 3, 'crlf'];
        yield 'mixed' => ["a\r\nb\n", 2, true, true, 1, 'mixed'];
        yield 'CRLF split across 64 KiB blocks' => [str_repeat('a', 65_535)."\r\nb\r\n", 2, true, true, 65_535, 'crlf'];
        yield 'UTF-8 split across 64 KiB blocks' => [str_repeat('a', 65_535)."é\n", 1, true, true, 65_537, 'lf'];
        yield 'invalid UTF-8' => ["ok\n\xC3", 2, false, false, 2, 'lf'];
        yield 'truncated UTF-8 at end' => ["ok \xE2\x82", 1, false, false, 5, 'none'];
    }

    public function testZeroBytesIsEmptyAndLimitsApply(): void
    {
        $empty = $this->parse('');
        self::assertSame('empty', $empty->outcome);
        self::assertSame(0, $empty->summary['bytes']);
        self::assertSame(0, $empty->summary['lines']);
        try {
            $this->parse('more than four bytes', new ReportParseLimits(maxBytes: 4));
            self::fail('The configured byte bound must apply.');
        } catch (ReportParseException $exception) {
            self::assertSame('oversized', $exception->outcome);
            self::assertSame('report_limit_exceeded', $exception->errorCode);
        }
    }

    private function parse(string $text, ?ReportParseLimits $limits = null): ParsedReport
    {
        $path = tempnam(sys_get_temp_dir(), 'xorgate-log-');
        self::assertIsString($path);
        $this->paths[] = $path;
        file_put_contents($path, $text);

        return (new TextLogAdapter())->parse($path, $limits);
    }
}
