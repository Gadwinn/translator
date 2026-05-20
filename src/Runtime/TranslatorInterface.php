<?php

declare(strict_types=1);

namespace G\Translator\Runtime;

use G\Translator\Exception\Runtime\InvalidLocaleException;
use G\Translator\Exception\Runtime\InvalidPlaceholderValueException;
use G\Translator\Exception\Runtime\MissingTranslationException;
use G\Translator\Exception\Runtime\PluralResolutionException;
use Stringable;

/**
 * Public translation API.
 *
 * The translator is the Facade orchestrating every runtime
 * component: it queries the `BagRegistryInterface` through the
 * `LocaleFallbackInterface` chain, picks the right plural form
 * via the `PluralResolverInterface`, and hydrates the final
 * string via the `PlaceholderResolverInterface`.
 *
 * Two strict-mode toggles control failure behavior:
 *
 * - **Strict mode OFF** (default) — when a translation cannot be
 *   resolved across the entire fallback chain, the translator
 *   returns the source identifier (`$id`) or, for `plural()`,
 *   the appropriate source form (`$id` or `$plural` depending
 *   on `$count`). No exception is thrown. This is the
 *   production-friendly behavior.
 *
 * - **Strict mode ON** — the translator throws
 *   `MissingTranslationException` when a translation cannot be
 *   resolved. Intended for development and CI environments where
 *   missing translations must be surfaced loudly.
 *
 * Implementations MUST be safe to share across requests (no
 * per-request mutable state hidden in singletons). Locale changes
 * via `setLocale()` are explicit and observable.
 *
 * @see \G\Translator\Runtime\BagRegistryInterface
 * @see \G\Translator\Runtime\LocaleFallbackInterface
 * @see \G\Translator\Runtime\Plural\PluralResolverInterface
 * @see \G\Translator\Runtime\Placeholder\PlaceholderResolverInterface
 */
interface TranslatorInterface
{
    /**
     * Translates a singular message.
     *
     * Resolution algorithm:
     *   1. Walk the locale chain produced by the configured
     *      `LocaleFallbackInterface`.
     *   2. For each locale, look up an entry matching
     *      `(id, domain, context)` in the registered bag.
     *   3. First match wins; its content is hydrated with
     *      `$params` and returned.
     *   4. If no match: throw `MissingTranslationException`
     *      (strict mode ON) or return `$id` (strict mode OFF).
     *
     * @param non-empty-string $id The source identifier
     *        (`msgid` in Gettext terms).
     * @param non-empty-string $domain The logical domain to
     *        query. Default: `'messages'`.
     * @param non-empty-string|null $context Optional
     *        disambiguation context.
     * @param non-empty-string|null $locale Optional locale
     *        override for this call. `null` uses the
     *        translator's current locale.
     * @param array<int|string, scalar|Stringable> $params
     *        Placeholder values. See
     *        `PlaceholderResolverInterface` for supported
     *        syntaxes. `null` values are NOT accepted.
     *
     * @throws InvalidLocaleException When `$locale` is set and
     *         malformed.
     * @throws InvalidPlaceholderValueException When a placeholder
     *         value is `null` or cannot be coerced to a string.
     * @throws MissingTranslationException When strict mode is
     *         enabled and no translation is found.
     */
    public function trans(
        string $id,
        string $domain = 'messages',
        ?string $context = null,
        ?string $locale = null,
        array $params = [],
    ): string;

    /**
     * Translates a plural message.
     *
     * Resolution algorithm:
     *   1. Walk the locale chain.
     *   2. For each locale, look up an entry matching
     *      `(id, domain, context)`.
     *   3. On match: select the plural form using the
     *      `PluralResolverInterface` for `$count` and the
     *      current locale, then hydrate with `$params`.
     *   4. If no match: throw `MissingTranslationException`
     *      (strict mode ON) or return the source fallback
     *      (`$id` when `$count == 1`, `$plural` otherwise) —
     *      hydrated with `$params`.
     *
     * The `$plural` parameter follows Gettext semantics: it is
     * the source string in plural form, used both as a
     * disambiguator at parse time AND as a fallback when no
     * translation exists.
     *
     * **Automatic `count` injection.** Before placeholder
     * resolution, the value of `$count` is automatically injected
     * into `$params` under the `'count'` key, IF that key is not
     * already present. This lets translators use `{count}` or
     * `%count%` directly in their strings without forcing the
     * caller to repeat it:
     *
     * ```
     * $translator->plural('One apple', '{count} apples', 5);
     * // → "5 apples"
     * ```
     *
     * The auto-injection ONLY applies to named placeholder
     * syntaxes (`{count}`, `%count%`). Positional syntaxes
     * (`%s`, `%d`, `%N$d`) MUST be filled explicitly via
     * `$params`, because their indices would otherwise collide
     * with caller-provided positional values.
     *
     * If `$params` already contains a `'count'` key, the
     * caller's value takes precedence (e.g., to display a
     * formatted number distinct from the technical count).
     *
     * @param non-empty-string $id The singular source identifier
     *        (`msgid`).
     * @param non-empty-string $plural The plural source string
     *        (`msgid_plural`), used as fallback when no
     *        translation is found and `$count != 1`.
     * @param int $count The count driving plural form selection.
     *        MAY be negative or zero. Automatically injected as
     *        `params['count']` for named placeholders.
     * @param non-empty-string $domain Default: `'messages'`.
     * @param non-empty-string|null $context Optional
     *        disambiguation context.
     * @param non-empty-string|null $locale Optional locale
     *        override.
     * @param array<int|string, scalar|Stringable> $params
     *        Placeholder values. `null` is NOT accepted.
     *
     * @throws InvalidLocaleException When `$locale` is malformed.
     * @throws InvalidPlaceholderValueException When a placeholder
     *         value is `null` or cannot be coerced to a string.
     * @throws MissingTranslationException When strict mode is
     *         enabled and no translation is found.
     * @throws PluralResolutionException When the plural resolver
     *         cannot determine a category for `$count` and the
     *         active locale.
     */
    public function plural(
        string $id,
        string $plural,
        int $count,
        string $domain = 'messages',
        ?string $context = null,
        ?string $locale = null,
        array $params = [],
    ): string;

    /**
     * Sets the current locale (the primary locale used when
     * `$locale` is omitted on `trans()` / `plural()` calls).
     *
     * Equivalent to calling `setPrimary()` on the underlying
     * `LocaleFallbackInterface`, but exposed at the translator
     * level for ergonomic reasons.
     *
     * @param non-empty-string $locale
     *
     * @throws InvalidLocaleException When the locale is malformed.
     */
    public function setLocale(string $locale): void;

    /**
     * Returns the translator's current primary locale.
     *
     * @return non-empty-string
     */
    public function locale(): string;

    /**
     * Toggles strict mode on translation lookups.
     *
     * When ON, missing translations throw
     * `MissingTranslationException`. When OFF (default), they
     * silently return the source identifier (or its plural
     * fallback).
     */
    public function setStrict(bool $enabled): void;

    /**
     * Indicates whether strict mode is currently enabled.
     */
    public function isStrict(): bool;
}
