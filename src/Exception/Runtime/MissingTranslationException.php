<?php

declare(strict_types=1);

namespace G\Translator\Exception\Runtime;

/**
 * Thrown when a translation key cannot be resolved across the entire
 * fallback chain, while the runtime is operating in strict mode.
 *
 * In lenient mode (the default), missing translations MUST NOT throw;
 * the runtime returns the original key (or a configured fallback value)
 * instead.
 */
final class MissingTranslationException extends RuntimeException
{
}
