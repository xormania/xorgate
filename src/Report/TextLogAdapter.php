<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Any text log (a server log, a step log). It has no test cases: the retained bytes are read through bounded
 * windows by intake, and this adapter only measures them, in fixed blocks, without holding the log in memory.
 */
final class TextLogAdapter implements ReportAdapterInterface
{
    private const BLOCK = 65_536;

    public function format(): string
    {
        return 'text-log';
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
        JsonDocument::assertStream($stream, $limits);
        $bytes = 0;
        $newlines = 0;
        $crlf = 0;
        $line = 0;
        $longest = 0;
        $utf8 = true;
        $carry = '';
        $last = '';
        while (!feof($stream)) {
            $block = fread($stream, self::BLOCK);
            if (false === $block) {
                throw new ReportParseException('missing', 'report_missing');
            }
            if ('' === $block) {
                continue;
            }
            $bytes += strlen($block);
            if ($bytes > $limits->maxBytes) {
                throw new ReportParseException('oversized', 'report_limit_exceeded');
            }
            $newlines += substr_count($block, "\n");
            $crlf += substr_count($block, "\r\n") + ("\r" === $last && "\n" === $block[0] ? 1 : 0);
            // Line lengths exclude the terminator, a CR before LF included.
            $offset = 0;
            while (false !== ($position = strpos($block, "\n", $offset))) {
                $length = $line + $position - $offset;
                if ($length > 0 && ($position > 0 ? "\r" === $block[$position - 1] : "\r" === $last)) {
                    --$length;
                }
                $longest = max($longest, $length);
                $line = 0;
                $offset = $position + 1;
            }
            $line += strlen($block) - $offset;
            $last = $block[strlen($block) - 1];
            if ($utf8) {
                [$complete, $carry] = self::splitIncomplete($carry.$block);
                $utf8 = mb_check_encoding($complete, 'UTF-8');
            }
        }
        $longest = max($longest, $line);
        $utf8 = $utf8 && '' === $carry;
        $lf = $newlines - $crlf;

        return new ParsedReport(0 === $bytes ? 'empty' : 'parsed', [], [], [
            'valid_document' => true,
            'bytes' => $bytes,
            'lines' => $newlines + ($bytes > 0 && "\n" !== $last ? 1 : 0),
            'trailing_newline' => "\n" === $last,
            'utf8_valid' => $utf8,
            'longest_line_bytes' => $longest,
            'line_ending' => 0 === $newlines ? 'none' : (0 === $crlf ? 'lf' : (0 === $lf ? 'crlf' : 'mixed')),
            'retry_history' => 'not_applicable',
        ], [], ['name' => $this->format(), 'version' => $this->version()]);
    }

    /**
     * Holds back a trailing UTF-8 sequence that a block boundary may have cut, so it is checked with the next block.
     *
     * @return array{string, string}
     */
    private static function splitIncomplete(string $bytes): array
    {
        $length = strlen($bytes);
        for ($back = 1; $back <= min(3, $length); ++$back) {
            $byte = ord($bytes[$length - $back]);
            if ($byte < 0x80) {
                break;
            }
            if ($byte >= 0xC0) {
                $needed = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $needed > $back ? [substr($bytes, 0, $length - $back), substr($bytes, $length - $back)] : [$bytes, ''];
            }
        }

        return [$bytes, ''];
    }
}
