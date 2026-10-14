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
                self::checkErrors($inputShape, $stream, $limits);
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

    /** @param resource $stream */
    private static function checkErrors(string $inputShape, mixed $stream, ReportParseLimits $limits): void
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $outcome = null;
        $endPosition = null;
        $ambiguousEnd = false;
        $definiteMalformed = false;
        foreach ($errors as $error) {
            if ($error->level < LIBXML_ERR_ERROR) {
                continue;
            }
            // Push-parser code5 means unfinished input at EOF, but also extra epilog
            // content before EOF. Reader lookahead can hide the root closing event,
            // so compare numeric source coordinates rather than observed node state.
            if (5 === $error->code && null === $endPosition) {
                $endPosition = self::endPosition($stream, $limits);
            }
            $ambiguous = 5 === $error->code && (null === $endPosition || $error->line < 1 || $error->column < 1);
            $ambiguousEnd = $ambiguousEnd || $ambiguous;
            $candidate = match ($error->code) {
                4 => 'declaration_only' === $inputShape ? 'truncated' : 'malformed',
                5 => [$error->line, $error->column] === $endPosition ? 'truncated' : 'malformed',
                34, 37, 40, 45, 47, 63, 77 => 'truncated',
                default => 'malformed',
            };
            $definiteMalformed = $definiteMalformed || ('malformed' === $candidate && !$ambiguous);
            if (null === $outcome || 'malformed' === $candidate) {
                $outcome = $candidate;
            }
        }
        if (null !== $outcome) {
            throw new ReportParseException($outcome, $ambiguousEnd && !$definiteMalformed ? 'report_xml_end_ambiguous' : 'report_'.$outcome);
        }
    }

    /** @param resource $stream */
    private static function endPosition(mixed $stream, ReportParseLimits $limits): ?array
    {
        $position = ftell($stream);
        try {
            if (!rewind($stream)) {
                return null;
            }
            // Only invalid code5 input needs this bounded coordinate check. No second
            // XML tree/parser is built, and no report content enters the diagnostic.
            $diagnosticMaximum = min($limits->maxBytes, 8_388_608);
            $text = stream_get_contents($stream, $diagnosticMaximum + 1);
            if (false === $text || strlen($text) > $diagnosticMaximum) {
                return null;
            }
            $encoding = 'UTF-8';
            if (str_starts_with($text, "\xFF\xFE\x00\x00") || str_starts_with($text, "\x00\x00\xFE\xFF")) {
                return null;
            }
            foreach (["\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE', "\xEF\xBB\xBF" => 'UTF-8'] as $bom => $detected) {
                if (str_starts_with($text, $bom)) {
                    $text = substr($text, strlen($bom));
                    $encoding = $detected;
                    break;
                }
            }
            if (str_starts_with($text, "<\x00?\x00")) {
                $encoding = 'UTF-16LE';
            } elseif (str_starts_with($text, "\x00<\x00?")) {
                $encoding = 'UTF-16BE';
            }
            if ('UTF-8' === $encoding && 1 === preg_match('/^<\?xml\s[^?]{0,512}\bencoding\s*=\s*(["\'])([A-Za-z][A-Za-z0-9._-]{0,39})\1/', substr($text, 0, 512), $declaration)) {
                if (!in_array(strtoupper($declaration[2]), ['UTF-8', 'UTF8', 'ASCII', 'US-ASCII'], true)) {
                    return null;
                }
            }
            try {
                if (!mb_check_encoding($text, $encoding)) {
                    return null;
                }
                $text = mb_convert_encoding($text, 'UTF-8', $encoding);
            } catch (\ValueError) {
                // An encoding unsupported by mbstring cannot support an EOF assertion.
                return null;
            }
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $lastBreak = strrpos($text, "\n");

            return [substr_count($text, "\n") + 1, mb_strlen(false === $lastBreak ? $text : substr($text, $lastBreak + 1), 'UTF-8') + 1];
        } finally {
            if (false !== $position) {
                fseek($stream, $position);
            }
        }
    }
}
