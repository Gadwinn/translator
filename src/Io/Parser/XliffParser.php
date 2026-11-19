<?php

declare(strict_types=1);

namespace G\Translator\Io\Parser;

use DOMDocument;
use DOMElement;
use DOMException;
use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Source\SourceInterface;

/**
 * `ParserInterface` implementation for XLIFF 1.2 and 2.0.
 *
 * Uses PHP's bundled `DOMDocument` (no `ext-xml` external
 * dependency required beyond what ships with every PHP build).
 * The XLIFF version is detected from the root element's
 * `version` attribute and the parser dispatches to the matching
 * walker.
 *
 * Supported XLIFF 1.2 nodes:
 *
 *   - `<xliff>` (root) — must declare `version="1.2"`.
 *   - `<file>` — one or more; the first one's `target-language` is
 *     informational only (the bag's locale comes from the
 *     `parse()` argument).
 *   - `<body>` containing `<trans-unit>` elements.
 *   - `<trans-unit resname="..." id="...">` with `<source>` and
 *     `<target>` children. The entry id is `resname` (the resource
 *     name, as written by Symfony and most tools, whose `id` is often
 *     a hash or a number), else `id`.
 *   - `<note>` children captured as `metadata['notes']`.
 *
 * Supported XLIFF 2.0 nodes:
 *
 *   - `<xliff>` (root) — must declare `version="2.0"`.
 *   - `<file id="...">`.
 *   - `<unit name="..." id="...">` containing `<segment>` blocks.
 *     The entry id is `name` (the resource name), else `id`.
 *   - `<segment>` with `<source>` and `<target>` children.
 *   - `<notes>/<note>` captured as `metadata['notes']`.
 *
 * Limitations:
 *
 *   - Inline placeholders (`<g>`, `<x>`, `<bpt>`, `<ph>`, …) are
 *     preserved as raw inner text. Their serialized round-trip
 *     is intentionally simplified — full inline-tag fidelity is
 *     beyond Phase D's scope.
 *   - Plural-unit groups (`xliff:plural` in 1.2 extensions, the
 *     `xml:lang` / `nplural` constructs in 2.0) are NOT
 *     interpreted; the parser collects unit-level data only.
 *
 * The XLIFF version actually seen during parsing is preserved on
 * every entry under `metadata['xliff-version']`, and the source
 * language (`source-language` of the 1.2 `<file>`, `srcLang` of the
 * 2.0 root) under `metadata['xliff-source-language']`, so the
 * matching serializer can emit the same values on round-trip.
 */
final class XliffParser implements ParserInterface
{
    /**
     * @var non-empty-string Metadata key holding the source language of the file.
     */
    public const string SOURCE_LANGUAGE_METADATA = 'xliff-source-language';

    /**
     * @var non-empty-string Canonical version string for XLIFF 1.2.
     */
    public const string VERSION_1_2 = '1.2';

    /**
     * @var non-empty-string Canonical version string for XLIFF 2.0.
     */
    public const string VERSION_2_0 = '2.0';

    public function parse(
        SourceInterface $source,
        string $locale,
        string $domain = 'messages',
    ): TranslationBagInterface {
        $bag = new TranslationBag($locale);
        $content = trim($source->read());
        if ($content === '') {
            return $bag;
        }

        $doc = $this->loadXml($content, $source->identifier());
        $root = $doc->documentElement;
        if ($root === null || $root->localName !== 'xliff') {
            throw new ParserException(sprintf(
                'XLIFF source "%s": root element MUST be <xliff>.',
                $source->identifier(),
            ));
        }

        $version = $root->getAttribute('version');

        match ($version) {
            self::VERSION_1_2 => $this->walkXliff12($root, $bag, $domain, $source->identifier()),
            self::VERSION_2_0 => $this->walkXliff20($root, $bag, $domain, $source->identifier()),
            default => throw new ParserException(sprintf(
                'XLIFF source "%s": unsupported version "%s" (expected "1.2" or "2.0").',
                $source->identifier(),
                $version,
            )),
        };

        return $bag;
    }

    public function format(): Format
    {
        return Format::XLIFF;
    }

    /**
     * @param non-empty-string $sourceIdentifier
     */
    private function loadXml(string $content, string $sourceIdentifier): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $doc->loadXML($content, LIBXML_NONET);
        } catch (DOMException $e) {
            throw new ParserException(sprintf(
                'XLIFF source "%s": %s',
                $sourceIdentifier,
                $e->getMessage(),
            ), 0, $e);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $message = $errors !== [] ? trim($errors[0]->message) : 'invalid XML';
            throw new ParserException(sprintf(
                'XLIFF source "%s": %s',
                $sourceIdentifier,
                $message,
            ));
        }

        return $doc;
    }

    /**
     * @param non-empty-string $domain
     * @param non-empty-string $sourceIdentifier
     */
    private function walkXliff12(
        DOMElement $root,
        TranslationBag $bag,
        string $domain,
        string $sourceIdentifier,
    ): void {
        foreach ($this->childElements($root, 'file') as $file) {
            $sourceLanguage = $file->getAttribute('source-language');
            foreach ($this->childElements($file, 'body') as $body) {
                foreach ($this->childElements($body, 'trans-unit') as $unit) {
                    $entry = $this->buildEntryFromUnit12($unit, $domain, $sourceIdentifier, $sourceLanguage);
                    if ($entry !== null) {
                        $bag->add($entry);
                    }
                }
            }
        }
    }

    /**
     * @param non-empty-string $domain
     * @param non-empty-string $sourceIdentifier
     */
    private function walkXliff20(
        DOMElement $root,
        TranslationBag $bag,
        string $domain,
        string $sourceIdentifier,
    ): void {
        $sourceLanguage = $root->getAttribute('srcLang');
        foreach ($this->childElements($root, 'file') as $file) {
            foreach ($this->childElements($file, 'unit') as $unit) {
                $bag->add($this->buildEntryFromUnit20($unit, $domain, $sourceIdentifier, $sourceLanguage));
            }
        }
    }

    /**
     * @param non-empty-string $domain
     * @param non-empty-string $sourceIdentifier
     */
    private function buildEntryFromUnit12(
        DOMElement $unit,
        string $domain,
        string $sourceIdentifier,
        string $sourceLanguage,
    ): ?TranslationEntry {
        $id = $this->preferAttribute($unit, ['resname', 'id']);
        if ($id === null) {
            return null;
        }

        $source = $this->firstChildText($unit, 'source');
        $target = $this->firstChildText($unit, 'target');
        $notes = $this->collectNotes($unit);

        $metadata = [
            'xliff-version' => self::VERSION_1_2,
        ];
        if ($sourceLanguage !== '') {
            $metadata[self::SOURCE_LANGUAGE_METADATA] = $sourceLanguage;
        }
        if ($notes !== []) {
            $metadata['notes'] = $notes;
        }
        if ($source !== null && $source !== $id) {
            $metadata['source'] = $source;
        }

        if ($id === '') {
            throw new ParserException(sprintf(
                'XLIFF source "%s": <trans-unit> with empty id.',
                $sourceIdentifier,
            ));
        }

        return new TranslationEntry(
            id: $id,
            content: $target ?? '',
            domain: $domain,
            metadata: $metadata,
        );
    }

    /**
     * @param non-empty-string $domain
     * @param non-empty-string $sourceIdentifier
     */
    private function buildEntryFromUnit20(
        DOMElement $unit,
        string $domain,
        string $sourceIdentifier,
        string $sourceLanguage,
    ): TranslationEntry {
        $id = $this->preferAttribute($unit, ['name', 'id']) ?? '';
        if ($id === '') {
            throw new ParserException(sprintf(
                'XLIFF source "%s": <unit> missing id attribute.',
                $sourceIdentifier,
            ));
        }

        $source = null;
        $target = null;
        foreach ($this->childElements($unit, 'segment') as $segment) {
            $source ??= $this->firstChildText($segment, 'source');
            $target ??= $this->firstChildText($segment, 'target');
            if ($source !== null && $target !== null) {
                break;
            }
        }

        $notes = [];
        foreach ($this->childElements($unit, 'notes') as $notesNode) {
            $notes = array_merge($notes, $this->collectNotes($notesNode));
        }

        $metadata = [
            'xliff-version' => self::VERSION_2_0,
        ];
        if ($sourceLanguage !== '') {
            $metadata[self::SOURCE_LANGUAGE_METADATA] = $sourceLanguage;
        }
        if ($notes !== []) {
            $metadata['notes'] = $notes;
        }
        if ($source !== null && $source !== $id) {
            $metadata['source'] = $source;
        }

        return new TranslationEntry(
            id: $id,
            content: $target ?? '',
            domain: $domain,
            metadata: $metadata,
        );
    }

    /**
     * @return iterable<DOMElement>
     */
    private function childElements(DOMElement $parent, string $localName): iterable
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $localName) {
                yield $node;
            }
        }
    }

    private function firstChildText(DOMElement $parent, string $localName): ?string
    {
        foreach ($this->childElements($parent, $localName) as $node) {
            return $node->textContent;
        }

        return null;
    }

    /**
     * @return list<non-empty-string>
     */
    private function collectNotes(DOMElement $parent): array
    {
        $notes = [];
        foreach ($this->childElements($parent, 'note') as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $notes[] = $text;
            }
        }

        return $notes;
    }

    /**
     * @param list<non-empty-string> $names
     */
    private function preferAttribute(DOMElement $element, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $element->getAttribute($name);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
