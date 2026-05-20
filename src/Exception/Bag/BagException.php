<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bag;

use G\Translator\Exception\TranslatorExceptionInterface;
use RuntimeException;

/**
 * Abstract base class for every exception thrown by the in-memory
 * `TranslationBag` and its related collaborators.
 *
 * Covers operational failures during data access, mutation, hydration,
 * and iteration over translation entries.
 */
abstract class BagException extends RuntimeException implements TranslatorExceptionInterface
{
}
