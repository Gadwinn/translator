<?php

declare(strict_types=1);

namespace G\Translator\Runtime;

use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Runtime\MissingTranslationException;
use G\Translator\Runtime\Placeholder\DefaultPlaceholderResolver;
use G\Translator\Runtime\Placeholder\PlaceholderResolverInterface;
use G\Translator\Runtime\Plural\CldrPluralResolver;
use G\Translator\Runtime\Plural\CompositePluralResolver;
use G\Translator\Runtime\Plural\GettextExpressionPluralResolver;
use G\Translator\Runtime\Plural\PluralResolverInterface;
use Stringable;

/**
 * Canonical implementation of `TranslatorInterface`.
 *
 * The translator is a thin orchestrator: it walks the locale chain
 * exposed by the injected `LocaleFallbackInterface`, queries the
 * `BagRegistryInterface` for each locale, picks plural forms via
 * the `PluralResolverInterface`, and hydrates the final string via
 * the `PlaceholderResolverInterface`. No translation logic lives
 * here that is not orchestration.
 *
 * Default dependencies (used when the constructor's optional
 * arguments are omitted):
 *   - `LocaleFallback` with primary `en`.
 *   - `CompositePluralResolver` wrapping a fresh
 *     `GettextExpressionPluralResolver` and `CldrPluralResolver`.
 *   - `DefaultPlaceholderResolver`.
 *
 * State carried by the translator (current locale, strict mode) is
 * intentionally explicit and observable through the public API.
 */
final class Translator implements TranslatorInterface
{
    /**
     * @var non-empty-string Sentinel placeholder name auto-injected
     *      by `plural()` when applicable.
     */
    private const string COUNT_PLACEHOLDER_KEY = 'count';

    /**
     * @var non-empty-string Default domain used by `trans()` and
     *      `plural()` when the caller omits the `$domain` argument.
     */
    private const string DEFAULT_DOMAIN = 'messages';

    private bool $strict = false;

    private LocaleFallbackInterface $fallback;

    public function __construct(
        private readonly BagRegistryInterface $registry,
        ?LocaleFallbackInterface $fallback = null,
        private readonly PluralResolverInterface $pluralResolver = new CompositePluralResolver(
            new GettextExpressionPluralResolver(),
            new CldrPluralResolver(),
        ),
        private readonly PlaceholderResolverInterface $placeholderResolver = new DefaultPlaceholderResolver(),
    ) {
        $this->fallback = $fallback ?? new LocaleFallback();
    }

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    public function trans(
        string $id,
        string $domain = self::DEFAULT_DOMAIN,
        ?string $context = null,
        ?string $locale = null,
        array $params = [],
    ): string {
        $entry = $this->findEntry($id, $domain, $context, $locale);

        if ($entry !== null) {
            return $this->placeholderResolver->resolve($entry->content(), $params);
        }

        if ($this->strict) {
            throw new MissingTranslationException(sprintf(
                'No translation found for id "%s" (domain "%s", context "%s") across the resolution chain.',
                $id,
                $domain,
                $context ?? '<none>',
            ));
        }

        return $this->placeholderResolver->resolve($id, $params);
    }

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    public function plural(
        string $id,
        string $plural,
        int $count,
        string $domain = self::DEFAULT_DOMAIN,
        ?string $context = null,
        ?string $locale = null,
        array $params = [],
    ): string {
        $effectiveLocale = $locale !== null
            ? LocaleNormalizer::normalize($locale)
            : $this->fallback->primary();

        $found = $this->locateEntry($id, $domain, $context, $effectiveLocale);
        $form = $found !== null ? $this->selectPluralForm($found[0], $count, $found[1]) : null;

        if ($form !== null && $form !== '') {
            $params = $this->injectCountIfNeeded($form, $count, $params);
            return $this->placeholderResolver->resolve($form, $params);
        }

        if ($this->strict) {
            throw new MissingTranslationException(sprintf(
                'No plural translation found for id "%s" / plural "%s" (count %d, locale "%s").',
                $id,
                $plural,
                $count,
                $effectiveLocale,
            ));
        }

        $fallbackForm = $count === 1 ? $id : $plural;
        $params = $this->injectCountIfNeeded($fallbackForm, $count, $params);

        return $this->placeholderResolver->resolve($fallbackForm, $params);
    }

    public function setLocale(string $locale): void
    {
        $this->fallback->setPrimary($locale);
    }

    public function locale(): string
    {
        return $this->fallback->primary();
    }

    public function setStrict(bool $enabled): void
    {
        $this->strict = $enabled;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Walks the resolution chain and returns the first matching
     * entry, or `null` when none was found.
     *
     * @param non-empty-string $id
     * @param non-empty-string $domain
     * @param non-empty-string|null $context
     * @param non-empty-string|null $locale Explicit override; when
     *        non-null, replaces the configured primary while
     *        preserving the explicit chain and auto-parent setting.
     */
    private function findEntry(
        string $id,
        string $domain,
        ?string $context,
        ?string $locale,
    ): ?TranslationEntryInterface {
        return $this->locateEntry($id, $domain, $context, $locale)[0] ?? null;
    }

    /**
     * Same walk as {@see findEntry()}, also returning the locale of the bag
     * the entry was found in: a plural form must be selected with the rules
     * of that locale, not those of the requested one (an English fallback
     * entry has no "one" form for 0, which French puts in "one").
     *
     * @param non-empty-string $id
     * @param non-empty-string $domain
     * @param non-empty-string|null $context
     * @param non-empty-string|null $locale
     *
     * @return array{0: TranslationEntryInterface, 1: non-empty-string}|null
     */
    private function locateEntry(
        string $id,
        string $domain,
        ?string $context,
        ?string $locale,
    ): ?array {
        $chain = $this->resolveChain($locale);

        foreach ($chain as $candidate) {
            $bag = $this->registry->find($candidate);
            if ($bag === null) {
                continue;
            }
            $entry = $bag->find($id, $domain, $context);
            if ($entry !== null) {
                return [$entry, $candidate];
            }
        }

        return null;
    }

    /**
     * @param non-empty-string|null $locale
     *
     * @return non-empty-list<non-empty-string>
     */
    private function resolveChain(?string $locale): array
    {
        if ($locale === null) {
            return $this->fallback->chain();
        }

        $override = clone $this->fallback;
        $override->setPrimary($locale);

        return $override->chain();
    }

    /**
     * @param non-empty-string $locale
     */
    private function selectPluralForm(
        TranslationEntryInterface $entry,
        int $count,
        string $locale,
    ): ?string {
        $forms = $entry->pluralForms();
        if ($forms === []) {
            return $entry->content() !== '' ? $entry->content() : null;
        }

        $category = $this->pluralResolver->resolve($count, $locale);
        $key = $category->value;
        if (isset($forms[$key]) && $forms[$key] !== '') {
            return $forms[$key];
        }

        $otherKey = PluralCategory::OTHER->value;
        if (isset($forms[$otherKey]) && $forms[$otherKey] !== '') {
            return $forms[$otherKey];
        }

        return null;
    }

    /**
     * Auto-injects the count into the `'count'` key of `$params`
     * when (and only when) the resolved content references it via
     * a named placeholder syntax (`{count}` or `%count%`) AND the
     * caller has not already provided a `'count'` key.
     *
     * Positional placeholders (`%s`, `%d`, `%N$d`) are intentionally
     * NEVER auto-filled: their indices would collide with
     * caller-provided positional values.
     *
     * @param array<int|string, scalar|Stringable> $params
     *
     * @return array<int|string, scalar|Stringable>
     */
    private function injectCountIfNeeded(string $content, int $count, array $params): array
    {
        if (array_key_exists(self::COUNT_PLACEHOLDER_KEY, $params)) {
            return $params;
        }

        if (
            str_contains($content, '{' . self::COUNT_PLACEHOLDER_KEY . '}')
            || str_contains($content, '%' . self::COUNT_PLACEHOLDER_KEY . '%')
        ) {
            $params[self::COUNT_PLACEHOLDER_KEY] = $count;
        }

        return $params;
    }
}
