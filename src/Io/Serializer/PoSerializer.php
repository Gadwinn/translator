<?php

declare(strict_types=1);

namespace G\Translator\Io\Serializer;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Enum\Format;
use G\Translator\Enum\PluralCategory;

/**
 * `SerializerInterface` implementation for GNU Gettext Portable
 * Object (`.po`) output.
 *
 * Mirrors the metadata schema documented on `PoParser`:
 *
 *   - `metadata['comments']`   → `#`  lines (translator comments).
 *   - `metadata['extracted']`  → `#.` lines (extracted comments).
 *   - `metadata['references']` → `#:` lines (one token per line).
 *   - `metadata['flags']`      → `#,` line (comma-joined tokens).
 *   - `metadata['po-header']`  → the empty-`msgid` header block.
 *
 * The header is emitted as the very first block when ANY entry in
 * the bag carries a `po-header` metadata key. If multiple entries
 * carry distinct headers, the first one encountered wins (in
 * practice the parser writes the same header to every entry, so
 * the choice is observable only when the bag was assembled by
 * hand).
 *
 * Output format choices:
 *
 *   - Single-line strings unless the value contains a newline;
 *     newlines trigger the `msgid ""\n"part 1\n"\n"part 2"`
 *     multi-line layout, with one logical line per `\n`. This is
 *     the convention used by Poedit, xgettext, and gettextize.
 *   - Plural forms are emitted in `nplurals` order, indexed by
 *     `msgstr[0]`, `msgstr[1]`, …, mapping back through the same
 *     `GETTEXT_NPLURALS_TO_CATEGORIES` convention used by the
 *     parser.
 *   - The line separator is `"\n"`; the file is plain UTF-8 with
 *     no BOM.
 */
final class PoSerializer implements SerializerInterface
{
    /**
     * @var int Default `nplurals` value when the bag's header has
     *      none. Matches `PoParser::DEFAULT_NPLURALS`.
     */
    private const int DEFAULT_NPLURALS = 2;

    /**
     * Form-index → PluralCategory mapping (same table as
     * `PoParser`).
     *
     * @var array<int, non-empty-list<PluralCategory>>
     */
    private const array GETTEXT_NPLURALS_TO_CATEGORIES = [
        1 => [PluralCategory::OTHER],
        2 => [PluralCategory::ONE, PluralCategory::OTHER],
        3 => [PluralCategory::ONE, PluralCategory::FEW, PluralCategory::OTHER],
        4 => [PluralCategory::ONE, PluralCategory::FEW, PluralCategory::MANY, PluralCategory::OTHER],
        5 => [
            PluralCategory::ONE,
            PluralCategory::TWO,
            PluralCategory::FEW,
            PluralCategory::MANY,
            PluralCategory::OTHER,
        ],
        6 => [
            PluralCategory::ZERO,
            PluralCategory::ONE,
            PluralCategory::TWO,
            PluralCategory::FEW,
            PluralCategory::MANY,
            PluralCategory::OTHER,
        ],
    ];

    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $entries = $bag->all($domain);

        $header = $this->extractHeader($entries);
        $nplurals = $this->resolveNplurals($header);

        $blocks = [];
        if ($header !== []) {
            $blocks[] = $this->serializeHeader($header);
        }

        foreach ($entries as $entry) {
            $blocks[] = $this->serializeEntry($entry, $nplurals);
        }

        if ($blocks === []) {
            return '';
        }

        // PO blocks MUST be separated by a blank line. `implode` with
        // `"\n\n"` inserts exactly one blank line between consecutive
        // blocks and a single trailing newline closes the file cleanly.
        return implode("\n\n", $blocks) . "\n";
    }

    public function format(): Format
    {
        return Format::PO;
    }

    /**
     * @param list<TranslationEntryInterface> $entries
     *
     * @return array<non-empty-string, string>
     */
    private function extractHeader(array $entries): array
    {
        foreach ($entries as $entry) {
            $header = $entry->metadata()['po-header'] ?? null;
            if (is_array($header) && $header !== []) {
                /** @var array<non-empty-string, string> $header */
                return $header;
            }
        }

        return [];
    }

    /**
     * @param array<non-empty-string, string> $header
     */
    private function resolveNplurals(array $header): int
    {
        $pluralForms = $header['Plural-Forms'] ?? null;
        if ($pluralForms === null) {
            return self::DEFAULT_NPLURALS;
        }

        if (preg_match('/nplurals\s*=\s*(\d+)/', $pluralForms, $match) !== 1) {
            return self::DEFAULT_NPLURALS;
        }

        $value = (int) $match[1];
        if (!isset(self::GETTEXT_NPLURALS_TO_CATEGORIES[$value])) {
            return self::DEFAULT_NPLURALS;
        }

        return $value;
    }

    /**
     * @param array<non-empty-string, string> $header
     */
    private function serializeHeader(array $header): string
    {
        $lines = ['msgid ""'];

        $headerBody = '';
        foreach ($header as $name => $value) {
            $headerBody .= $name . ': ' . $value . "\n";
        }

        $lines[] = 'msgstr ""';
        foreach ($this->splitForOutput($headerBody) as $piece) {
            $lines[] = '"' . $this->escape($piece) . '"';
        }

        return implode("\n", $lines);
    }

    private function serializeEntry(TranslationEntryInterface $entry, int $nplurals): string
    {
        $metadata = $entry->metadata();
        $lines = [];

        $this->appendCommentLines(
            $lines,
            '# ',
            $this->stringListMetadata($metadata, 'comments'),
        );
        $this->appendCommentLines(
            $lines,
            '#. ',
            $this->stringListMetadata($metadata, 'extracted'),
        );
        $this->appendCommentLines(
            $lines,
            '#: ',
            $this->stringListMetadata($metadata, 'references'),
        );

        $flags = $this->stringListMetadata($metadata, 'flags');
        if ($flags !== []) {
            $this->appendCommentLines($lines, '#, ', [implode(', ', $flags)]);
        }

        $context = $entry->context();
        if ($context !== null) {
            $lines[] = $this->serializeString('msgctxt', $context);
        }

        $lines[] = $this->serializeString('msgid', $entry->id());

        $pluralForms = $entry->pluralForms();
        if ($pluralForms === []) {
            $lines[] = $this->serializeString('msgstr', $entry->content());
            return implode("\n", $lines);
        }

        $sourcePlural = $this->extractSourcePlural($entry);
        $lines[] = $this->serializeString('msgid_plural', $sourcePlural);

        $categories = self::GETTEXT_NPLURALS_TO_CATEGORIES[$nplurals];
        foreach ($categories as $index => $category) {
            $value = $pluralForms[$category->value] ?? '';
            $lines[] = $this->serializeString(sprintf('msgstr[%d]', $index), $value);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     * @param list<non-empty-string> $values
     *
     * @param-out list<string> $lines
     */
    private function appendCommentLines(array &$lines, string $prefix, array $values): void
    {
        // A comment spanning several lines gets the prefix on each of them
        foreach ($values as $value) {
            foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $value)) as $line) {
                $lines[] = rtrim($prefix . $line);
            }
        }
    }

    /**
     * @param array<non-empty-string, mixed> $metadata
     * @param non-empty-string $key
     *
     * @return list<non-empty-string>
     */
    private function stringListMetadata(array $metadata, string $key): array
    {
        $value = $metadata[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function extractSourcePlural(TranslationEntryInterface $entry): string
    {
        $metadata = $entry->metadata();
        $candidate = $metadata['msgid_plural'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        // Fallback when the bag was assembled without preserving the
        // source plural form: reuse the singular id. The result still
        // round-trips through this parser/serializer pair.
        return $entry->id();
    }

    private function serializeString(string $directive, string $value): string
    {
        if (!str_contains($value, "\n")) {
            return $directive . ' "' . $this->escape($value) . '"';
        }

        $lines = [$directive . ' ""'];
        foreach ($this->splitForOutput($value) as $piece) {
            $lines[] = '"' . $this->escape($piece) . '"';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string> Pieces ending with `\n` except possibly
     *         the last one (which can be empty).
     */
    private function splitForOutput(string $value): array
    {
        $pieces = explode("\n", $value);
        $result = [];
        $lastIndex = count($pieces) - 1;
        foreach ($pieces as $index => $piece) {
            if ($index === $lastIndex) {
                if ($piece !== '') {
                    $result[] = $piece;
                }
                continue;
            }
            $result[] = $piece . "\n";
        }

        return $result;
    }

    private function escape(string $value): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $result .= match ($char) {
                '\\' => '\\\\',
                '"'  => '\\"',
                "\n" => '\\n',
                "\t" => '\\t',
                "\r" => '\\r',
                default => $char,
            };
        }

        return $result;
    }
}
