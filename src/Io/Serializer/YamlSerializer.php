<?php

declare(strict_types=1);

namespace G\Translator\Io\Serializer;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\Format;

/**
 * `SerializerInterface` implementation producing the YAML subset
 * understood by `YamlParser`.
 *
 * Output rules:
 *
 *   - Every entry is emitted as a single `key: "value"` line.
 *   - Values are ALWAYS double-quoted to avoid YAML's implicit
 *     typing rules (`yes`, `true`, `1.0`, `null`, …). Double
 *     quotes are escaped using the same five escapes the parser
 *     understands (`\\`, `\"`, `\n`, `\t`, `\r`).
 *   - Keys are emitted FLAT (dotted ids stay as dotted ids). Round
 *     tripping is consistent with the parser, which flattens
 *     nested structures.
 *
 * Limitations match the JSON family: no plurals, no metadata, no
 * context.
 */
final class YamlSerializer implements SerializerInterface
{
    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $lines = [];
        foreach ($bag->all($domain) as $entry) {
            $lines[] = $entry->id() . ': "' . $this->escape($entry->content()) . '"';
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    public function format(): Format
    {
        return Format::YAML;
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
