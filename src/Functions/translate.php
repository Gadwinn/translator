<?php

/**
 * Opt-in procedural translation API.
 *
 * This file declares eight global functions inspired by the GNU
 * Gettext family (`gettext`, `dgettext`, `ngettext`, `pgettext`
 * and their combinations), adapted to the package's
 * `TranslatorInterface` contract.
 *
 * Activation is opt-in via the consumer's `composer.json`:
 *
 *     "autoload": {
 *         "files": [
 *             "vendor/gadwinn/translator/src/Functions/translate.php"
 *         ]
 *     }
 *
 * Bootstrap is performed by registering a default translator
 * BEFORE any function call:
 *
 *     use G\Translator\Functions\TranslatorRegistry;
 *
 *     TranslatorRegistry::setDefault($translator);
 *     echo __('Hello %name%', ['name' => 'Alice']);
 *
 * The procedural API is a thin convenience layer; the
 * object-oriented API (`$translator->trans(...)`) remains the
 * canonical entry point and exposes the full set of options.
 *
 * Argument order follows the historical GNU Gettext convention
 * to remain compatible with extraction tools such as Poedit.
 *
 * @see \G\Translator\Runtime\TranslatorInterface
 * @see \G\Translator\Functions\TranslatorRegistry
 */

declare(strict_types=1);

use G\Translator\Functions\TranslatorRegistry;

if (!function_exists('__')) {
    /**
     * Translates a singular message in the default domain.
     *
     * Equivalent to `gettext($id)`.
     *
     * @param non-empty-string $id The source identifier.
     * @param array<int|string, scalar|Stringable> $params
     *        Placeholder values.
     */
    function __(string $id, array $params = []): string
    {
        return TranslatorRegistry::current()->trans(
            id: $id,
            params: $params,
        );
    }
}

if (!function_exists('__d')) {
    /**
     * Translates a singular message in a specific domain.
     *
     * Equivalent to `dgettext($domain, $id)`.
     *
     * @param non-empty-string $domain
     * @param non-empty-string $id
     * @param array<int|string, scalar|Stringable> $params
     */
    function __d(string $domain, string $id, array $params = []): string
    {
        return TranslatorRegistry::current()->trans(
            id: $id,
            domain: $domain,
            params: $params,
        );
    }
}

if (!function_exists('__n')) {
    /**
     * Translates a plural message in the default domain.
     *
     * Equivalent to `ngettext($id, $plural, $count)`.
     *
     * @param non-empty-string $id The singular source.
     * @param non-empty-string $plural The plural source.
     * @param int $count The count driving plural form selection.
     * @param array<int|string, scalar|Stringable> $params
     */
    function __n(string $id, string $plural, int $count, array $params = []): string
    {
        return TranslatorRegistry::current()->plural(
            id: $id,
            plural: $plural,
            count: $count,
            params: $params,
        );
    }
}

if (!function_exists('__p')) {
    /**
     * Translates a singular message with a disambiguation
     * context.
     *
     * Equivalent to `pgettext($context, $id)`.
     *
     * @param non-empty-string $context
     * @param non-empty-string $id
     * @param array<int|string, scalar|Stringable> $params
     */
    function __p(string $context, string $id, array $params = []): string
    {
        return TranslatorRegistry::current()->trans(
            id: $id,
            context: $context,
            params: $params,
        );
    }
}

if (!function_exists('__dn')) {
    /**
     * Translates a plural message in a specific domain.
     *
     * Equivalent to `dngettext($domain, $id, $plural, $count)`.
     *
     * @param non-empty-string $domain
     * @param non-empty-string $id
     * @param non-empty-string $plural
     * @param int $count
     * @param array<int|string, scalar|Stringable> $params
     */
    function __dn(string $domain, string $id, string $plural, int $count, array $params = []): string
    {
        return TranslatorRegistry::current()->plural(
            id: $id,
            plural: $plural,
            count: $count,
            domain: $domain,
            params: $params,
        );
    }
}

if (!function_exists('__dp')) {
    /**
     * Translates a singular message in a specific domain with a
     * disambiguation context.
     *
     * Equivalent to `dpgettext($domain, $context, $id)`.
     *
     * @param non-empty-string $domain
     * @param non-empty-string $context
     * @param non-empty-string $id
     * @param array<int|string, scalar|Stringable> $params
     */
    function __dp(string $domain, string $context, string $id, array $params = []): string
    {
        return TranslatorRegistry::current()->trans(
            id: $id,
            domain: $domain,
            context: $context,
            params: $params,
        );
    }
}

if (!function_exists('__np')) {
    /**
     * Translates a plural message with a disambiguation context.
     *
     * Equivalent to `npgettext($context, $id, $plural, $count)`.
     *
     * @param non-empty-string $context
     * @param non-empty-string $id
     * @param non-empty-string $plural
     * @param int $count
     * @param array<int|string, scalar|Stringable> $params
     */
    function __np(string $context, string $id, string $plural, int $count, array $params = []): string
    {
        return TranslatorRegistry::current()->plural(
            id: $id,
            plural: $plural,
            count: $count,
            context: $context,
            params: $params,
        );
    }
}

if (!function_exists('__dnp')) {
    /**
     * Translates a plural message in a specific domain with a
     * disambiguation context.
     *
     * Equivalent to `dnpgettext($domain, $context, $id, $plural,
     * $count)`.
     *
     * @param non-empty-string $domain
     * @param non-empty-string $context
     * @param non-empty-string $id
     * @param non-empty-string $plural
     * @param int $count
     * @param array<int|string, scalar|Stringable> $params
     */
    function __dnp(
        string $domain,
        string $context,
        string $id,
        string $plural,
        int $count,
        array $params = [],
    ): string {
        return TranslatorRegistry::current()->plural(
            id: $id,
            plural: $plural,
            count: $count,
            domain: $domain,
            context: $context,
            params: $params,
        );
    }
}
