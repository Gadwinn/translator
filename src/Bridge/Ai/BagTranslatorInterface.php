<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Exception\Bridge\AiAuthenticationException;
use G\Translator\Exception\Bridge\AiProviderException;
use G\Translator\Exception\Bridge\AiRateLimitException;

/**
 * End-to-end orchestrator that translates an entire
 * `TranslationBag` using an `AiProviderInterface`.
 *
 * This is the convenience layer making the package "turnkey":
 * the typical workflow `parse source file → translate → serialize
 * target file` is reduced to a single call to `translateBag()`.
 *
 * The orchestrator's responsibilities:
 *   1. Iterate the entries of the source bag.
 *   2. Build one `TranslationRequest` per entry, carrying the
 *      source text, plural form if any, context, and any custom
 *      metadata derived from the entry.
 *   3. Submit them to the provider in efficient batches via
 *      `AiProviderInterface::translateBatch()`.
 *   4. Hydrate a new target bag with the resulting translations
 *      (including all CLDR plural forms when the provider
 *      returned them).
 *   5. Return the populated target bag, ready for serialization.
 *
 * The orchestrator MUST NOT mutate the source bag. It produces a
 * NEW bag bound to the target locale.
 *
 * Implementations MAY:
 * - Skip entries already translated in the source bag (i.e.,
 *   non-empty content) when configured to do so.
 * - Apply per-batch size limits to fit provider constraints.
 * - Preserve entry metadata (PO references, comments, flags) by
 *   copying it from source to target entries.
 *
 * @see \G\Translator\Bridge\Ai\AiProviderInterface
 * @see \G\Translator\Bag\TranslationBagInterface
 */
interface BagTranslatorInterface
{
    /**
     * Translates an entire source bag into the given target
     * locale, returning a new populated bag.
     *
     * The source bag's locale is treated as the source language;
     * `$targetLocale` is the destination. The returned bag's
     * `locale()` MUST equal `$targetLocale`.
     *
     * @param TranslationBagInterface $source The bag to translate
     *        from. Not mutated.
     * @param non-empty-string $targetLocale The target locale in
     *        BCP-47 form (e.g., `fr-FR`, `pl-PL`).
     * @param TranslationQuality $quality Declarative quality hint
     *        propagated to every issued `TranslationRequest`.
     *        Default: `TranslationQuality::STANDARD`.
     * @param array<non-empty-string, mixed> $metadata Free-form
     *        metadata propagated to every issued
     *        `TranslationRequest` (e.g., glossary IDs, formality
     *        flags). Per-entry metadata derived from individual
     *        entries MAY be merged on top.
     *
     * @return TranslationBagInterface A new bag bound to
     *         `$targetLocale`, populated with the translated
     *         entries. Entries that could not be translated
     *         (skipped or failed in a tolerant batch) are either
     *         omitted or carried over with empty content,
     *         depending on the implementation's policy (which
     *         MUST be documented).
     *
     * @throws AiAuthenticationException Propagated from the
     *         provider.
     * @throws AiRateLimitException Propagated from the provider.
     * @throws AiProviderException Propagated from the provider.
     */
    public function translateBag(
        TranslationBagInterface $source,
        string $targetLocale,
        TranslationQuality $quality = TranslationQuality::STANDARD,
        array $metadata = [],
    ): TranslationBagInterface;

    /**
     * Returns the AI provider used by this orchestrator.
     *
     * Exposed for diagnostic and composition purposes (e.g., a
     * caller might want to inspect or temporarily swap the
     * provider).
     */
    public function provider(): AiProviderInterface;
}
