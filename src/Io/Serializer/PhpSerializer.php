<?php

declare(strict_types=1);

namespace G\Translator\Io\Serializer;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\Format;

/**
 * `SerializerInterface` implementation producing a PHP file that
 * returns a flat `array<string, string>` of translations.
 *
 * Output template:
 * ```php
 * <?php
 *
 * declare(strict_types=1);
 *
 * return array (
 *   'key1' => 'value1',
 *   ...
 * );
 * ```
 *
 * The exported array uses `var_export` for safety — every string
 * is properly escaped using PHP's native single-quote literal
 * syntax. The output is byte-identical for two bags with the same
 * entries in the same order.
 *
 * Limitations match the JSON family:
 *
 *   - Plural forms are NOT emitted (only `content()` is exported).
 *   - Metadata is NOT emitted.
 *   - Context-disambiguated entries collide; the first one written
 *     wins.
 */
final class PhpSerializer implements SerializerInterface
{
    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $data = [];
        foreach ($bag->all($domain) as $entry) {
            $data[$entry->id()] = $entry->content();
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";
    }

    public function format(): Format
    {
        return Format::PHP;
    }
}
