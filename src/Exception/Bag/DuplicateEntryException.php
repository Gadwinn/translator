<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bag;

/**
 * Thrown when a write operation would violate the uniqueness invariant
 * of the bag (same composite key: id + domain + context).
 *
 * Typical triggers:
 * - `add()` called with an entry whose key already exists, while the
 *   bag is operating in strict insertion mode.
 *
 * Merge operations (which intentionally allow overwrites) MUST NOT
 * throw this exception; they delegate collision handling to the
 * configured `MergeStrategy`.
 */
final class DuplicateEntryException extends BagException
{
}
