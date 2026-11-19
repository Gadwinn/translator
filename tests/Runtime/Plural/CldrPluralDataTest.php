<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime\Plural;

use G\Translator\Runtime\Plural\CldrPluralResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks every locale of the resolver against the categories computed
 * from the CLDR `plurals.xml` file (tests/Fixtures/cldr-integer-plurals.json,
 * generated from the official data, independently of the PHP rules).
 */
#[CoversClass(CldrPluralResolver::class)]
final class CldrPluralDataTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<int>, list<string>}>
     */
    public static function cldrLocales(): iterable
    {
        /** @var array{samples: list<int>, expected: list<string>, locales: array<string, int>} $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/cldr-integer-plurals.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($data['locales'] as $locale => $signature) {
            yield $locale => [$locale, $data['samples'], explode(' ', $data['expected'][$signature])];
        }
    }

    /**
     * @param list<int> $samples
     * @param list<string> $expected
     */
    #[DataProvider('cldrLocales')]
    public function testLocaleMatchesCldr(string $locale, array $samples, array $expected): void
    {
        $resolver = new CldrPluralResolver();

        $actual = array_map(
            static fn (int $count): string => $resolver->resolve($count, $locale)->value,
            $samples,
        );

        self::assertSame($expected, $actual);
    }

    public function testEveryCldrLocaleIsCovered(): void
    {
        self::assertCount(227, iterator_to_array(self::cldrLocales()));
    }
}
