<?php

declare(strict_types=1);

namespace G\Translator\Exception\Configuration;

/**
 * Thrown when the package detects an invalid configuration state at
 * call time.
 *
 * Typical triggers:
 * - Required option is missing in a configuration array.
 * - Mutually exclusive options have been set simultaneously.
 * - A value falls outside its allowed range/enum.
 *
 * Always indicates a programming error: the consumer should fix their
 * code, not retry the operation.
 */
final class InvalidConfigurationException extends ConfigurationException
{
}
