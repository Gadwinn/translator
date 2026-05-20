<?php

declare(strict_types=1);

namespace G\Translator\Bag;

use G\Translator\Enum\PluralCategory;

/**
 * Contract for a single, immutable translation entry.
 *
 * An entry represents one unit of translation as understood by
 * the package: a source identifier (`id`), its translated content,
 * optional plural forms, an optional disambiguation context, a
 * logical domain, and free-form metadata (references, comments,
 * flags, etc.).
 *
 * Implementations MUST be immutable: every "mutator" method (the
 * `with*()` family) MUST return a new instance, leaving the
 * original untouched. This guarantees that an entry, once
 * inserted into a `TranslationBag`, cannot drift from its
 * composite key (id + domain + context).
 *
 * Identity of an entry is defined by its composite key, exposed
 * via `key()`. Two entries with the same composite key are
 * considered to refer to the same translation slot (collision
 * candidates during merge operations).
 *
 * @see \G\Translator\Bag\TranslationBagInterface
 * @see \G\Translator\Enum\PluralCategory
 */
interface TranslationEntryInterface
{
    /**
     * Returns the source identifier (`msgid` in Gettext terms).
     *
     * MUST NOT be empty.
     *
     * @return non-empty-string
     */
    public function id(): string;

    /**
     * Returns the translated content (the singular form, or the
     * source string when untranslated).
     *
     * MAY be an empty string. An empty content indicates that the
     * entry is referenced (so its plural forms or metadata can be
     * inspected) but has not been translated yet. Merge strategies
     * MAY treat empty content as "missing" to allow lower-priority
     * sources to fill the gap.
     */
    public function content(): string;

    /**
     * Returns the logical domain this entry belongs to
     * (e.g., `messages`, `validators`, `admin`).
     *
     * @return non-empty-string
     */
    public function domain(): string;

    /**
     * Returns the disambiguation context, if any
     * (`msgctxt` in Gettext terms).
     *
     * Two entries sharing the same `id` and `domain` but with
     * different contexts are considered distinct. Returns `null`
     * when no context is set.
     *
     * @return non-empty-string|null
     */
    public function context(): ?string;

    /**
     * Returns the plural variants keyed by CLDR category.
     *
     * The array is empty when the entry has no plural forms.
     * When non-empty, the `PluralCategory::OTHER` key MUST be
     * present (it is the universal fallback). Implementations
     * MAY enforce this invariant in their factory methods.
     *
     * Example for English:
     * ```
     * [
     *     PluralCategory::ONE->value   => 'one apple',
     *     PluralCategory::OTHER->value => '%count% apples',
     * ]
     * ```
     *
     * @return array<value-of<PluralCategory>, string>
     */
    public function pluralForms(): array;

    /**
     * Returns format-specific metadata attached to the entry.
     *
     * Used by parsers and serializers to preserve information
     * that does not fit the canonical model: source code
     * references, translator comments, fuzzy flags, custom
     * attributes, etc.
     *
     * The structure is intentionally loose to avoid coupling the
     * core to any specific format. Each parser/serializer pair
     * defines its own metadata schema and SHOULD document it.
     *
     * @return array<non-empty-string, mixed>
     */
    public function metadata(): array;

    /**
     * Returns the composite key identifying this entry within a
     * bag.
     *
     * Format: implementation-defined, but MUST be deterministic
     * (same id + domain + context → same key) and unique within
     * the (id, domain, context) tuple. Recommended encoding:
     * `{domain}::{context}::{id}` with a sentinel for null
     * context (e.g., `{domain}::__none__::{id}`).
     *
     * @return non-empty-string
     */
    public function key(): string;

    /**
     * Indicates whether the entry carries an actual translation.
     *
     * `true` when `content()` is empty AND `pluralForms()` is
     * empty (or contains only empty strings). Used by merge
     * strategies to decide whether an entry should be skipped or
     * overridden.
     */
    public function isEmpty(): bool;

    /**
     * Returns a copy of this entry with the given content.
     *
     * @param string $content The new content (may be empty).
     */
    public function withContent(string $content): self;

    /**
     * Returns a copy of this entry with the given plural forms.
     *
     * @param array<value-of<PluralCategory>, string> $pluralForms
     *        The plural variants keyed by CLDR category. Passing
     *        an empty array clears all plural forms.
     */
    public function withPluralForms(array $pluralForms): self;

    /**
     * Returns a copy of this entry with the given metadata.
     *
     * Replaces the entire metadata array (no merge). To extend
     * existing metadata, retrieve `metadata()` first, merge
     * externally, then pass the result.
     *
     * @param array<non-empty-string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self;
}
