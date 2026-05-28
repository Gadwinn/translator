<?php

declare(strict_types=1);

namespace G\Translator\Runtime;

use G\Translator\Exception\Runtime\InvalidLocaleException;

/**
 * Pure utility responsible for normalizing locale identifiers to a
 * canonical BCP-47 form and computing parent locales.
 *
 * The normalizer is the single source of truth for locale string
 * handling across the package: `LocaleFallback`, `BagRegistry`, and
 * the runtime translator MUST delegate to it. Centralising the rules
 * here guarantees that `pl_PL`, `pl-pl`, and `PL-PL` are always
 * resolved to the same canonical key (`pl-PL`).
 *
 * Normalization rules (canonical BCP-47):
 *   - Language subtag: 2 or 3 ASCII letters, lower-cased.
 *   - Script subtag (optional): exactly 4 ASCII letters, Title-cased.
 *   - Region subtag (optional): 2 ASCII letters (upper-cased) OR
 *     3 ASCII digits (kept as-is).
 *   - Separator: hyphen `-` (canonical) — underscores `_` are accepted
 *     on input and converted on output.
 *
 * Inputs containing whitespace, non-ASCII characters, malformed
 * subtags, or unsupported tag combinations are rejected with
 * `InvalidLocaleException`.
 *
 * This class carries no state. All methods are static and pure;
 * calling them is referentially transparent.
 */
final class LocaleNormalizer
{
    /**
     * @var int Length of a script subtag (BCP-47 grammar).
     */
    private const int SCRIPT_SUBTAG_LENGTH = 4;

    /**
     * @var int Length of a region subtag expressed as letters.
     */
    private const int REGION_LETTER_LENGTH = 2;

    /**
     * @var int Length of a region subtag expressed as digits.
     */
    private const int REGION_DIGIT_LENGTH = 3;

    /**
     * @var int Maximum number of subtags supported (language, script, region).
     */
    private const int MAX_SUBTAGS = 3;

    /**
     * Private constructor: this class is a static utility and is
     * never instantiated.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Returns the canonical BCP-47 representation of a locale string.
     *
     * Accepts both hyphen and underscore separators on input.
     * Produces a string of the form `xx`, `xx-YY`, `xx-Yyyy`, or
     * `xx-Yyyy-ZZ` depending on the subtags present.
     *
     * @param string $locale The locale string to normalize.
     *
     * @return non-empty-string The canonical locale.
     *
     * @throws InvalidLocaleException When the input is empty, contains
     *         invalid characters, or cannot be parsed as BCP-47.
     */
    public static function normalize(string $locale): string
    {
        if ($locale === '') {
            throw new InvalidLocaleException('Locale string must not be empty.');
        }

        if (preg_match('/^[A-Za-z0-9_\-]+$/', $locale) !== 1) {
            throw new InvalidLocaleException(sprintf(
                'Locale "%s" contains invalid characters; only ASCII letters, digits, "-" and "_" are allowed.',
                $locale,
            ));
        }

        $parts = preg_split('/[_\-]/', $locale) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        if ($parts === [] || count($parts) > self::MAX_SUBTAGS) {
            throw new InvalidLocaleException(sprintf(
                'Locale "%s" does not match the supported BCP-47 grammar (language[-script][-region]).',
                $locale,
            ));
        }

        $language = self::normalizeLanguage($parts[0], $locale);
        $canonical = $language;

        $remaining = array_slice($parts, 1);
        $script = null;
        $region = null;

        foreach ($remaining as $subtag) {
            if ($script === null && self::looksLikeScript($subtag)) {
                $script = self::normalizeScript($subtag);
                continue;
            }

            if ($region === null && self::looksLikeRegion($subtag)) {
                $region = self::normalizeRegion($subtag);
                continue;
            }

            throw new InvalidLocaleException(sprintf(
                'Locale "%s" contains an unrecognized subtag "%s".',
                $locale,
                $subtag,
            ));
        }

        if ($script !== null) {
            $canonical .= '-' . $script;
        }

        if ($region !== null) {
            $canonical .= '-' . $region;
        }

        return $canonical;
    }

    /**
     * Returns the parent locale by dropping the rightmost subtag.
     *
     * Examples:
     *   - `fr-FR`       → `fr`
     *   - `zh-Hant-TW`  → `zh-Hant`
     *   - `zh-Hant`     → `zh`
     *   - `fr`          → `null`
     *
     * @param non-empty-string $locale A locale already in canonical
     *        form (i.e., the output of `normalize()`).
     *
     * @return non-empty-string|null The parent, or `null` when the
     *         locale has no parent (single language subtag).
     */
    public static function parent(string $locale): ?string
    {
        $position = strrpos($locale, '-');
        if ($position === false) {
            return null;
        }

        $parent = substr($locale, 0, $position);
        if ($parent === '') {
            return null;
        }

        return $parent;
    }

    /**
     * @return non-empty-string
     */
    private static function normalizeLanguage(string $subtag, string $original): string
    {
        if (preg_match('/^[A-Za-z]{2,3}$/', $subtag) !== 1) {
            throw new InvalidLocaleException(sprintf(
                'Locale "%s" has an invalid language subtag "%s"; expected 2 or 3 ASCII letters.',
                $original,
                $subtag,
            ));
        }

        $normalized = strtolower($subtag);
        if ($normalized === '') {
            throw new InvalidLocaleException(sprintf(
                'Locale "%s" produced an empty normalized language subtag.',
                $original,
            ));
        }

        return $normalized;
    }

    private static function looksLikeScript(string $subtag): bool
    {
        return strlen($subtag) === self::SCRIPT_SUBTAG_LENGTH
            && preg_match('/^[A-Za-z]+$/', $subtag) === 1;
    }

    private static function normalizeScript(string $subtag): string
    {
        return ucfirst(strtolower($subtag));
    }

    private static function looksLikeRegion(string $subtag): bool
    {
        $length = strlen($subtag);
        if ($length === self::REGION_LETTER_LENGTH) {
            return preg_match('/^[A-Za-z]+$/', $subtag) === 1;
        }

        if ($length === self::REGION_DIGIT_LENGTH) {
            return preg_match('/^[0-9]+$/', $subtag) === 1;
        }

        return false;
    }

    private static function normalizeRegion(string $subtag): string
    {
        if (preg_match('/^[A-Za-z]+$/', $subtag) === 1) {
            return strtoupper($subtag);
        }

        return $subtag;
    }
}
