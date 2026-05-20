<?php

declare(strict_types=1);

namespace G\Translator\Exception\Configuration;

use G\Translator\Exception\TranslatorExceptionInterface;
use LogicException;

/**
 * Abstract base class for every exception caused by an incorrect
 * programmatic use of the package's API.
 *
 * Extends \LogicException because configuration errors are, by nature,
 * detectable at development time (they reflect a misuse of the API
 * rather than an environmental condition).
 *
 * Examples of conditions belonging to this category:
 * - Building a `Translator` without registering at least one source.
 * - Passing an unsupported strategy class to a registry.
 * - Calling a fluent setter with an obviously invalid argument
 *   (e.g., negative priority).
 */
abstract class ConfigurationException extends LogicException implements TranslatorExceptionInterface
{
}
