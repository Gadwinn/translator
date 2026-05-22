<?php

declare(strict_types=1);

namespace G\Translator\Tests\Stub;

use G\Translator\Runtime\TranslatorInterface;
use Stringable;

/**
 * Test-only `TranslatorInterface` implementation that records every
 * `trans()` / `plural()` invocation so assertions can verify the
 * routing performed by the procedural API.
 *
 * Returns deterministic placeholder strings keyed by the call type
 * so failed assertions surface the real call path in the diff.
 */
final class StubTranslator implements TranslatorInterface
{
    /**
     * @var list<array{
     *     kind: 'trans'|'plural',
     *     id: string,
     *     plural?: string,
     *     count?: int,
     *     domain: string,
     *     context: string|null,
     *     locale: string|null,
     *     params: array<int|string, scalar|Stringable>
     * }>
     */
    public array $calls = [];

    private string $locale = 'en';

    private bool $strict = false;

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    public function trans(
        string $id,
        string $domain = 'messages',
        ?string $context = null,
        ?string $locale = null,
        array $params = [],
    ): string {
        $this->calls[] = [
            'kind'    => 'trans',
            'id'      => $id,
            'domain'  => $domain,
            'context' => $context,
            'locale'  => $locale,
            'params'  => $params,
        ];

        return 'trans:' . $id;
    }

    /**
     * @param array<int|string, scalar|Stringable> $params
     */
    public function plural(
        string $id,
        string $plural,
        int $count,
        string $domain = 'messages',
        ?string $context = null,
        ?string $locale = null,
        array $params = [],
    ): string {
        $this->calls[] = [
            'kind'    => 'plural',
            'id'      => $id,
            'plural'  => $plural,
            'count'   => $count,
            'domain'  => $domain,
            'context' => $context,
            'locale'  => $locale,
            'params'  => $params,
        ];

        return 'plural:' . $id;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setStrict(bool $enabled): void
    {
        $this->strict = $enabled;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }
}
