<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bag;

/**
 * Thrown when a `TranslationEntry` is constructed (or mutated via
 * `with*()`) with arguments that violate its structural invariants.
 *
 * Typical triggers:
 * - Empty source identifier (`id`).
 * - Empty logical domain.
 * - Empty disambiguation context (must be `null` or a non-empty string).
 * - Plural forms keyed by an unknown CLDR category.
 * - Plural forms missing the mandatory `OTHER` fallback while at least
 *   one other category is present.
 * - Metadata array containing non-string keys, or empty-string keys.
 *
 * Constructing an entry is considered programmatic — these failures
 * indicate a defect in the calling code (parser, factory, or hand-built
 * entry) rather than an external runtime condition.
 */
final class InvalidEntryException extends BagException
{
}
