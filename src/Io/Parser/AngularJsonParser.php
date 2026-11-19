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
use stdClass;

/**
 * `ParserInterface` implementation for ngx-translate JSON files
 * (`Format::ANGULAR_JSON`).
 *
 * Accepts the same shapes as the generic JSON parser (flat or
 * namespaced nested dictionaries with string leaves) but is
 * registered against `Format::ANGULAR_JSON` so that the matching
 * `AngularJsonSerializer` round-trips into the nested layout
 * preferred by Angular i18n tooling.
 *
 * Format-level limitations match `JsonParser`: no plurals, no
 * context, no metadata.
 */
final class AngularJsonParser implements ParserInterface
{
    /**
     * @var non-empty-string Dot used to join nested keys.
     */
    private const string KEY_SEPARATOR = '.';

    /**
     * @var non-empty-string UTF-8 byte order mark, tolerated at the start.
     */
    private const string BOM = "\xEF\xBB\xBF";

    public function parse(
        SourceInterface $source,
        string $locale,
        string $domain = 'messages',
    ): TranslationBagInterface {
        $bag = new TranslationBag($locale);
        $content = trim($source->read());
        if (str_starts_with($content, self::BOM)) {
            $content = trim(substr($content, strlen(self::BOM)));
        }
        if ($content === '') {
            return $bag;
        }

        try {
            /** @var mixed $decoded */
            // Decoded as objects so that a JSON list is told apart from a dictionary
            $decoded = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ParserException(sprintf(
                'Angular JSON source "%s" is not valid JSON: %s',
                $source->identifier(),
                $e->getMessage(),
            ), 0, $e);
        }

        if (!$decoded instanceof stdClass) {
            throw new ParserException(sprintf(
                'Angular JSON source "%s" must decode to an object at the top level.',
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
        return Format::ANGULAR_JSON;
    }

    /**
     * @param non-empty-string $sourceIdentifier
     *
     * @return iterable<non-empty-string, string>
     */
    private function flatten(stdClass $data, string $prefix, string $sourceIdentifier): iterable
    {
        foreach (get_object_vars($data) as $key => $value) {
            $segment = (string) $key;
            if ($segment === '') {
                throw new ParserException(sprintf(
                    'Angular JSON source "%s" contains an empty key under prefix "%s".',
                    $sourceIdentifier,
                    $prefix,
                ));
            }
            $fullKey = $prefix === '' ? $segment : $prefix . self::KEY_SEPARATOR . $segment;

            if (is_string($value)) {
                yield $fullKey => $value;
                continue;
            }

            if ($value instanceof stdClass) {
                yield from $this->flatten($value, $fullKey, $sourceIdentifier);
                continue;
            }

            throw new ParserException(sprintf(
                'Angular JSON source "%s" has unsupported leaf type "%s" at key "%s".',
                $sourceIdentifier,
                get_debug_type($value),
                $fullKey,
            ));
        }
    }
}
