<?php

declare(strict_types=1);

namespace G\Translator\Io\Parser;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\Format;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Source\SourceInterface;

/**
 * `ParserInterface` implementation for GNU Gettext Portable Object
 * (`.po` / `.pot`) files.
 *
 * Supports the full canonical PO feature set used by Poedit,
 * xgettext, and the Gettext runtime:
 *
 *   - Translator comments (`#`).
 *   - Extracted comments (`#.`).
 *   - Source references (`#:`).
 *   - Flags (`#,`, e.g. `fuzzy`, `c-format`).
 *   - Disambiguation context (`msgctxt`).
 *   - Singular and plural messages (`msgid` / `msgid_plural` /
 *     `msgstr[N]`).
 *   - Multi-line string concatenation (`msgid ""\n"line"\n"line"`).
 *   - Escape sequences (`\n`, `\t`, `\r`, `\\`, `\"`).
 *   - PO header (the entry whose `msgid` is empty) including
 *     `Plural-Forms` / `Language` / `Project-Id-Version` etc.
 *
 * ## Metadata schema
 *
 * Parsed-out auxiliary data lives in `TranslationEntry::metadata()`
 * under the following keys (all optional, absent when the source
 * carries no such data):
 *
 *   - `references` — `list<non-empty-string>` of `path:line` tokens
 *     extracted from `#:` lines (space-separated tokens are split).
 *   - `flags` — `list<non-empty-string>` of flag tokens extracted
 *     from `#,` lines (comma-separated tokens are split).
 *   - `comments` — `list<non-empty-string>` of translator comments
 *     (one per `#` line).
 *   - `extracted` — `list<non-empty-string>` of extracted comments
 *     (one per `#.` line).
 *   - `po-header` — `array<non-empty-string, string>` of the parsed
 *     PO header (RFC822-like key/value pairs). Attached to EVERY
 *     entry produced by the parser, so serializers can recover it
 *     from any entry regardless of iteration order.
 *
 * ## Plural form mapping
 *
 * The PO format stores plural variants as `msgstr[0]`, `msgstr[1]`,
 * etc., driven by the `nplurals` value declared in the header.
 * Because the bag stores plural forms keyed by CLDR
 * `PluralCategory`, the parser maps form indices to categories
 * using the canonical Gettext convention (see
 * `GETTEXT_NPLURALS_TO_CATEGORIES`). The same table is used by
 * `GettextExpressionPluralResolver` at runtime, so the parser and
 * the resolver agree end-to-end without any cross-layer coupling
 * (the table is duplicated by design — `Io` is forbidden from
 * depending on `Runtime`).
 *
 * Default behavior in the absence of a `Plural-Forms` header:
 * `nplurals = 2`. This matches Gettext's own default and is correct
 * for English-like locales.
 *
 * @see \G\Translator\Runtime\Plural\GettextExpressionPluralResolver
 *      Canonical owner of the nplurals → category convention.
 */
final class PoParser implements ParserInterface
{
    /**
     * @var int Default `nplurals` value when the source carries no
     *      `Plural-Forms` header.
     */
    private const int DEFAULT_NPLURALS = 2;

    /**
     * @var int Maximum `nplurals` value supported. Matches
     *      `GettextExpressionPluralResolver::MAX_NPLURALS`.
     */
    private const int MAX_NPLURALS = 6;

    /**
     * Form-index → PluralCategory mapping keyed by `nplurals`.
     *
     * Documented on the class docblock; mirrors
     * `GettextExpressionPluralResolver::NPLURALS_TO_CATEGORIES`.
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

    public function parse(
        SourceInterface $source,
        string $locale,
        string $domain = 'messages',
    ): TranslationBagInterface {
        $bag = new TranslationBag($locale);
        $content = $this->stripBom($source->read());
        $blocks = $this->splitIntoBlocks($content);

        $header = [];
        $nplurals = self::DEFAULT_NPLURALS;

        foreach ($blocks as $block) {
            $parsed = $this->parseBlock($block, $source->identifier());
            if ($parsed === null) {
                continue;
            }

            if ($this->isHeaderBlock($parsed)) {
                $header = $this->parseHeader($parsed['msgstr'][0] ?? '');
                $nplurals = $this->resolveNplurals($header);
                continue;
            }

            $bag->add($this->buildEntry($parsed, $header, $nplurals, $domain, $source->identifier()));
        }

        return $bag;
    }

    public function format(): Format
    {
        return Format::PO;
    }

    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }

    /**
     * @return list<list<string>> Each inner list is the non-blank
     *         lines of one block (line endings already stripped).
     */
    private function splitIntoBlocks(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $normalized);

        $blocks = [];
        $current = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
                continue;
            }
            $current[] = $line;
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{
     *     comments: list<non-empty-string>,
     *     extracted: list<non-empty-string>,
     *     references: list<non-empty-string>,
     *     flags: list<non-empty-string>,
     *     context: string|null,
     *     id: string,
     *     plural: string|null,
     *     msgstr: array<int, string>
     * }|null
     */
    private function parseBlock(array $lines, string $sourceIdentifier): ?array
    {
        $block = [
            'comments'   => [],
            'extracted'  => [],
            'references' => [],
            'flags'      => [],
            'context'    => null,
            'id'         => null,
            'plural'     => null,
            'msgstr'     => [],
        ];

        /** @var non-empty-string|null $lastDirective */
        $lastDirective = null;
        /** @var int|null $lastMsgstrIndex */
        $lastMsgstrIndex = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                $this->ingestCommentLine($line, $block);
                continue;
            }

            if (preg_match('/^msgctxt\s+"(.*)"\s*$/', $line, $match) === 1) {
                $block['context'] = $this->unescape($match[1]);
                $lastDirective = 'context';
                continue;
            }

            if (preg_match('/^msgid\s+"(.*)"\s*$/', $line, $match) === 1) {
                $block['id'] = $this->unescape($match[1]);
                $lastDirective = 'id';
                continue;
            }

            if (preg_match('/^msgid_plural\s+"(.*)"\s*$/', $line, $match) === 1) {
                $block['plural'] = $this->unescape($match[1]);
                $lastDirective = 'plural';
                continue;
            }

            if (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"\s*$/', $line, $match) === 1) {
                $index = (int) $match[1];
                $block['msgstr'][$index] = $this->unescape($match[2]);
                $lastDirective = 'msgstr';
                $lastMsgstrIndex = $index;
                continue;
            }

            if (preg_match('/^msgstr\s+"(.*)"\s*$/', $line, $match) === 1) {
                $block['msgstr'][0] = $this->unescape($match[1]);
                $lastDirective = 'msgstr';
                $lastMsgstrIndex = 0;
                continue;
            }

            if (preg_match('/^"(.*)"\s*$/', $line, $match) === 1) {
                if ($lastDirective === null) {
                    throw new ParserException(sprintf(
                        'PO source "%s": continuation string "%s" not preceded by a directive.',
                        $sourceIdentifier,
                        $line,
                    ));
                }
                $this->appendContinuation($block, $lastDirective, $lastMsgstrIndex, $this->unescape($match[1]));
                continue;
            }

            throw new ParserException(sprintf(
                'PO source "%s": unrecognized line "%s".',
                $sourceIdentifier,
                $line,
            ));
        }

        if ($block['id'] === null) {
            return null;
        }

        /** @var array{comments: list<non-empty-string>, extracted: list<non-empty-string>, references: list<non-empty-string>, flags: list<non-empty-string>, context: non-empty-string|null, id: string, plural: string|null, msgstr: array<int, string>} $block */
        return $block;
    }

    /**
     * @param array{
     *     comments: list<non-empty-string>,
     *     extracted: list<non-empty-string>,
     *     references: list<non-empty-string>,
     *     flags: list<non-empty-string>,
     *     context: string|null,
     *     id: string|null,
     *     plural: string|null,
     *     msgstr: array<int, string>
     * } $block
     *
     * @param-out array{
     *     comments: list<non-empty-string>,
     *     extracted: list<non-empty-string>,
     *     references: list<non-empty-string>,
     *     flags: list<non-empty-string>,
     *     context: string|null,
     *     id: string|null,
     *     plural: string|null,
     *     msgstr: array<int, string>
     * } $block
     */
    private function ingestCommentLine(string $line, array &$block): void
    {
        if (str_starts_with($line, '#.')) {
            $value = trim(substr($line, 2));
            if ($value !== '') {
                $block['extracted'][] = $value;
            }
            return;
        }
        if (str_starts_with($line, '#:')) {
            $value = trim(substr($line, 2));
            if ($value === '') {
                return;
            }
            foreach (preg_split('/\s+/', $value) ?: [] as $reference) {
                if ($reference !== '') {
                    $block['references'][] = $reference;
                }
            }
            return;
        }
        if (str_starts_with($line, '#,')) {
            $value = trim(substr($line, 2));
            if ($value === '') {
                return;
            }
            foreach (explode(',', $value) as $flag) {
                $trimmed = trim($flag);
                if ($trimmed !== '') {
                    $block['flags'][] = $trimmed;
                }
            }
            return;
        }
        if (str_starts_with($line, '#|') || str_starts_with($line, '#~')) {
            // Previous-msgid (`#|`) and obsolete (`#~`) lines are
            // recognized but ignored; preserving them is out of scope
            // for the canonical model.
            return;
        }

        // Plain translator comment `#` or `# something`.
        $value = ltrim(substr($line, 1));
        if ($value !== '') {
            $block['comments'][] = $value;
        }
    }

    /**
     * @param array{
     *     comments: list<non-empty-string>,
     *     extracted: list<non-empty-string>,
     *     references: list<non-empty-string>,
     *     flags: list<non-empty-string>,
     *     context: string|null,
     *     id: string|null,
     *     plural: string|null,
     *     msgstr: array<int, string>
     * } $block
     *
     * @param-out array{
     *     comments: list<non-empty-string>,
     *     extracted: list<non-empty-string>,
     *     references: list<non-empty-string>,
     *     flags: list<non-empty-string>,
     *     context: string|null,
     *     id: string|null,
     *     plural: string|null,
     *     msgstr: array<int, string>
     * } $block
     */
    private function appendContinuation(
        array &$block,
        string $lastDirective,
        ?int $lastMsgstrIndex,
        string $piece,
    ): void {
        match ($lastDirective) {
            'context' => $block['context'] = ($block['context'] ?? '') . $piece,
            'id'      => $block['id'] = ($block['id'] ?? '') . $piece,
            'plural'  => $block['plural'] = ($block['plural'] ?? '') . $piece,
            'msgstr'  => $block['msgstr'][$lastMsgstrIndex ?? 0] =
                ($block['msgstr'][$lastMsgstrIndex ?? 0] ?? '') . $piece,
            default   => null,
        };

        if ($block['context'] === '') {
            $block['context'] = null;
        }
    }

    /**
     * @param array{id: string, context: string|null, msgstr: array<int, string>} $parsed
     */
    private function isHeaderBlock(array $parsed): bool
    {
        return $parsed['id'] === '' && ($parsed['context'] === null || $parsed['context'] === '');
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function parseHeader(string $headerContent): array
    {
        $result = [];
        foreach (explode("\n", $headerContent) as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $key = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            if ($key === '') {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
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
        if ($value < 1 || $value > self::MAX_NPLURALS) {
            return self::DEFAULT_NPLURALS;
        }

        return $value;
    }

    /**
     * @param array{
     *     comments: list<non-empty-string>,
     *     extracted: list<non-empty-string>,
     *     references: list<non-empty-string>,
     *     flags: list<non-empty-string>,
     *     context: string|null,
     *     id: string,
     *     plural: string|null,
     *     msgstr: array<int, string>
     * } $parsed
     * @param array<non-empty-string, string> $header
     */
    private function buildEntry(
        array $parsed,
        array $header,
        int $nplurals,
        string $domain,
        string $sourceIdentifier,
    ): TranslationEntry {
        if ($parsed['id'] === '') {
            throw new ParserException(sprintf(
                'PO source "%s": found a non-header entry with empty msgid.',
                $sourceIdentifier,
            ));
        }

        $content = $parsed['msgstr'][0] ?? '';
        $pluralForms = $parsed['plural'] !== null
            ? $this->collectPluralForms($parsed['msgstr'], $nplurals)
            : [];
        $context = $parsed['context'] === '' ? null : $parsed['context'];

        return new TranslationEntry(
            id: $parsed['id'],
            content: $content,
            domain: $domain,
            context: $context,
            pluralForms: $pluralForms,
            metadata: $this->buildMetadata($parsed, $header),
        );
    }

    /**
     * @param array<int, string> $msgstr
     *
     * @return array<value-of<PluralCategory>, string>
     */
    private function collectPluralForms(array $msgstr, int $nplurals): array
    {
        $categories = self::GETTEXT_NPLURALS_TO_CATEGORIES[$nplurals]
            ?? self::GETTEXT_NPLURALS_TO_CATEGORIES[self::DEFAULT_NPLURALS];

        $forms = [];
        $hasContent = false;
        foreach ($categories as $index => $category) {
            $value = $msgstr[$index] ?? '';
            $forms[$category->value] = $value;
            if ($value !== '') {
                $hasContent = true;
            }
        }

        if (!$hasContent) {
            return [];
        }

        return $forms;
    }

    /**
     * @param array{
     *     comments: list<non-empty-string>,
     *     extracted: list<non-empty-string>,
     *     references: list<non-empty-string>,
     *     flags: list<non-empty-string>,
     *     plural: string|null
     * } $parsed
     * @param array<non-empty-string, string> $header
     *
     * @return array<non-empty-string, mixed>
     */
    private function buildMetadata(array $parsed, array $header): array
    {
        $metadata = [];

        if ($parsed['comments'] !== []) {
            $metadata['comments'] = $parsed['comments'];
        }
        if ($parsed['extracted'] !== []) {
            $metadata['extracted'] = $parsed['extracted'];
        }
        if ($parsed['references'] !== []) {
            $metadata['references'] = $parsed['references'];
        }
        if ($parsed['flags'] !== []) {
            $metadata['flags'] = $parsed['flags'];
        }
        if ($parsed['plural'] !== null && $parsed['plural'] !== '') {
            $metadata['msgid_plural'] = $parsed['plural'];
        }
        if ($header !== []) {
            $metadata['po-header'] = $header;
        }

        return $metadata;
    }

    private function unescape(string $raw): string
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
}
