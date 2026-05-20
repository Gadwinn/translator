<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime;

use G\Translator\Bag\TranslationBag;
use G\Translator\Exception\Bag\EntryNotFoundException;
use G\Translator\Exception\Runtime\InvalidLocaleException;
use G\Translator\Runtime\BagRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BagRegistry::class)]
final class BagRegistryTest extends TestCase
{
    public function testRegisterAndGet(): void
    {
        $registry = new BagRegistry();
        $bag = new TranslationBag('fr-FR');
        $registry->register('fr-FR', $bag);

        self::assertSame($bag, $registry->get('fr-FR'));
    }

    public function testLookupNormalizesLocaleForm(): void
    {
        $registry = new BagRegistry();
        $bag = new TranslationBag('pl-PL');
        $registry->register('pl_PL', $bag);

        self::assertSame($bag, $registry->get('PL-pl'));
        self::assertTrue($registry->has('pl-PL'));
    }

    public function testReRegisterReplaces(): void
    {
        $registry = new BagRegistry();
        $first = new TranslationBag('fr-FR');
        $second = new TranslationBag('fr-FR');
        $registry->register('fr-FR', $first);
        $registry->register('fr-FR', $second);

        self::assertSame($second, $registry->get('fr-FR'));
    }

    public function testGetThrowsWhenMissing(): void
    {
        $registry = new BagRegistry();
        $this->expectException(EntryNotFoundException::class);
        $registry->get('fr-FR');
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $registry = new BagRegistry();
        self::assertNull($registry->find('fr-FR'));
    }

    public function testHasReturnsFalseWhenMissing(): void
    {
        $registry = new BagRegistry();
        self::assertFalse($registry->has('fr-FR'));
    }

    public function testLocalesReturnsCanonicalForms(): void
    {
        $registry = new BagRegistry();
        $registry->register('fr_FR', new TranslationBag('fr-FR'));
        $registry->register('pl_pl', new TranslationBag('pl-PL'));

        $locales = $registry->locales();
        sort($locales);
        self::assertSame(['fr-FR', 'pl-PL'], $locales);
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $registry = new BagRegistry();
        $this->expectException(InvalidLocaleException::class);
        $registry->has('not a locale');
    }
}
