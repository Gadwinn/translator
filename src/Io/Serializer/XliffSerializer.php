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
 * Units carry the entry id twice: `id` and `resname` in XLIFF 1.2;
 * `name` and `id` in XLIFF 2.0, where `id` must be an XML NMTOKEN
 * (an id with spaces or punctuation gets an `id` derived from its
 * hash). The file is named after `$domain` (`messages` when null).
 *
 * The source language (`source-language`, `srcLang`) is the one given
 * to the constructor, else the one the parser kept from the original
 * file (`metadata['xliff-source-language']`), else `en`.
 *
 * Limitations match the parser: no plural-unit groups, no inline
 * placeholder fidelity, no context (`msgctxt`-equivalent). Two
 * entries sharing an id (different contexts or domains) cannot both
 * be written, nor can control characters that XML 1.0 forbids: both
 * throw a `SerializerException`.
 */
final class XliffSerializer implements SerializerInterface
{
    private const string XML_VERSION = '1.0';

    /**
     * @var non-empty-string Source language written when none is known.
     */
    private const string DEFAULT_SOURCE_LANGUAGE = 'en';

    private const string XML_ENCODING = 'UTF-8';

    private const string DEFAULT_FILE = 'messages';

    /**
     * @var non-empty-string Characters XML 1.0 forbids in a document.
     */
    private const string FORBIDDEN_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /**
     * @var non-empty-string An XML NMTOKEN (simplified to letters, digits, `.`, `-`, `_`, `:`).
     */
    private const string NMTOKEN = '/^[\p{L}\p{N}._:\-]+$/uD';

    /**
     * @param non-empty-string|null $sourceLocale Source language of the file;
     *        null keeps the one read from the original XLIFF file, or `en`.
     */
    public function __construct(
        private readonly ?string $sourceLocale = null,
    ) {
    }

    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $entries = $bag->all($domain);
        $version = $this->resolveVersion($entries);
        $this->assertWritable($entries);
        $file = $domain ?? self::DEFAULT_FILE;

        try {
            $doc = new DOMDocument(self::XML_VERSION, self::XML_ENCODING);
            $doc->formatOutput = true;

            match ($version) {
                XliffParser::VERSION_2_0 => $this->buildXliff20($doc, $bag, $entries, $file),
                default                  => $this->buildXliff12($doc, $bag, $entries, $file),
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
    private function buildXliff12(DOMDocument $doc, TranslationBagInterface $bag, array $entries, string $name): void
    {
        $root = $doc->createElement('xliff');
        $root->setAttribute('version', XliffParser::VERSION_1_2);
        $root->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:1.2');
        $doc->appendChild($root);

        $file = $doc->createElement('file');
        $file->setAttribute('source-language', $this->resolveSourceLanguage($entries));
        $file->setAttribute('target-language', $bag->locale());
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('original', $name);
        $root->appendChild($file);

        $body = $doc->createElement('body');
        $file->appendChild($body);

        foreach ($entries as $entry) {
            $unit = $doc->createElement('trans-unit');
            $unit->setAttribute('id', $entry->id());
            $unit->setAttribute('resname', $entry->id());
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
    private function buildXliff20(DOMDocument $doc, TranslationBagInterface $bag, array $entries, string $name): void
    {
        $root = $doc->createElement('xliff');
        $root->setAttribute('version', XliffParser::VERSION_2_0);
        $root->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:2.0');
        $root->setAttribute('srcLang', $this->resolveSourceLanguage($entries));
        $root->setAttribute('trgLang', $bag->locale());
        $doc->appendChild($root);

        $file = $doc->createElement('file');
        $file->setAttribute('id', preg_match(self::NMTOKEN, $name) === 1 ? $name : self::DEFAULT_FILE);
        $root->appendChild($file);

        foreach ($entries as $entry) {
            $unit = $doc->createElement('unit');
            $unit->setAttribute(
                'id',
                preg_match(self::NMTOKEN, $entry->id()) === 1 ? $entry->id() : 'u' . hash('xxh128', $entry->id()),
            );
            $unit->setAttribute('name', $entry->id());
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

    /**
     * @param list<TranslationEntryInterface> $entries
     */
    private function resolveSourceLanguage(array $entries): string
    {
        if ($this->sourceLocale !== null) {
            return $this->sourceLocale;
        }

        foreach ($entries as $entry) {
            $language = $entry->metadata()[XliffParser::SOURCE_LANGUAGE_METADATA] ?? null;
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return self::DEFAULT_SOURCE_LANGUAGE;
    }

    /**
     * @param list<TranslationEntryInterface> $entries
     *
     * @throws SerializerException
     */
    private function assertWritable(array $entries): void
    {
        $seen = [];
        foreach ($entries as $entry) {
            $id = $entry->id();
            if (isset($seen[$id])) {
                throw new SerializerException(sprintf(
                    'XLIFF cannot hold two entries with the id "%s" (different contexts or domains): '
                    . 'serialize one domain at a time.',
                    $id,
                ));
            }
            $seen[$id] = true;

            $source = $entry->metadata()['source'] ?? '';
            $texts = [$id, $entry->content(), is_string($source) ? $source : '', ...$this->extractNotes($entry)];
            foreach ($texts as $text) {
                if (preg_match(self::FORBIDDEN_CHARACTERS, $text) === 1) {
                    throw new SerializerException(sprintf(
                        'XLIFF (XML 1.0) cannot hold the control characters of entry "%s".',
                        $id,
                    ));
                }
            }
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
