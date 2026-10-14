<?php

declare(strict_types=1);

namespace App\Report;

/** Bounded, non-validating JUnit subset; original native extensions remain in the artifact. */
final class JUnitAdapter implements ReportAdapterInterface
{
    public function format(): string
    {
        return 'junit';
    }

    public function version(): string
    {
        return '1';
    }

    public function parse(string $path, ?ReportParseLimits $limits = null): ParsedReport
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
            return $this->parseStream($stream, $limits);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function parseStream(mixed $stream, ?ReportParseLimits $limits = null): ParsedReport
    {
        if (!is_resource($stream) || 'stream' !== get_resource_type($stream) || 0 !== ftell($stream)) {
            throw new \InvalidArgumentException('Report parsing requires a verified stream positioned at the start.');
        }
        $limits ??= new ReportParseLimits();
        $reader = null;
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $size = fstat($stream)['size'] ?? null;
            if (null === $size || $size > $limits->maxBytes) {
                throw new ReportParseException('oversized', 'report_limit_exceeded');
            }
            if (0 === $size) {
                throw new ReportParseException('empty', 'report_empty');
            }
            $inputShape = self::inputShape($stream, $limits);
            if ('empty' === $inputShape) {
                throw new ReportParseException('empty', 'report_empty');
            }

            $flags = LIBXML_NONET | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0);
            $reader = \XMLReader::fromStream($stream, null, $flags);
            foreach ([\XMLReader::LOADDTD, \XMLReader::DEFAULTATTRS, \XMLReader::VALIDATE, \XMLReader::SUBST_ENTITIES] as $property) {
                $reader->setParserProperty($property, false);
            }
            $state = new JUnitParseState($limits);
            $nodes = 0;

            while (true) {
                $read = $reader->read();
                self::checkErrors($inputShape);
                if (ftell($stream) > $limits->maxBytes) {
                    throw new ReportParseException('oversized', 'report_limit_exceeded');
                }
                if (!$read) {
                    break;
                }
                if (++$nodes > $limits->maxNodes || $reader->depth > $limits->maxDepth) {
                    throw new ReportParseException('oversized', 'report_limit_exceeded');
                }
                if (in_array($reader->nodeType, [\XMLReader::DOC_TYPE, \XMLReader::ENTITY, \XMLReader::ENTITY_REF], true)) {
                    throw new ReportParseException('unsupported', 'report_unsafe_xml');
                }
                $state->consume($reader);
            }

            return $state->finish(['name' => $this->format(), 'version' => $this->version(), 'libxml_version' => LIBXML_DOTTED_VERSION]);
        } finally {
            $reader?->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    /**
     * libxml DOCUMENT_EMPTY also describes non-XML text. Inspect bounded bytes on the
     * same verified descriptor to distinguish whitespace from garbage or a declaration
     * without a document element; restore the start before XMLReader consumes it.
     *
     * @param resource $stream
     */
    private static function inputShape(mixed $stream, ReportParseLimits $limits): string
    {
        $prefix = '';
        $afterDeclaration = false;
        $firstChunk = true;
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8_192);
                if (false === $chunk) {
                    throw new ReportParseException('missing', 'report_missing');
                }
                $bytes += strlen($chunk);
                if ($bytes > $limits->maxBytes) {
                    throw new ReportParseException('oversized', 'report_limit_exceeded');
                }
                if ($firstChunk) {
                    $firstChunk = false;
                    if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
                        $chunk = substr($chunk, 3);
                    }
                }
                $prefix .= $chunk;
                if ($afterDeclaration) {
                    if ('' !== trim($prefix, " \t\r\n")) {
                        return 'other';
                    }
                    $prefix = '';

                    continue;
                }
                $prefix = ltrim($prefix, " \t\r\n");
                if ('' === $prefix) {
                    continue;
                }
                if (1 !== preg_match('/^<\?xml(?:\s|\?>)/', $prefix)) {
                    return 'other';
                }
                $end = strpos($prefix, '?>');
                if (false === $end) {
                    if (strlen($prefix) > 4_096) {
                        return 'other';
                    }

                    continue;
                }
                $afterDeclaration = true;
                $prefix = substr($prefix, $end + 2);
                if ('' !== trim($prefix, " \t\r\n")) {
                    return 'other';
                }
                $prefix = '';
            }

            return $afterDeclaration ? 'declaration_only' : 'empty';
        } finally {
            if (!rewind($stream)) {
                throw new \InvalidArgumentException('Report parsing requires a seekable verified stream.');
            }
        }
    }

    private static function checkErrors(string $inputShape): void
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $outcome = null;
        foreach ($errors as $error) {
            if ($error->level < LIBXML_ERR_ERROR) {
                continue;
            }
            // libxml's unfinished token/tag codes, not diagnostic text containing report data.
            $candidate = 4 === $error->code
                ? ('declaration_only' === $inputShape ? 'truncated' : 'malformed')
                : (in_array($error->code, [34, 37, 40, 45, 47, 63, 77], true) ? 'truncated' : 'malformed');
            if (null === $outcome || 'malformed' === $candidate) {
                $outcome = $candidate;
            }
        }
        if (null !== $outcome) {
            throw new ReportParseException($outcome, 'report_'.$outcome);
        }
    }
}
