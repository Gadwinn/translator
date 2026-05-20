<?php

declare(strict_types=1);

namespace G\Translator\Exception\Runtime;

use G\Translator\Exception\TranslatorExceptionInterface;
use RuntimeException as BaseRuntimeException;

/**
 * Abstract base class for every exception thrown by the translation
 * runtime engine.
 *
 * Covers failures happening when resolving translations at runtime:
 * locale handling, plural resolution, fallback chain traversal, etc.
 */
abstract class RuntimeException extends BaseRuntimeException implements TranslatorExceptionInterface
{
}
