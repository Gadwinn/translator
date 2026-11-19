<?php

declare(strict_types=1);

namespace G\Translator\Io\Parser;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Source\SourceInterface;

/**
 * `ParserInterface` implementation for the strict subset of YAML
 * used by translation files.
 *
 * ## Supported subset
 *
 *   - Nested maps via indentation.
 *   - Keys — plain (`app.title`, no whitespace, `:` or `#`), or
 *     single- / double-quoted like values (`"Hello world": Bonjour`).
 *   - Scalar strings — unquoted (taken literally), single-quoted
 *     (no escapes; `''` represents a literal single quote), or
 *     double-quoted with the YAML escapes `\\` `\"` `\/` `\0` `\a`
 *     `\b` `\t` `\n` `\v` `\f` `\r` `\e` `\ ` `\xHH` `\uHHHH`
 *     `\UHHHHHHHH`.
 *   - Comments (`#`) on their own line or trailing after a value
 *     (a `#` inside a quoted string is part of the string).
 *   - The optional `---` document-start marker on the first
 *     non-blank, non-comment line.
 *
 * ## Deliberately rejected
 *
 *   - Multi-line scalars (`|`, `>`). Translation files do not need
 *     them and supporting them is a maintenance trap.
 *   - Anchors (`&`), aliases (`*`), tags (`!!`).
 *   - Flow style (`{key: value}`, `[a, b]`).
 *   - List items (`-`). The translation model is map-of-strings;
 *     list values have no canonical mapping.
 *   - Duplicate keys in the same map (forbidden by YAML).
 *   - Numeric / boolean scalar coercion. Every value is parsed as
 *     a string; YAML's implicit typing (`yes`, `true`, `42`, `null`,
 *     …) is intentionally NOT applied here.
 *
 * Any input outside this subset (including an unterminated quoted
 * string or an unknown escape) triggers `ParserException`.
 *
 * Indentation MUST use spaces (tabs are rejected to keep the
 * grammar unambiguous). The width of the indent for each new
 * nested level is whatever the first child of the parent map
 * declares, but MUST be strictly greater than the parent's indent.
 *
 * Like the JSON parsers, nested maps are flattened to dotted keys
 * at bag-construction time.
 */
final class YamlParser implements ParserInterface
{
    /**
     * @var non-empty-string Dot used to join nested keys.
     */
    private const string KEY_SEPARATOR = '.';

    /**
     * @var non-empty-string Document-start marker that may appear
     *      on the first content line; it is skipped.
     */
    private const string DOCUMENT_START = '---';

    /**
     * @var int Sentinel returned by `peekIndent()` when the cursor
     *      has already moved past the last line of the document.
     */
    private const int INDENT_END_OF_INPUT = -1;

    /**
     * @var array<array-key, string> Single-character escapes of
     *      double-quoted scalars (YAML 1.2 §5.7).
     */
    private const array SIMPLE_ESCAPES = [
        '\\' => '\\',
        '"'  => '"',
        '/'  => '/',
        ' '  => ' ',
        '0'  => "\0",
        'a'  => "\x07",
        'b'  => "\x08",
        't'  => "\t",
        'n'  => "\n",
        'v'  => "\x0B",
        'f'  => "\x0C",
        'r'  => "\r",
        'e'  => "\x1B",
    ];

    /**
     * @var array<string, int> Hexadecimal escapes and their digit count.
     */
    private const array HEX_ESCAPES = ['x' => 2, 'u' => 4, 'U' => 8];

    /**
     * @var int Highest Unicode code point.
     */
    private const int MAX_CODE_POINT = 0x10FFFF;

    /**
     * @var int First UTF-16 surrogate (not a character on its own).
     */
    private const int SURROGATE_FIRST = 0xD800;

    /**
     * @var int Last UTF-16 surrogate.
     */
    private const int SURROGATE_LAST = 0xDFFF;

    /**
     * @var int Length of the UTF-8 byte order mark.
     */
    private const int BOM_LENGTH = 3;

    public function parse(
        SourceInterface $source,
        string $locale,
        string $domain = 'messages',
    ): TranslationBagInterface {
        $bag = new TranslationBag($locale);
        $rawLines = $this->normalizeLines($source->read());
        if ($rawLines === []) {
            return $bag;
        }

        $cursor = 0;
        $tree = $this->parseMap($rawLines, $cursor, 0, $source->identifier());

        foreach ($this->flatten($tree, '', $source->identifier()) as $key => $value) {
            $bag->add(new TranslationEntry(id: $key, content: $value, domain: $domain));
        }

        return $bag;
    }

    public function format(): Format
    {
        return Format::YAML;
    }

    /**
     * @return list<string> Lines stripped of trailing whitespace,
     *         minus blanks, full-line comments, BOM, and the
     *         optional `---` document marker.
     */
    private function normalizeLines(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, self::BOM_LENGTH);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $rawLines = explode("\n", $content);

        $result = [];
        foreach ($rawLines as $line) {
            $stripped = rtrim($line);
            $trimmed = ltrim($stripped);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if ($result === [] && $trimmed === self::DOCUMENT_START) {
                continue;
            }
            $result[] = $stripped;
        }

        return $result;
    }

    /**
     * Recursively parses a map starting at `$lines[$cursor]`.
     *
     * @param list<string> $lines
     * @param non-empty-string $sourceIdentifier
     *
     * @return array<non-empty-string, mixed>
     */
    private function parseMap(array $lines, int &$cursor, int $expectedIndent, string $sourceIdentifier): array
    {
        $result = [];

        while ($cursor < count($lines)) {
            $line = $lines[$cursor];
            $indent = $this->indentOf($line, $sourceIdentifier);

            if ($indent < $expectedIndent) {
                return $result;
            }

            if ($indent > $expectedIndent) {
                throw new ParserException(sprintf(
                    'YAML source "%s": unexpected indent at line "%s".',
                    $sourceIdentifier,
                    $line,
                ));
            }

            [$key, $valuePart] = $this->extractKeyAndValue($line, $sourceIdentifier);
            $cursor++;

            if (array_key_exists($key, $result)) {
                throw new ParserException(sprintf(
                    'YAML source "%s": duplicate key "%s".',
                    $sourceIdentifier,
                    $key,
                ));
            }

            if ($valuePart === '') {
                $nextIndent = $this->peekIndent($lines, $cursor, $sourceIdentifier);
                if ($nextIndent > $expectedIndent) {
                    $result[$key] = $this->parseMap($lines, $cursor, $nextIndent, $sourceIdentifier);
                } else {
                    $result[$key] = '';
                }
                continue;
            }

            $result[$key] = $this->parseScalar($valuePart, $sourceIdentifier);
        }

        return $result;
    }

    /**
     * @param non-empty-string $sourceIdentifier
     */
    private function indentOf(string $line, string $sourceIdentifier): int
    {
        $length = strlen($line);
        $indent = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === ' ') {
                $indent++;
                continue;
            }
            if ($char === "\t") {
                throw new ParserException(sprintf(
                    'YAML source "%s": tabs are not allowed for indentation (line "%s").',
                    $sourceIdentifier,
                    $line,
                ));
            }
            break;
        }

        return $indent;
    }

    /**
     * @param list<string> $lines
     * @param non-empty-string $sourceIdentifier
     */
    private function peekIndent(array $lines, int $cursor, string $sourceIdentifier): int
    {
        if (!isset($lines[$cursor])) {
            return self::INDENT_END_OF_INPUT;
        }

        return $this->indentOf($lines[$cursor], $sourceIdentifier);
    }

    /**
     * @param non-empty-string $sourceIdentifier
     *
     * @return array{0: non-empty-string, 1: string} The key and the
     *         value part, trailing comment removed (empty for a
     *         nested map or an empty value).
     */
    private function extractKeyAndValue(string $line, string $sourceIdentifier): array
    {
        $trimmed = ltrim($line);

        // Reject flow style and list items up front.
        if (str_starts_with($trimmed, '-')) {
            throw new ParserException(sprintf(
                'YAML source "%s": list items ("-") are not supported (line "%s").',
                $sourceIdentifier,
                $line,
            ));
        }
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            throw new ParserException(sprintf(
                'YAML source "%s": flow style is not supported (line "%s").',
                $sourceIdentifier,
                $line,
            ));
        }

        if (str_starts_with($trimmed, "'") || str_starts_with($trimmed, '"')) {
            [$key, $end] = $this->readQuoted($trimmed, $sourceIdentifier);
            $rest = substr($trimmed, $end);
        } elseif (preg_match('/^([^\s:#]+)(.*)$/', $trimmed, $match) === 1) {
            $key = $match[1];
            $rest = $match[2];
        } else {
            $key = '';
            $rest = '';
        }

        // `key: rest` (rest may be empty for nested maps).
        if ($key === '' || preg_match('/^\s*:(?:\s+(.*))?$/', $rest, $match) !== 1) {
            throw new ParserException(sprintf(
                'YAML source "%s": expected "key: value", got "%s".',
                $sourceIdentifier,
                $line,
            ));
        }

        return [$key, $this->stripTrailingComment($match[1] ?? '', $sourceIdentifier)];
    }

    /**
     * @param non-empty-string $sourceIdentifier
     */
    private function parseScalar(string $value, string $sourceIdentifier): string
    {
        if (str_starts_with($value, '|') || str_starts_with($value, '>')) {
            throw new ParserException(sprintf(
                'YAML source "%s": multi-line scalars ("|", ">") are not supported.',
                $sourceIdentifier,
            ));
        }
        if (str_starts_with($value, '&') || str_starts_with($value, '*')) {
            throw new ParserException(sprintf(
                'YAML source "%s": anchors ("&") and aliases ("*") are not supported.',
                $sourceIdentifier,
            ));
        }
        if (str_starts_with($value, '!')) {
            throw new ParserException(sprintf(
                'YAML source "%s": tags ("!", "!!") are not supported.',
                $sourceIdentifier,
            ));
        }
        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            throw new ParserException(sprintf(
                'YAML source "%s": flow style ("{...}", "[...]") is not supported in value position.',
                $sourceIdentifier,
            ));
        }

        if (str_starts_with($value, "'") || str_starts_with($value, '"')) {
            // stripTrailingComment() already checked that nothing follows the closing quote.
            return $this->readQuoted($value, $sourceIdentifier)[0];
        }

        return $value;
    }

    /**
     * Removes a trailing comment from a value: after a quoted string,
     * only whitespace and a comment may follow; in a plain scalar, a
     * `#` starts a comment when it begins the value or follows
     * whitespace.
     *
     * @param non-empty-string $sourceIdentifier
     */
    private function stripTrailingComment(string $value, string $sourceIdentifier): string
    {
        if (str_starts_with($value, '#')) {
            return '';
        }

        if (str_starts_with($value, "'") || str_starts_with($value, '"')) {
            $end = $this->readQuoted($value, $sourceIdentifier)[1];
            $rest = substr($value, $end);
            if (preg_match('/^(?:\s+#.*)?\s*$/', $rest) !== 1) {
                throw new ParserException(sprintf(
                    'YAML source "%s": unexpected characters after a quoted string in "%s".',
                    $sourceIdentifier,
                    $value,
                ));
            }

            return substr($value, 0, $end);
        }

        if (preg_match('/^(.*?)\s+#/', $value, $match) === 1) {
            return rtrim($match[1]);
        }

        return $value;
    }

    /**
     * Reads the quoted string starting at the first character of `$text`.
     *
     * @param non-empty-string $sourceIdentifier
     *
     * @return array{0: string, 1: int} The unquoted value and the offset
     *         just after the closing quote.
     */
    private function readQuoted(string $text, string $sourceIdentifier): array
    {
        $quote = $text[0];
        $length = strlen($text);
        $value = '';

        for ($i = 1; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote === "'") {
                if ($char !== "'") {
                    $value .= $char;
                    continue;
                }
                if ($i + 1 < $length && $text[$i + 1] === "'") {
                    $value .= "'";
                    $i++;
                    continue;
                }

                return [$value, $i + 1];
            }

            if ($char === '"') {
                return [$value, $i + 1];
            }
            if ($char !== '\\') {
                $value .= $char;
                continue;
            }

            [$decoded, $consumed] = $this->decodeEscape($text, $i + 1, $sourceIdentifier);
            $value .= $decoded;
            $i += $consumed;
        }

        throw new ParserException(sprintf(
            'YAML source "%s": unterminated quoted string %s.',
            $sourceIdentifier,
            $text,
        ));
    }

    /**
     * Decodes the escape sequence whose letter is at `$offset`.
     *
     * @param non-empty-string $sourceIdentifier
     *
     * @return array{0: string, 1: int} The decoded text and the number
     *         of characters consumed after the backslash.
     */
    private function decodeEscape(string $text, int $offset, string $sourceIdentifier): array
    {
        $letter = $text[$offset] ?? '';

        if (isset(self::SIMPLE_ESCAPES[$letter])) {
            return [self::SIMPLE_ESCAPES[$letter], 1];
        }

        if (isset(self::HEX_ESCAPES[$letter])) {
            $digits = self::HEX_ESCAPES[$letter];
            $hex = substr($text, $offset + 1, $digits);
            if (strlen($hex) === $digits && ctype_xdigit($hex)) {
                // All three are Unicode code points (`\xE9` is "é"), written in UTF-8
                $codePoint = (int) hexdec($hex);
                $isScalarValue = $codePoint <= self::MAX_CODE_POINT
                    && ($codePoint < self::SURROGATE_FIRST || $codePoint > self::SURROGATE_LAST);
                if ($isScalarValue) {
                    return [mb_chr($codePoint, 'UTF-8'), $digits + 1];
                }
            }
        }

        throw new ParserException(sprintf(
            'YAML source "%s": invalid escape sequence "\\%s" in %s.',
            $sourceIdentifier,
            $letter,
            $text,
        ));
    }

    /**
     * @param array<non-empty-string, mixed> $data
     * @param non-empty-string $sourceIdentifier
     *
     * @return iterable<non-empty-string, string>
     */
    private function flatten(array $data, string $prefix, string $sourceIdentifier): iterable
    {
        foreach ($data as $key => $value) {
            $segment = (string) $key;
            $fullKey = $prefix === '' ? $segment : $prefix . self::KEY_SEPARATOR . $segment;

            if (is_string($value)) {
                yield $fullKey => $value;
                continue;
            }

            if (is_array($value)) {
                /** @var array<non-empty-string, mixed> $value */
                yield from $this->flatten($value, $fullKey, $sourceIdentifier);
                continue;
            }

            throw new ParserException(sprintf(
                'YAML source "%s" has unsupported leaf type at key "%s".',
                $sourceIdentifier,
                $fullKey,
            ));
        }
    }
}
