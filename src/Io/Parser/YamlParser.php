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
 *   - Scalar strings — unquoted (taken literally), single-quoted
 *     (no escapes; `''` represents a literal single quote), or
 *     double-quoted (with `\n` `\t` `\r` `\\` `\"` escapes).
 *   - Comments (`#`) on their own line or trailing after a value.
 *   - The optional `---` document-start marker on the first line.
 *
 * ## Deliberately rejected
 *
 *   - Multi-line scalars (`|`, `>`). Translation files do not need
 *     them and supporting them is a maintenance trap.
 *   - Anchors (`&`), aliases (`*`), tags (`!!`).
 *   - Flow style (`{key: value}`, `[a, b]`).
 *   - List items (`-`). The translation model is map-of-strings;
 *     list values have no canonical mapping.
 *   - Numeric / boolean scalar coercion. Every value is parsed as
 *     a string; YAML's implicit typing (`yes`, `true`, `42`, `null`,
 *     …) is intentionally NOT applied here.
 *
 * Any input outside this subset triggers `ParserException`.
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
     *      on the first non-blank line; it is skipped.
     */
    private const string DOCUMENT_START = '---';

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
            $content = substr($content, 3);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $rawLines = explode("\n", $content);

        $result = [];
        foreach ($rawLines as $index => $line) {
            $stripped = rtrim($line);
            $trimmed = ltrim($stripped);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, '#')) {
                continue;
            }
            if ($index === 0 && $trimmed === self::DOCUMENT_START) {
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
            return -1;
        }

        return $this->indentOf($lines[$cursor], $sourceIdentifier);
    }

    /**
     * @param non-empty-string $sourceIdentifier
     *
     * @return array{0: non-empty-string, 1: string}
     */
    private function extractKeyAndValue(string $line, string $sourceIdentifier): array
    {
        $trimmed = ltrim($line);

        // Reject flow style, list items, and multi-line scalar markers up front.
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

        // `key: rest` (rest may be empty for nested maps).
        if (preg_match('/^([^\s:#]+)\s*:(?:\s+(.*))?$/', $trimmed, $match) !== 1) {
            throw new ParserException(sprintf(
                'YAML source "%s": expected "key: value", got "%s".',
                $sourceIdentifier,
                $line,
            ));
        }

        // The regex `[^\s:#]+` already guarantees a non-empty key.
        $key = $match[1];
        $value = $match[2] ?? '';

        return [$key, $value];
    }

    /**
     * @param non-empty-string $sourceIdentifier
     */
    private function parseScalar(string $raw, string $sourceIdentifier): string
    {
        $value = $this->stripTrailingComment($raw);

        if ($value === '|' || str_starts_with($value, '|') || str_starts_with($value, '>')) {
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
        if (str_starts_with($value, '!!') || str_starts_with($value, '!')) {
            throw new ParserException(sprintf(
                'YAML source "%s": tags ("!", "!!") are not supported.',
                $sourceIdentifier,
            ));
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
            return $this->unescapeDoubleQuoted(substr($value, 1, -1));
        }

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            throw new ParserException(sprintf(
                'YAML source "%s": flow style ("{...}", "[...]") is not supported in value position.',
                $sourceIdentifier,
            ));
        }

        return $value;
    }

    private function stripTrailingComment(string $value): string
    {
        // A trailing `#` MUST be preceded by whitespace to be a comment.
        if (preg_match('/^(.*?)\s+#.*$/', $value, $match) === 1) {
            return rtrim($match[1]);
        }

        return $value;
    }

    private function unescapeDoubleQuoted(string $raw): string
    {
        $result = '';
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char !== '\\' || $i + 1 >= $length) {
                $result .= $char;
                continue;
            }
            $next = $raw[$i + 1];
            $result .= match ($next) {
                '\\' => '\\',
                '"'  => '"',
                'n'  => "\n",
                't'  => "\t",
                'r'  => "\r",
                default => '\\' . $next,
            };
            $i++;
        }

        return $result;
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
            $segment = $key;
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
