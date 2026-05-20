<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bag;

/**
 * Thrown when an entry lookup in a `TranslationBag` fails to locate
 * any matching record.
 *
 * Used only by strict accessor methods (e.g., `get()`, `require()`).
 * Lenient accessors (e.g., `find()`) MUST return `null` instead of
 * throwing.
 */
final class EntryNotFoundException extends BagException
{
}
