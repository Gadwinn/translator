<?php

declare(strict_types=1);

namespace G\Translator\Io\Serializer;

use DOMDocument;
use DOMElement;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\SerializerException;
use G\Translator\Io\Parser\XliffParser;
use Throwable;

/**
 * `SerializerInterface` implementation for XLIFF 1.2 and 2.0.
 *
 * Emits the version preserved in `metadata['xliff-version']` of
 * the first entry the serializer encounters. When no entry carries
 * that key (e.g., a bag assembled by hand), the serializer falls
 * back to XLIFF 1.2 — the most widely supported version across
 * tooling.
 *
 * Both versions emit:
 *
 *   - `<source>` populated from `metadata['source']` when present,
 *     otherwise from the entry's `id` (XLIFF 1.2 convention) or
 *     `id` (XLIFF 2.0 convention).
 *   - `<target>` populated from the entry's `content()`.
 *   - `<note>` elements from `metadata['notes']` when present.
 *
 * Limitations match the parser: no plural-unit groups, no inline
 * placeholder fidelity, no context (`msgctxt`-equivalent).
 */
final class XliffSerializer implements SerializerInterface
{
    /**
     * @var non-empty-string Pseudo-language code used in the
     *      `target-language` attribute of XLIFF 1.2 output when
     *      the bag locale would be invalid as an XML attribute.
     *      Translations from a bag carry their locale, but XLIFF
     *      attributes use a separate naming convention; we simply
     *      pass the locale through.
     */
    private const string XML_VERSION = '1.0';

    private const string XML_ENCODING = 'UTF-8';

    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $entries = $bag->all($domain);
        $version = $this->resolveVersion($entries);

        try {
            $doc = new DOMDocument(self::XML_VERSION, self::XML_ENCODING);
            $doc->formatOutput = true;

            match ($version) {
                XliffParser::VERSION_2_0 => $this->buildXliff20($doc, $bag, $entries),
                default                  => $this->buildXliff12($doc, $bag, $entries),
            };

            $xml = $doc->saveXML();
        } catch (Throwable $e) {
            throw new SerializerException(
                'Failed to serialize translation bag as XLIFF: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($xml === false) {
            throw new SerializerException('Failed to serialize translation bag as XLIFF.');
        }

        return $xml;
    }

    public function format(): Format
    {
        return Format::XLIFF;
    }

    /**
     * @param list<TranslationEntryInterface> $entries
     */
    private function resolveVersion(array $entries): string
    {
        foreach ($entries as $entry) {
            $version = $entry->metadata()['xliff-version'] ?? null;
            if ($version === XliffParser::VERSION_2_0) {
                return XliffParser::VERSION_2_0;
            }
            if ($version === XliffParser::VERSION_1_2) {
                return XliffParser::VERSION_1_2;
            }
        }

        return XliffParser::VERSION_1_2;
    }

    /**
     * @param list<TranslationEntryInterface> $entries
     */
    private function buildXliff12(DOMDocument $doc, TranslationBagInterface $bag, array $entries): void
    {
        $root = $doc->createElement('xliff');
        $root->setAttribute('version', XliffParser::VERSION_1_2);
        $root->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:1.2');
        $doc->appendChild($root);

        $file = $doc->createElement('file');
        $file->setAttribute('source-language', 'en');
        $file->setAttribute('target-language', $bag->locale());
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('original', 'messages');
        $root->appendChild($file);

        $body = $doc->createElement('body');
        $file->appendChild($body);

        foreach ($entries as $entry) {
            $unit = $doc->createElement('trans-unit');
            $unit->setAttribute('id', $entry->id());
            $body->appendChild($unit);

            $source = $entry->metadata()['source'] ?? $entry->id();
            $sourceText = is_string($source) ? $source : $entry->id();
            $unit->appendChild($this->createTextChild($doc, 'source', $sourceText));
            $unit->appendChild($this->createTextChild($doc, 'target', $entry->content()));

            foreach ($this->extractNotes($entry) as $note) {
                $unit->appendChild($this->createTextChild($doc, 'note', $note));
            }
        }
    }

    /**
     * @param list<TranslationEntryInterface> $entries
     */
    private function buildXliff20(DOMDocument $doc, TranslationBagInterface $bag, array $entries): void
    {
        $root = $doc->createElement('xliff');
        $root->setAttribute('version', XliffParser::VERSION_2_0);
        $root->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:2.0');
        $root->setAttribute('srcLang', 'en');
        $root->setAttribute('trgLang', $bag->locale());
        $doc->appendChild($root);

        $file = $doc->createElement('file');
        $file->setAttribute('id', 'messages');
        $root->appendChild($file);

        foreach ($entries as $entry) {
            $unit = $doc->createElement('unit');
            $unit->setAttribute('id', $entry->id());
            $file->appendChild($unit);

            $notes = $this->extractNotes($entry);
            if ($notes !== []) {
                $notesEl = $doc->createElement('notes');
                $unit->appendChild($notesEl);
                foreach ($notes as $note) {
                    $notesEl->appendChild($this->createTextChild($doc, 'note', $note));
                }
            }

            $segment = $doc->createElement('segment');
            $unit->appendChild($segment);

            $source = $entry->metadata()['source'] ?? $entry->id();
            $sourceText = is_string($source) ? $source : $entry->id();
            $segment->appendChild($this->createTextChild($doc, 'source', $sourceText));
            $segment->appendChild($this->createTextChild($doc, 'target', $entry->content()));
        }
    }

    private function createTextChild(DOMDocument $doc, string $tag, string $value): DOMElement
    {
        $element = $doc->createElement($tag);
        if ($value !== '') {
            $element->appendChild($doc->createTextNode($value));
        }

        return $element;
    }

    /**
     * @return list<non-empty-string>
     */
    private function extractNotes(TranslationEntryInterface $entry): array
    {
        $notes = $entry->metadata()['notes'] ?? null;
        if (!is_array($notes)) {
            return [];
        }

        $result = [];
        foreach ($notes as $note) {
            if (is_string($note) && $note !== '') {
                $result[] = $note;
            }
        }

        return $result;
    }
}
