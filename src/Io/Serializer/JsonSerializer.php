<?php

declare(strict_types=1);

namespace G\Translator\Io\Serializer;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\SerializerException;
use JsonException;

/**
 * `SerializerInterface` implementation for generic JSON output.
 *
 * Produces a FLAT dictionary keyed by entry id. Output is
 * pretty-printed with two-space indentation, Unicode characters
 * preserved verbatim (no `\uXXXX` escapes), and forward slashes
 * left as-is. The trailing newline is intentionally omitted so
 * the output can be piped directly without normalization.
 *
 * Limitations:
 *
 *   - Plural forms are NOT emitted. Entries carrying
 *     `pluralForms()` will be serialized with their singular
 *     `content()` only. JSON has no canonical plural shape;
 *     consumers needing plurals SHOULD use PO or XLIFF.
 *   - Metadata (references, comments, flags, …) is NOT emitted.
 *   - Context (`msgctxt`) is NOT emitted — entries with a
 *     non-null context are serialized using the bare id, which
 *     means two entries sharing the same id but with different
 *     contexts WILL collide. PHP's last-assignment-wins semantics
 *     apply: the last entry written to the array for a given id
 *     overwrites earlier ones. Iteration order is the bag's
 *     iteration order.
 */
final class JsonSerializer implements SerializerInterface
{
    /**
     * @var int `json_encode` flags producing readable, web-safe
     *      output. See class docblock for rationale.
     */
    private const int ENCODE_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR;

    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $data = [];
        foreach ($bag->all($domain) as $entry) {
            $data[$entry->id()] = $entry->content();
        }

        if ($data === []) {
            return '{}';
        }

        try {
            return json_encode($data, self::ENCODE_FLAGS);
        } catch (JsonException $e) {
            throw new SerializerException(
                'Failed to encode translation bag as JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function format(): Format
    {
        return Format::JSON;
    }
}
