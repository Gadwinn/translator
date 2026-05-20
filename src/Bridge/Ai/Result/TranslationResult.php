<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai\Result;

use G\Translator\Enum\PluralCategory;

/**
 * Immutable result of a single translation task fulfilled by an
 * `AiProviderInterface`.
 *
 * The structure is intentionally rich enough to carry the full
 * CLDR plural variant set (when the provider supports it) while
 * remaining usable for the simple case of a single singular
 * translation.
 *
 * @see \G\Translator\Bridge\Ai\AiProviderInterface
 * @see \G\Translator\Bridge\Ai\Request\TranslationRequest
 * @see \G\Translator\Enum\PluralCategory
 */
final readonly class TranslationResult
{
    /**
     * @param string $translatedText The translated string in the
     *        target locale (singular form when plurals are
     *        involved). MAY be an empty string if the provider
     *        explicitly returned an empty translation (rare;
     *        callers SHOULD treat this as a quality signal).
     * @param array<value-of<PluralCategory>, string> $translatedPluralForms
     *        Plural variants of the translation keyed by CLDR
     *        category. Empty when the source request had no
     *        plural form or when the provider does not support
     *        plural generation. When non-empty, MUST contain at
     *        least the `OTHER` category (the universal
     *        fallback).
     * @param float|null $confidence Optional confidence score in
     *        `[0.0, 1.0]` if the provider exposes one. `null`
     *        when the provider does not report confidence.
     * @param array<non-empty-string, mixed> $metadata Free-form
     *        provider-specific data attached to the result
     *        (e.g., tokens consumed, model used, billing
     *        identifiers, detected source language).
     */
    public function __construct(
        public string $translatedText,
        public array $translatedPluralForms = [],
        public ?float $confidence = null,
        public array $metadata = [],
    ) {
    }
}
