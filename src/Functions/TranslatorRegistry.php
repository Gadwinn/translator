<?php

declare(strict_types=1);

namespace G\Translator\Functions;

use G\Translator\Exception\Configuration\InvalidConfigurationException;
use G\Translator\Runtime\TranslatorInterface;

/**
 * Static accessor used by the global `__()` function family to
 * locate a `TranslatorInterface` instance at call time.
 *
 * This class is INTENTIONALLY the only piece of global state in
 * the entire package. It exists for one purpose only: to back the
 * opt-in procedural API exposed in `Functions/translate.php`.
 *
 * The object-oriented core of the package (everything outside
 * `Functions/`) does NOT reference this class and has no global
 * state. Consumers who never `require` the procedural API also
 * never instantiate or call this registry.
 *
 * Multi-instance scenarios are supported via NAMED scopes: an
 * application MAY register several translators (e.g., one for
 * its public site, one for its admin back-office) and switch
 * the active scope at runtime. The `__*()` functions always
 * read the currently active scope.
 *
 * Thread-safety: PHP request handlers are single-threaded by
 * default; this registry is safe within a single request. Async
 * runtimes (Swoole, ReactPHP) MUST avoid sharing a single
 * registry across coroutines; in such environments the
 * procedural API SHOULD NOT be used.
 */
final class TranslatorRegistry
{
    /**
     * The default translator returned when no scope is active.
     */
    private static ?TranslatorInterface $default = null;

    /**
     * Named translators registered under non-default scopes.
     *
     * @var array<non-empty-string, TranslatorInterface>
     */
    private static array $scoped = [];

    /**
     * The name of the currently active scope, or `null` when the
     * default translator is active.
     *
     * @var non-empty-string|null
     */
    private static ?string $activeScope = null;

    /**
     * Private constructor: this class is never instantiated.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Registers the default translator used when no scope is
     * active.
     *
     * Calling this method a second time replaces the previous
     * default. This is intentional (e.g., to hot-swap the
     * translator during tests or after configuration reload).
     */
    public static function setDefault(TranslatorInterface $translator): void
    {
        self::$default = $translator;
    }

    /**
     * Registers a named translator under the given scope.
     *
     * @param non-empty-string $scope
     */
    public static function register(string $scope, TranslatorInterface $translator): void
    {
        self::$scoped[$scope] = $translator;
    }

    /**
     * Activates the given scope so that subsequent `__*()` calls
     * use the corresponding translator.
     *
     * Passing `null` reverts to the default translator.
     *
     * @param non-empty-string|null $scope
     *
     * @throws InvalidConfigurationException When `$scope` is not
     *         `null` and refers to an unregistered scope.
     */
    public static function use(?string $scope): void
    {
        if ($scope !== null && !isset(self::$scoped[$scope])) {
            throw new InvalidConfigurationException(
                sprintf('No translator registered under scope "%s".', $scope),
            );
        }

        self::$activeScope = $scope;
    }

    /**
     * Returns the currently active translator.
     *
     * Resolution order:
     *   1. The translator registered under the active scope, if
     *      `use()` has been called with a non-null value.
     *   2. The default translator otherwise.
     *
     * @throws InvalidConfigurationException When no default
     *         translator has been registered and no scope is
     *         currently active.
     */
    public static function current(): TranslatorInterface
    {
        if (self::$activeScope !== null) {
            return self::$scoped[self::$activeScope];
        }

        if (self::$default === null) {
            throw new InvalidConfigurationException(
                'No translator registered. '
                    . 'Call TranslatorRegistry::setDefault() before using the global __*() functions.',
            );
        }

        return self::$default;
    }

    /**
     * Resets the registry to its initial empty state.
     *
     * Intended primarily for test suites that need to isolate
     * each test from the others. Production code SHOULD NOT call
     * this method.
     */
    public static function reset(): void
    {
        self::$default = null;
        self::$scoped = [];
        self::$activeScope = null;
    }
}
