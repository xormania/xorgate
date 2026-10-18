<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Bounded JSON input and per-document normalization state shared by the JSON report adapters. One instance
 * belongs to one parse, so shared adapter services never retain a previous report.
 */
#[Exclude]
final class JsonDocument
{
    private const ANSI = '/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[@-Z\\\\-_])/';

    public const ANNOTATION_KINDS = ['failed_attempt', 'test_duration', 'jev_assessment', 'global_error', 'producer_status'];
    private const TARGET = ['spec_id' => null, 'test_id' => null, 'project' => null, 'retry' => null, 'file' => null, 'line' => null, 'title' => null];

    private array $limitations = [];
    private array $annotations = [];
    private bool $partial = false;
    private int $textBytes = 0;

    /** @param ?string $rootDir the producer's root directory, used only to relativize paths; never stored */
    public function __construct(private readonly ReportParseLimits $limits, private readonly ?string $rootDir = null)
    {
    }

    public static function parsePath(ReportAdapterInterface $adapter, string $path, ?ReportParseLimits $limits): ParsedReport
    {
        // The caller supplies a retained local file, never a URL or a stream-wrapper address.
        if (str_contains($path, '://') || !is_file($path)) {
            throw new ReportParseException('missing', 'report_missing');
        }
        $stream = @fopen($path, 'rb');
        if (false === $stream) {
            throw new ReportParseException('missing', 'report_missing');
        }
        try {
            return $adapter->parseStream($stream, $limits);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Verify the caller's stream like the XML adapter does, then read it once within maxBytes.
     *
     * @param resource $stream
     */
    public static function assertStream(mixed $stream, ReportParseLimits $limits): int
    {
        if (!is_resource($stream) || 'stream' !== get_resource_type($stream) || 0 !== ftell($stream)) {
            throw new \InvalidArgumentException('Report parsing requires a verified stream positioned at the start.');
        }
        $size = fstat($stream)['size'] ?? null;
        if (null === $size || $size > $limits->maxBytes) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }

        return $size;
    }

    /**
     * @param resource $stream
     *
     * @return array<array-key, mixed> the decoded top-level object
     */
    public static function decode(mixed $stream, ReportParseLimits $limits): array
    {
        $size = self::assertStream($stream, $limits);
        if (0 === $size) {
            throw new ReportParseException('empty', 'report_empty', 'JSON');
        }
        $text = stream_get_contents($stream, $limits->maxBytes + 1);
        if (false === $text) {
            throw new ReportParseException('missing', 'report_missing');
        }
        if (strlen($text) > $limits->maxBytes) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if ('' === trim($text, " \t\r\n")) {
            throw new ReportParseException('empty', 'report_empty', 'JSON');
        }
        // Every decoded container costs memory before any bound below applies; brackets inside strings make
        // this an upper estimate, so a report near the limit is refused rather than exhausting the worker.
        if (substr_count($text, '{') + substr_count($text, '[') > $limits->maxNodes) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        try {
            $value = json_decode($text, true, max(1, $limits->maxDepth), JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            throw JSON_ERROR_DEPTH === $exception->getCode()
                ? new ReportParseException('oversized', 'report_limit_exceeded')
                : new ReportParseException('malformed', 'report_malformed', 'JSON');
        }
        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new ReportParseException('unsupported', 'report_unsupported', 'JSON');
        }

        return $value;
    }

    public static function unsupported(): ReportParseException
    {
        return new ReportParseException('unsupported', 'report_unsupported', 'JSON');
    }

    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ([] === $value || !array_is_list($value));
    }

    public static function stripAnsi(string $text): string
    {
        return preg_replace(self::ANSI, '', $text) ?? $text;
    }

    /** A list value, or an empty list when the native field is absent or not a list. */
    public static function listOf(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** A bounded native string; other JSON types are not coerced. */
    public function text(mixed $value, bool &$truncated, ?int $maximum = null, string $limitation = 'native_fields_truncated'): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $maximum = min($maximum ?? $this->limits->maxFieldBytes, $this->limits->maxFieldBytes);
        $result = mb_strcut($value, 0, $maximum, 'UTF-8');
        if (strlen($result) < strlen($value)) {
            $truncated = true;
            $this->limitation($limitation, true);
        }
        $this->reserve(strlen($result));

        return $result;
    }

    /** Diagnostic text: terminal escapes removed, the producer root removed, then bounded. */
    public function message(mixed $value, bool &$truncated): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return $this->text($this->withoutRoot(self::stripAnsi($value)), $truncated, null, 'diagnostics_truncated');
    }

    /** A path relative to the producer root; an absolute path outside it is not retained. */
    public function path(mixed $value, bool &$truncated): ?string
    {
        if (!is_string($value) || '' === $value) {
            return is_string($value) ? '' : null;
        }
        $path = $value;
        $root = $this->root();
        if (null !== $root) {
            foreach (['/', '\\'] as $separator) {
                if (str_starts_with($path, $root.$separator)) {
                    $path = substr($path, strlen($root) + 1);
                    break;
                }
            }
        }
        if (self::isAbsolute($path)) {
            $this->limitation('producer_paths_not_retained', false);

            return null;
        }

        return $this->text($path, $truncated);
    }

    /** @return array{file: ?string, line: ?int, column: ?int}|null */
    public function location(mixed $value, bool &$truncated): ?array
    {
        if (!self::isObject($value)) {
            return null;
        }

        return ['file' => $this->path($value['file'] ?? null, $truncated), 'line' => $this->integer($value['line'] ?? null), 'column' => $this->integer($value['column'] ?? null)];
    }

    public function integer(mixed $value, int $minimum = 0): ?int
    {
        if (null === $value) {
            return null;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum) {
            $this->limitation('invalid_numeric_metadata', true);

            return null;
        }

        return $value;
    }

    /** Native milliseconds rounded like the producer's summary; unknown stays null, never 0. */
    public function milliseconds(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1e15) {
            $this->limitation('invalid_numeric_metadata', true);

            return null;
        }

        return (int) round($value);
    }

    public function number(mixed $value): int|float|null
    {
        if (null === $value) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            $this->limitation('invalid_numeric_metadata', true);

            return null;
        }

        return $value;
    }

    public function timestamp(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 64 || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $this->limitation('invalid_numeric_metadata', true);

            return null;
        }
        $this->reserve(strlen($value));

        return $value;
    }

    /** A derived reference to native evidence; intake links it to an attempt or case without counting it again. */
    public function annotate(string $kind, array $target, array $data): void
    {
        if (!in_array($kind, self::ANNOTATION_KINDS, true)) {
            throw new \InvalidArgumentException('Unknown annotation kind.');
        }
        if (count($this->annotations) >= $this->limits->maxCases * 8) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $this->annotations[] = [
            'annotation_key' => 'root/annotation/'.count($this->annotations),
            'kind' => $kind,
            'target' => array_replace(self::TARGET, array_intersect_key($target, self::TARGET)),
            'data' => $data,
        ];
    }

    /** The report of an annotation import: no cases, suites or attempts of its own. */
    public function annotationReport(array $summary, array $parser): ParsedReport
    {
        $counts = ['annotations' => count($this->annotations)] + array_fill_keys(self::ANNOTATION_KINDS, 0);
        foreach ($this->annotations as $annotation) {
            ++$counts[$annotation['kind']];
        }
        $summary = ['valid_document' => true] + $summary + ['retry_history' => 'derived', 'annotation_counts' => $counts];

        return new ParsedReport(
            $this->partial ? 'partial' : ([] === $this->annotations ? 'empty' : 'parsed'),
            [], [], $summary, $this->limitations(), $parser, [], $this->annotations,
        );
    }

    /** A bounded map of short native strings (runtime versions and similar). */
    public function stringMap(mixed $value, int $maximumEntries = 16): ?array
    {
        if (!self::isObject($value)) {
            return null;
        }
        $map = [];
        $truncated = false;
        foreach ($value as $key => $item) {
            if (count($map) >= $maximumEntries) {
                $this->limitation('native_fields_truncated', true);
                break;
            }
            $name = $this->text((string) $key, $truncated, 64);
            $map[$name] = match (true) {
                is_string($item) => $this->text($item, $truncated, 256),
                is_int($item) || is_float($item) => $this->number($item),
                default => null,
            };
        }

        return $map;
    }

    /** @param list<string> $vocabulary */
    public function vocabulary(mixed $value, array $vocabulary): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_string($value) && in_array($value, $vocabulary, true)) {
            return $value;
        }
        $this->limitation('native_status_unrecognized', true);

        return 'unknown';
    }

    /** @return list<string> */
    public function stringList(mixed $value, int $maximumEntries = 32, int $maximumBytes = 256): array
    {
        $list = [];
        $truncated = false;
        $items = self::listOf($value);
        foreach (array_slice($items, 0, $maximumEntries) as $item) {
            if (null !== ($text = $this->text($item, $truncated, $maximumBytes))) {
                $list[] = $text;
            }
        }
        if (count($items) > $maximumEntries) {
            $this->limitation('native_fields_truncated', true);
        }

        return $list;
    }

    public function limitation(string $code, bool $partial): void
    {
        $this->partial = $this->partial || $partial;
        if (!isset($this->limitations[$code]) && count($this->limitations) >= $this->limits->maxLimitations) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
        $this->limitations[$code] = true;
    }

    public function reserve(int $bytes): void
    {
        $this->textBytes += $bytes;
        if ($this->textBytes > $this->limits->maxTextBytes) {
            throw new ReportParseException('oversized', 'report_limit_exceeded');
        }
    }

    public function isPartial(): bool
    {
        return $this->partial;
    }

    /** @return list<string> */
    public function limitations(): array
    {
        return array_keys($this->limitations);
    }

    public function limits(): ReportParseLimits
    {
        return $this->limits;
    }

    private function root(): ?string
    {
        if (null === $this->rootDir) {
            return null;
        }
        $root = rtrim($this->rootDir, '/\\');

        return '' === $root ? null : $root;
    }

    private function withoutRoot(string $text): string
    {
        $root = $this->root();

        return null === $root ? $text : str_replace([$root.'/', $root.'\\', $root], ['', '', '.'], $text);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || 1 === preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, 'file:');
    }
}
