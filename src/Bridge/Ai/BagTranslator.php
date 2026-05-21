<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Request\TranslationRequest;

/**
 * Canonical implementation of `BagTranslatorInterface`.
 *
 * Iterates the source bag, builds one `TranslationRequest` per
 * non-empty entry, submits them to the provider via
 * `translateBatch()`, and hydrates a fresh target bag with the
 * resulting translations. The source bag is NEVER mutated.
 *
 * ## Source-text selection
 *
 * For each entry, the source text is `content()` when non-empty,
 * else `id()`. This handles both common shapes:
 *
 *   - PO source bags where `id()` == `content()` (the English
 *     msgid is also the msgstr in the source-language file).
 *   - Key/value bags (JSON, YAML, PHP) where `id()` is a key like
 *     `"app.title"` and `content()` is the actual source text.
 *
 * Entries with both an empty `id()` and empty `content()` cannot
 * happen (the entry constructor rejects empty ids), so this rule
 * is exhaustive.
 *
 * ## Untranslated-source policy
 *
 * Entries whose effective source text is empty are SKIPPED — they
 * are not present in the returned bag. This matches the
 * interface's documented "either omitted or empty content" policy.
 *
 * ## Metadata propagation
 *
 * Each source entry's metadata is copied verbatim onto the
 * corresponding target entry, with one exception: the `po-header`
 * key is dropped (the header belongs to the source-language file,
 * not the translated one). The PO serializer will regenerate it
 * if needed.
 *
 * The provider's per-result metadata (model, usage, …) is
 * INTENTIONALLY NOT propagated into the target entry — it's a
 * telemetry channel, not a translation artifact. Consumers
 * interested in usage stats SHOULD call `translateBatch()`
 * directly.
 */
final readonly class BagTranslator implements BagTranslatorInterface
{
    public function __construct(
        private AiProviderInterface $provider,
    ) {
    }

    public function provider(): AiProviderInterface
    {
        return $this->provider;
    }

    /**
     * @param array<non-empty-string, mixed> $metadata
     */
    public function translateBag(
        TranslationBagInterface $source,
        string $targetLocale,
        TranslationQuality $quality = TranslationQuality::STANDARD,
        array $metadata = [],
    ): TranslationBagInterface {
        $target = new TranslationBag($targetLocale);
        if ($source->count() === 0) {
            return $target;
        }

        /** @var array<non-empty-string, TranslationRequest> $requests */
        $requests = [];
        /** @var array<non-empty-string, TranslationEntryInterface> $entryMap */
        $entryMap = [];

        foreach ($source as $entry) {
            $sourceText = $this->resolveSourceText($entry);
            if ($sourceText === '') {
                continue;
            }

            $request = new TranslationRequest(
                sourceText:        $sourceText,
                sourceLocale:      $source->locale(),
                targetLocale:      $targetLocale,
                sourcePluralText:  $this->resolvePluralSource($entry),
                context:           $entry->context(),
                quality:           $quality,
                metadata:          $metadata,
            );

            $key = $entry->key();
            $requests[$key] = $request;
            $entryMap[$key] = $entry;
        }

        if ($requests === []) {
            return $target;
        }

        $results = $this->provider->translateBatch($requests);

        foreach ($results as $key => $result) {
            if (!isset($entryMap[$key])) {
                continue;
            }
            $sourceEntry = $entryMap[$key];

            $target->add(new TranslationEntry(
                id: $sourceEntry->id(),
                content: $result->translatedText,
                domain: $sourceEntry->domain(),
                context: $sourceEntry->context(),
                pluralForms: $result->translatedPluralForms,
                metadata: $this->propagatedMetadata($sourceEntry),
            ));
        }

        return $target;
    }

    private function resolveSourceText(TranslationEntryInterface $entry): string
    {
        return $entry->content() !== '' ? $entry->content() : $entry->id();
    }

    /**
     * @return non-empty-string|null
     */
    private function resolvePluralSource(TranslationEntryInterface $entry): ?string
    {
        $candidate = $entry->metadata()['msgid_plural'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        return null;
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    private function propagatedMetadata(TranslationEntryInterface $sourceEntry): array
    {
        $metadata = $sourceEntry->metadata();
        unset($metadata['po-header']);

        return $metadata;
    }
}
