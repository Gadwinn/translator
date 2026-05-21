<?php

declare(strict_types=1);

namespace G\Translator\Io\Parser;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Source\SourceInterface;
use JsonException;

/**
 * `ParserInterface` implementation for generic JSON translation
 * files.
 *
 * Accepted shapes:
 *
 *   - Flat dictionary:
 *     ```json
 *     { "hello": "Hello", "bye": "Goodbye" }
 *     ```
 *   - Nested dictionary (namespaced); leaves MUST be strings.
 *     Nested objects are flattened to dotted keys at parse time:
 *     ```json
 *     { "app": { "title": "Title" } }
 *     ```
 *     becomes one entry with id `"app.title"`.
 *
 * Non-string leaves (numbers, booleans, `null`, arrays, deeply
 * mixed structures) raise `ParserException`. JSON syntax errors
 * raise `ParserException` as well.
 *
 * The JSON format carries no native concept of plurals, contexts,
 * or rich metadata; entries produced by this parser therefore
 * have no plural forms, no context, and an empty metadata map.
 * Round-tripping through `JsonSerializer` is lossless on
 * `(id, content)` pairs only — anything else SHOULD be sourced
 * from a format that supports it (PO, XLIFF).
 */
final class JsonParser implements ParserInterface
{
    /**
     * @var non-empty-string Dot used to join nested keys.
     */
    private const string KEY_SEPARATOR = '.';

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

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ParserException(sprintf(
                'JSON source "%s" is not valid JSON: %s',
                $source->identifier(),
                $e->getMessage(),
            ), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new ParserException(sprintf(
                'JSON source "%s" must decode to an object/dictionary at the top level.',
                $source->identifier(),
            ));
        }

        foreach ($this->flatten($decoded, '', $source->identifier()) as $key => $value) {
            $bag->add(new TranslationEntry(id: $key, content: $value, domain: $domain));
        }

        return $bag;
    }

    public function format(): Format
    {
        return Format::JSON;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param non-empty-string $sourceIdentifier
     *
     * @return iterable<non-empty-string, string>
     */
    private function flatten(array $data, string $prefix, string $sourceIdentifier): iterable
    {
        foreach ($data as $key => $value) {
            $segment = (string) $key;
            if ($segment === '') {
                throw new ParserException(sprintf(
                    'JSON source "%s" contains an empty key under prefix "%s".',
                    $sourceIdentifier,
                    $prefix,
                ));
            }
            $fullKey = $prefix === '' ? $segment : $prefix . self::KEY_SEPARATOR . $segment;

            if (is_string($value)) {
                yield $fullKey => $value;
                continue;
            }

            if (is_array($value)) {
                yield from $this->flatten($value, $fullKey, $sourceIdentifier);
                continue;
            }

            throw new ParserException(sprintf(
                'JSON source "%s" has unsupported leaf type "%s" at key "%s".',
                $sourceIdentifier,
                get_debug_type($value),
                $fullKey,
            ));
        }
    }
}
