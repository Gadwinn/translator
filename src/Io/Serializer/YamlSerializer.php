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
 *     typing rules (`yes`, `true`, `1.0`, `null`, …). Backslash,
 *     double quote, `\n`, `\t`, `\r` use their short escapes; the
 *     other control characters are written `\xHH`.
 *   - Keys are emitted FLAT (dotted ids stay as dotted ids). Round
 *     tripping is consistent with the parser, which flattens
 *     nested structures. A key that is not a plain identifier
 *     (letters, digits, `_`, `.`, `-`, starting with a letter or
 *     `_`), or that YAML would type (`yes`, `null`…), is
 *     double-quoted too.
 *
 * Limitations match the JSON family: no plurals, no metadata, no
 * context.
 */
final class YamlSerializer implements SerializerInterface
{
    /**
     * @var list<string> Plain words YAML 1.1 parsers type as booleans
     *      or null; quoted when used as keys.
     */
    private const array TYPED_WORDS = ['y', 'n', 'yes', 'no', 'true', 'false', 'on', 'off', 'null'];

    /**
     * @var int First printable ASCII character (space).
     */
    private const int FIRST_PRINTABLE = 0x20;

    /**
     * @var int ASCII DEL control character.
     */
    private const int DELETE = 0x7F;

    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $lines = [];
        foreach ($bag->all($domain) as $entry) {
            $lines[] = $this->key($entry->id()) . ': "' . $this->escape($entry->content()) . '"';
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

    private function key(string $id): string
    {
        $plain = preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/D', $id) === 1
            && !in_array(strtolower($id), self::TYPED_WORDS, true);

        return $plain ? $id : '"' . $this->escape($id) . '"';
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
                default => ord($char) < self::FIRST_PRINTABLE || ord($char) === self::DELETE
                    ? sprintf('\\x%02X', ord($char))
                    : $char,
            };
        }

        return $result;
    }
}
