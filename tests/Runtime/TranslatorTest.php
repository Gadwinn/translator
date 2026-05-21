<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Runtime\MissingTranslationException;
use G\Translator\Runtime\BagRegistry;
use G\Translator\Runtime\LocaleFallback;
use G\Translator\Runtime\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    private BagRegistry $registry;
    private LocaleFallback $fallback;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->registry = new BagRegistry();

        $french = new TranslationBag('fr-FR');
        $french->add(new TranslationEntry(id: 'Hello %name%', content: 'Bonjour %name%'));
        $french->add(new TranslationEntry(id: 'plain', content: 'simple'));
        $french->add(new TranslationEntry(id: 'Post', context: 'noun', content: 'Article'));
        $french->add(new TranslationEntry(id: 'Post', context: 'verb', content: 'Publier'));
        $french->add(new TranslationEntry(id: 'Welcome', domain: 'admin', content: 'Bienvenue admin'));
        $french->add(new TranslationEntry(
            id: 'One apple',
            content: 'One apple',
            pluralForms: [
                PluralCategory::ONE->value   => 'Une pomme',
                PluralCategory::OTHER->value => '{count} pommes',
            ],
        ));

        $english = new TranslationBag('en');
        $english->add(new TranslationEntry(id: 'fallback.only', content: 'EN fallback'));

        $this->registry->register('fr-FR', $french);
        $this->registry->register('en', $english);

        $this->fallback = new LocaleFallback('fr-FR');
        $this->fallback->setChain(['en']);

        $this->translator = new Translator($this->registry, $this->fallback);
    }

    public function testTransResolvesFromPrimaryLocale(): void
    {
        self::assertSame('simple', $this->translator->trans('plain'));
    }

    public function testTransHydratesPlaceholders(): void
    {
        self::assertSame(
            'Bonjour Willy',
            $this->translator->trans('Hello %name%', params: ['name' => 'Willy']),
        );
    }

    public function testTransDistinguishesContexts(): void
    {
        self::assertSame('Article', $this->translator->trans('Post', context: 'noun'));
        self::assertSame('Publier', $this->translator->trans('Post', context: 'verb'));
    }

    public function testTransRespectsDomain(): void
    {
        self::assertSame(
            'Bienvenue admin',
            $this->translator->trans('Welcome', domain: 'admin'),
        );
    }

    public function testTransFallsThroughChainToEnglish(): void
    {
        // 'fallback.only' exists in en bag but not in fr-FR bag.
        self::assertSame('EN fallback', $this->translator->trans('fallback.only'));
    }

    public function testTransReturnsIdInLenientModeWhenMissing(): void
    {
        self::assertSame('absent.key', $this->translator->trans('absent.key'));
    }

    public function testTransThrowsInStrictModeWhenMissing(): void
    {
        $this->translator->setStrict(true);
        self::assertTrue($this->translator->isStrict());

        $this->expectException(MissingTranslationException::class);
        $this->translator->trans('absent.key');
    }

    public function testTransLocaleOverrideUsesNewPrimary(): void
    {
        $bag = new TranslationBag('de');
        $bag->add(new TranslationEntry(id: 'hello', content: 'Hallo'));
        $this->registry->register('de', $bag);

        self::assertSame('Hallo', $this->translator->trans('hello', locale: 'de'));
    }

    public function testPluralPicksOneForCountOne(): void
    {
        self::assertSame(
            'Une pomme',
            $this->translator->plural('One apple', '%count% apples', 1),
        );
    }

    public function testPluralInjectsCountAutomatically(): void
    {
        self::assertSame(
            '5 pommes',
            $this->translator->plural('One apple', '%count% apples', 5),
        );
    }

    public function testPluralRespectsCallerProvidedCount(): void
    {
        // Caller-provided 'count' wins over auto-injection.
        self::assertSame(
            '5 000 pommes',
            $this->translator->plural(
                id:     'One apple',
                plural: '%count% apples',
                count:  5000,
                params: ['count' => '5 000'],
            ),
        );
    }

    public function testPluralFallbackUsesIdForCountOne(): void
    {
        $out = $this->translator->plural('singular id', '{count} plural id', 1);
        self::assertSame('singular id', $out);
    }

    public function testPluralFallbackUsesPluralForOtherCounts(): void
    {
        $out = $this->translator->plural('singular id', '{count} plural id', 5);
        self::assertSame('5 plural id', $out);
    }

    public function testPluralFallbackDoesNotInjectIntoPositional(): void
    {
        // Positional %d is NOT auto-filled: the caller must provide it.
        $out = $this->translator->plural('Singular %d', 'Plural %d', 5);
        // Auto-injection only targets %count% / {count}; '%d' is untouched.
        self::assertSame('Plural %d', $out);
    }

    public function testPluralFallbackInjectsIntoBraceCount(): void
    {
        $out = $this->translator->plural('one', '{count} items', 3);
        self::assertSame('3 items', $out);
    }

    public function testSetLocaleUpdatesUnderlyingFallback(): void
    {
        $this->translator->setLocale('en');
        self::assertSame('en', $this->translator->locale());
    }

    public function testStrictModeToggle(): void
    {
        self::assertFalse($this->translator->isStrict());
        $this->translator->setStrict(true);
        self::assertTrue($this->translator->isStrict());
        $this->translator->setStrict(false);
        self::assertFalse($this->translator->isStrict());
    }

    /**
     * Contract-literal test: the interface says "first match wins;
     * its content is hydrated with `$params` and returned". An entry
     * with empty content IS a match, so it short-circuits the chain
     * and returns its empty content unchanged — it does NOT cause
     * silent fall-through to lower-priority locales.
     *
     * If a project wants empty translations to fall through, the
     * intended mechanism is to omit the entry from the bag entirely
     * (rather than storing it with empty content).
     */
    public function testEmptyContentEntryShortCircuitsChainAsContractDictates(): void
    {
        $french = $this->registry->get('fr-FR');
        $french->add(new TranslationEntry(id: 'empty.passthrough', content: ''));

        $english = $this->registry->get('en');
        $english->add(new TranslationEntry(id: 'empty.passthrough', content: 'EN value'));

        self::assertSame('', $this->translator->trans('empty.passthrough'));
    }
}
