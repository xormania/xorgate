<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Report\JUnitAdapter;
use App\Report\ParsedReport;
use App\Report\ReportParseException;
use App\Report\ReportParseLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Inline XML below is synthesized boundary evidence, not a producer-run fixture. */
final class JUnitAdapterTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function testNestedSuitesRetainDistinctDuplicateNamesAndNativeOutcomes(): void
    {
        $xml = <<<'XML'
<testsuites tests="4" failures="1" errors="1" skipped="1">
 <testsuite name="Outer" tests="4" failures="1" errors="1" skipped="1">
  <testsuite name="Inner" tests="4" failures="1" errors="1" skipped="1">
   <testcase name="same" classname="Example" time="0"/>
   <testcase name="same" classname="Example"><failure type="Assertion" message="a &amp; b"><![CDATA[x < y]]></failure></testcase>
   <testcase name="broken"><error type="Runtime">setup failed</error></testcase>
   <testcase name="later"><skipped message="disabled"/></testcase>
  </testsuite>
 </testsuite>
</testsuites>
XML;
        $report = $this->parse($xml);
        self::assertSame('parsed', $report->outcome);
        self::assertSame(['passed', 'failure', 'error', 'skipped'], array_column($report->cases, 'native_outcome'));
        self::assertNotSame($report->cases[0]['case_key'], $report->cases[1]['case_key']);
        self::assertSame($report->cases, $this->parse($xml)->cases, 'Redelivery must derive the same observation keys.');
        self::assertSame(0.0, $report->cases[0]['duration_seconds']);
        self::assertNull($report->cases[1]['duration_seconds']);
        self::assertSame('a & b', $report->cases[1]['diagnostics'][0]['message']);
        self::assertSame('x < y', $report->cases[1]['diagnostics'][0]['text']);
        self::assertSame(['tests' => 4, 'passed' => 1, 'failures' => 1, 'errors' => 1, 'skipped' => 1, 'unknown' => 0], $report->summary['observed_counts']);
        self::assertSame($report->summary['observed_counts'], $report->suites[0]['observed_counts']);
        self::assertSame($report->suites[0]['suite_key'], $report->suites[1]['parent_key']);
        self::assertSame('not_inferred', $report->summary['retry_history']);
        self::assertSame(['name' => 'junit', 'version' => '1', 'libxml_version' => LIBXML_DOTTED_VERSION], $report->parser);
    }

    public function testValidEmptyDocumentAndDeclaredPopulationMismatchRemainDistinct(): void
    {
        $empty = $this->parse('<testsuite name="No observed cases" tests="0"/>');
        self::assertSame('empty', $empty->outcome);
        self::assertTrue($empty->summary['valid_document']);
        self::assertSame([], $empty->cases);
        self::assertSame(0, $empty->summary['observed_counts']['tests']);

        $partial = $this->parse('<testsuite tests="3"><testcase name="only one observed"/></testsuite>');
        self::assertSame('partial', $partial->outcome);
        self::assertSame(3, $partial->summary['declared_counts']['tests']);
        self::assertSame(1, $partial->summary['observed_counts']['tests']);
        self::assertContains('declared_counts_mismatch', $partial->limitations);

        $unknown = $this->parse('<testsuites><testcase name="one"/></testsuites>');
        self::assertNull($unknown->summary['declared_counts']['tests']);
        self::assertSame('parsed', $unknown->outcome);
    }

    public function testUnsupportedOutcomeExtensionsCannotBecomePassingEvidence(): void
    {
        $report = $this->parse('<testsuite><testcase name="retry"><flakyFailure>failed before recovery</flakyFailure></testcase><testcase name="ambiguous"><failure/><skipped/></testcase></testsuite>');
        self::assertSame('partial', $report->outcome);
        self::assertSame(['unknown', 'unknown'], array_column($report->cases, 'native_outcome'));
        self::assertContains('native_extensions_not_normalized', $report->limitations);
        self::assertContains('case_outcome_ambiguous', $report->limitations);
        self::assertSame(0, $report->summary['observed_counts']['passed']);

        $metadata = $this->parse('<testsuite><properties><property name="not normalized" value="preserved in original"/></properties><testcase name="normal"><system-out>native output</system-out></testcase></testsuite>');
        self::assertSame('parsed', $metadata->outcome);
        self::assertContains('native_metadata_not_normalized', $metadata->limitations);
    }

    public function testNativeTextIsBoundedWithoutDiscardingItsOutcome(): void
    {
        $report = $this->parse('<testsuite><testcase name="long"><failure message="'.str_repeat('é', 20).'">'.str_repeat('é', 20).'</failure></testcase></testsuite>', new ReportParseLimits(maxFieldBytes: 9));
        self::assertSame('partial', $report->outcome);
        self::assertSame('failure', $report->cases[0]['native_outcome']);
        self::assertTrue($report->cases[0]['truncated']);
        self::assertTrue($report->cases[0]['diagnostics'][0]['truncated']);
        self::assertLessThanOrEqual(9, strlen($report->cases[0]['diagnostics'][0]['message']));
        self::assertLessThanOrEqual(9, strlen($report->cases[0]['diagnostics'][0]['text']));
        self::assertTrue(mb_check_encoding($report->cases[0]['diagnostics'][0]['text'], 'UTF-8'));
    }

    public function testUnsupportedNativeOutcomeAttributesCannotSilentlyBecomePassed(): void
    {
        $report = $this->parse('<testsuite><testcase name="status" status="failed"/><testcase name="result" result="error"/><testcase name="outcome" outcome="skipped"/><testcase name="ordinary"/></testsuite>');
        self::assertSame('partial', $report->outcome);
        self::assertSame(['unknown', 'unknown', 'unknown', 'passed'], array_column($report->cases, 'native_outcome'));
        self::assertContains('native_outcome_attributes_not_normalized', $report->limitations);
        self::assertSame(3, $report->summary['observed_counts']['unknown']);
        self::assertSame(1, $report->summary['observed_counts']['passed']);
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidAndUnsafeDocumentsHaveSafeDistinctDiagnostics(string $xml, string $outcome, string $errorCode): void
    {
        try {
            $this->parse($xml);
            self::fail('This document must not yield normalized observations.');
        } catch (ReportParseException $exception) {
            self::assertSame($outcome, $exception->outcome);
            self::assertSame($errorCode, $exception->errorCode);
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString(sys_get_temp_dir(), $exception->getMessage());
        }
    }

    public static function invalidDocuments(): iterable
    {
        yield 'zero bytes' => ['', 'empty', 'report_empty'];
        yield 'whitespace only' => ['  ', 'empty', 'report_empty'];
        yield 'non-XML garbage' => ['not XML', 'malformed', 'report_malformed'];
        yield 'declaration without root' => ['<?xml version="1.0"?>', 'truncated', 'report_truncated'];
        yield 'declaration followed by garbage' => ['<?xml version="1.0"?>not XML', 'malformed', 'report_malformed'];
        yield 'unfinished suite' => ['<testsuite><testcase name="secret"/>', 'truncated', 'report_truncated'];
        yield 'mismatched tags' => ['<testsuite><testcase name="secret"></testsuite>', 'malformed', 'report_malformed'];
        yield 'unsupported root' => ['<report secret="untrusted"/>', 'unsupported', 'report_unsupported'];
        yield 'internal DTD' => ['<!DOCTYPE testsuite [<!ENTITY secret "not allowed">]><testsuite/>', 'unsupported', 'report_unsafe_xml'];
        yield 'external DTD' => ['<!DOCTYPE testsuite SYSTEM "http://127.0.0.1:9/secret"><testsuite/>', 'unsupported', 'report_unsafe_xml'];
        yield 'local external entity' => ['<!DOCTYPE testsuite [<!ENTITY secret SYSTEM "file:///etc/passwd">]><testsuite><testcase name="x"><failure>&secret;</failure></testcase></testsuite>', 'unsupported', 'report_unsafe_xml'];
    }

    #[DataProvider('limitedDocuments')]
    public function testResourceBoundsStopNormalization(string $xml, ReportParseLimits $limits): void
    {
        try {
            $this->parse($xml, $limits);
            self::fail('The parser must enforce its configured bound.');
        } catch (ReportParseException $exception) {
            self::assertSame('oversized', $exception->outcome);
            self::assertSame('report_limit_exceeded', $exception->errorCode);
        }
    }

    public static function limitedDocuments(): iterable
    {
        yield 'bytes' => ['<testsuite/>', new ReportParseLimits(maxBytes: 4)];
        yield 'cases' => ['<testsuite><testcase/><testcase/></testsuite>', new ReportParseLimits(maxCases: 1)];
        yield 'suites' => ['<testsuites><testsuite/><testsuite/></testsuites>', new ReportParseLimits(maxSuites: 1)];
        yield 'depth' => ['<testsuite><testsuite><testcase/></testsuite></testsuite>', new ReportParseLimits(maxDepth: 1)];
        yield 'nodes' => ['<testsuite><testcase/></testsuite>', new ReportParseLimits(maxNodes: 1)];
        yield 'normalized text' => ['<testsuite name="long"><testcase name="also long"/></testsuite>', new ReportParseLimits(maxTextBytes: 5)];
    }

    public function testMissingLocalInputAndLibxmlStateAreExplicit(): void
    {
        try {
            (new JUnitAdapter())->parse('/missing-xorgate-report.xml');
            self::fail('A missing retained artifact must not be an empty test suite.');
        } catch (ReportParseException $exception) {
            self::assertSame('missing', $exception->outcome);
        }
        $previous = libxml_use_internal_errors(false);
        try {
            $this->parse('<testsuite/>');
            self::assertFalse(libxml_use_internal_errors(), 'Parser must restore process-wide libxml error handling.');
            self::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function testVerifiedStreamIsParsedWithoutReopeningItsPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xorgate-junit-stream-');
        self::assertIsString($path);
        file_put_contents($path, '<testsuite><testcase name="original"/></testsuite>');
        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);
        // The verified descriptor keeps its original bytes when the pathname is replaced.
        unlink($path);
        file_put_contents($path, '<testsuite><testcase name="replacement"/></testsuite>');
        $this->paths[] = $path;
        try {
            $report = (new JUnitAdapter())->parseStream($stream);
            self::assertSame('original', $report->cases[0]['name']);
            self::assertIsResource($stream, 'The intake owner closes its verified descriptor.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testRealUxorNodeJUnitFixtureHasFifteenDirectCases(): void
    {
        $path = dirname(__DIR__).'/Fixtures/JUnit/uxor-node.xml';
        $provenance = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/JUnit/uxor-node.provenance.json'), true, 16, JSON_THROW_ON_ERROR);
        $report = (new JUnitAdapter())->parse($path);
        self::assertSame('parsed', $report->outcome);
        self::assertCount(15, $report->cases);
        self::assertSame([], $report->suites);
        self::assertSame(15, $report->summary['observed_counts']['passed']);
        self::assertNull($report->summary['declared_counts']['tests']);
        self::assertSame(0.001745, $report->cases[0]['duration_seconds']);
        self::assertSame('root/case/0', $report->cases[0]['case_key']);
        self::assertSame(15, count(array_unique(array_column($report->cases, 'case_key'))));
        self::assertSame($provenance['retainedReport']['sha256'], hash_file('sha256', $path));
        self::assertSame($provenance['retainedReport']['bytes'], filesize($path));
        self::assertSame('tools/tests/shard-plan.test.mjs', $report->cases[0]['file']);
    }

    public function testCapturedControlledPhpunitReportPreservesItsFourNativeOutcomes(): void
    {
        $path = dirname(__DIR__).'/Fixtures/JUnit/controlled-phpunit.xml';
        $provenance = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/JUnit/controlled-phpunit.provenance.json'), true, 16, JSON_THROW_ON_ERROR);
        $report = (new JUnitAdapter())->parse($path);
        self::assertSame('parsed', $report->outcome);
        self::assertCount(4, $report->cases);
        self::assertCount(1, $report->suites);
        self::assertSame(['passed', 'failure', 'error', 'skipped'], array_column($report->cases, 'native_outcome'));
        self::assertSame(4, count(array_unique(array_column($report->cases, 'case_key'))));
        self::assertSame(['tests' => 4, 'passed' => 1, 'failures' => 1, 'errors' => 1, 'skipped' => 1, 'unknown' => 0], $report->summary['observed_counts']);
        self::assertSame(['tests' => 4, 'failures' => 1, 'errors' => 1, 'skipped' => 1], $report->suites[0]['declared_counts']);
        self::assertSame($report->summary['observed_counts'], $report->suites[0]['observed_counts']);
        self::assertNull($report->summary['declared_counts']['tests']);
        self::assertSame([0.000185, 0.001212, 0.000181, 0.000113], array_column($report->cases, 'duration_seconds'));
        self::assertSame(0.001690, $report->suites[0]['duration_seconds']);
        self::assertEqualsWithDelta($provenance['observed']['sumTestcaseTimeSeconds'], array_sum(array_column($report->cases, 'duration_seconds')), 0.000000001);
        self::assertSame('PHPUnit\\Framework\\ExpectationFailedException', $report->cases[1]['diagnostics'][0]['type']);
        self::assertSame('RuntimeException', $report->cases[2]['diagnostics'][0]['type']);
        self::assertSame('tests/Fixtures/JUnit/ProducerCases.php', $report->cases[0]['file']);
        self::assertSame($provenance['retainedReport']['sha256'], hash_file('sha256', $path));
        self::assertSame($provenance['retainedReport']['bytes'], filesize($path));
        self::assertSame('controlled-native-producer-report', $provenance['classification']);
        self::assertSame('13.4.2', $provenance['producer']['version']);
    }

    private function parse(string $xml, ?ReportParseLimits $limits = null): ParsedReport
    {
        $path = tempnam(sys_get_temp_dir(), 'xorgate-junit-');
        self::assertIsString($path);
        $this->paths[] = $path;
        file_put_contents($path, $xml);

        return (new JUnitAdapter())->parse($path, $limits);
    }
}
