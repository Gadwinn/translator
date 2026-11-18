<?php

declare(strict_types=1);

namespace G\Translator\Io\Parser;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Source\SourceInterface;
use Throwable;

/**
 * `ParserInterface` implementation for PHP files that `return`
 * a flat `array<string, string>` (or a namespaced nested array).
 *
 * Canonical shape:
 * ```php
 * <?php
 *
 * return [
 *     'hello'   => 'Hello',
 *     'app' => [
 *         'title' => 'Title',
 *     ],
 * ];
 * ```
 *
 * The parser writes the source content to a unique temporary file
 * (under `sys_get_temp_dir()`), `require`s it, captures the
 * returned array, and removes the file. Unique tmpnames avoid
 * OPcache hits between consecutive parses.
 *
 * Security: parsing a PHP file means EXECUTING that file. This
 * matches the Symfony / Laravel / WordPress conventions for PHP
 * translation files. Consumers MUST treat PHP source files as
 * trusted code (the same way they treat their own classes); never
 * pass content received from untrusted users to this parser.
 *
 * Nested arrays are flattened to dotted keys, matching `JsonParser`.
 * Non-string leaves and non-array returns raise `ParserException`.
 */
final class PhpParser implements ParserInterface
{
    /**
     * @var non-empty-string Dot used to join nested keys.
     */
    private const string KEY_SEPARATOR = '.';

    /**
     * @var non-empty-string Prefix for the temporary file used to
     *      sandbox `require`.
     */
    private const string TMP_PREFIX = 'translator-php-';

    public function parse(
        SourceInterface $source,
        string $locale,
        string $domain = 'messages',
    ): TranslationBagInterface {
        $bag = new TranslationBag($locale);
        $content = $source->read();
        if (trim($content) === '') {
            return $bag;
        }

        $data = $this->evaluateContent($content, $source->identifier());
        if (!is_array($data)) {
            throw new ParserException(sprintf(
                'PHP source "%s" must `return` an array; got %s.',
                $source->identifier(),
                get_debug_type($data),
            ));
        }

        foreach ($this->flatten($data, '', $source->identifier()) as $key => $value) {
            $bag->add(new TranslationEntry(id: $key, content: $value, domain: $domain));
        }

        return $bag;
    }

    public function format(): Format
    {
        return Format::PHP;
    }

    private function evaluateContent(string $content, string $sourceIdentifier): mixed
    {
        $tmpFile = tempnam(sys_get_temp_dir(), self::TMP_PREFIX);
        if ($tmpFile === false) {
            throw new ParserException(sprintf(
                'PHP source "%s": failed to allocate a temporary file for evaluation.',
                $sourceIdentifier,
            ));
        }

        $tmpWithSuffix = $tmpFile . '.php';
        if (!rename($tmpFile, $tmpWithSuffix)) {
            @unlink($tmpFile);
            throw new ParserException(sprintf(
                'PHP source "%s": failed to prepare temporary file "%s".',
                $sourceIdentifier,
                $tmpWithSuffix,
            ));
        }

        $bytesWritten = file_put_contents($tmpWithSuffix, $content);
        if ($bytesWritten === false) {
            @unlink($tmpWithSuffix);
            throw new ParserException(sprintf(
                'PHP source "%s": failed to write temporary file "%s".',
                $sourceIdentifier,
                $tmpWithSuffix,
            ));
        }

        try {
            /** @var mixed $data */
            $data = require $tmpWithSuffix;
        } catch (Throwable $e) {
            @unlink($tmpWithSuffix);
            throw new ParserException(sprintf(
                'PHP source "%s" raised an error during evaluation: %s',
                $sourceIdentifier,
                $e->getMessage(),
            ), 0, $e);
        }

        @unlink($tmpWithSuffix);

        return $data;
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
                    'PHP source "%s" contains an empty key under prefix "%s".',
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
                'PHP source "%s" has unsupported leaf type "%s" at key "%s".',
                $sourceIdentifier,
                get_debug_type($value),
                $fullKey,
            ));
        }
    }
}
