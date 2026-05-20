<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai\Request;

use G\Translator\Bridge\Ai\Enum\TranslationQuality;

/**
 * Immutable description of a single translation task to be
 * fulfilled by an `AiProviderInterface`.
 *
 * Carries everything a provider may need to produce a high-quality
 * translation: the source text, its language, the target language,
 * an optional disambiguation context, optional plural source form
 * (when relevant), free-form metadata for custom instructions, and
 * a declarative quality hint.
 *
 * Providers MAY ignore fields they cannot exploit (e.g., DeepL
 * largely ignores `context` while LLM providers use it heavily).
 * The caller-side contract remains uniform regardless.
 *
 * @see \G\Translator\Bridge\Ai\AiProviderInterface
 * @see \G\Translator\Bridge\Ai\Result\TranslationResult
 */
final readonly class TranslationRequest
{
    /**
     * @param non-empty-string $sourceText The source string to
     *        translate (typically the singular form when plurals
     *        are involved).
     * @param non-empty-string $sourceLocale The source locale in
     *        BCP-47 form (e.g., `en`, `en-US`).
     * @param non-empty-string $targetLocale The target locale in
     *        BCP-47 form (e.g., `fr-FR`, `pl-PL`).
     * @param non-empty-string|null $sourcePluralText Optional
     *        source string in plural form (`msgid_plural` in
     *        Gettext terms). When non-null, providers capable of
     *        handling plurals SHOULD return all CLDR plural
     *        variants of the target locale in
     *        `TranslationResult::$translatedPluralForms`.
     * @param non-empty-string|null $context Optional
     *        disambiguation context (e.g., "verb", "menu item",
     *        "user-facing button label"). Helps LLM providers
     *        produce semantically correct translations.
     * @param TranslationQuality $quality Declarative hint for the
     *        speed/quality/cost trade-off. Default:
     *        `TranslationQuality::STANDARD`.
     * @param array<non-empty-string, mixed> $metadata Free-form
     *        provider-specific instructions (e.g., glossary IDs,
     *        formality level, preserve-HTML flag). Providers
     *        SHOULD document the keys they recognize and ignore
     *        unknown ones silently.
     */
    public function __construct(
        public string $sourceText,
        public string $sourceLocale,
        public string $targetLocale,
        public ?string $sourcePluralText = null,
        public ?string $context = null,
        public TranslationQuality $quality = TranslationQuality::STANDARD,
        public array $metadata = [],
    ) {
    }
}
