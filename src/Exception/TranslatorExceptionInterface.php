<?php

declare(strict_types=1);

namespace G\Translator\Exception;

use Throwable;

/**
 * Root contract for every exception thrown by the G\Translator package.
 *
 * This is a pure marker interface (no methods defined). It exists so that
 * consumers of the library can catch ANY exception originating from the
 * package with a single, stable type — without having to know about the
 * concrete exception classes:
 *
 * ```php
 * try {
 *     // any Translator operation
 * } catch (TranslatorExceptionInterface $e) {
 *     // catches ParserException, SerializerException, MergeException, etc.
 * }
 * ```
 *
 * Implementation contract for the package's internal exceptions:
 * - MUST extend either \RuntimeException (for runtime/environmental failures)
 *   or \LogicException (for programming errors detectable at dev-time).
 * - MUST be `final` when concrete (only abstract category classes may be
 *   extended internally).
 * - SHOULD be thrown with a human-readable message describing the failure
 *   in operational terms (file path, locale, key, etc.).
 *
 * @see https://www.php.net/manual/en/class.throwable.php
 */
interface TranslatorExceptionInterface extends Throwable
{
}
