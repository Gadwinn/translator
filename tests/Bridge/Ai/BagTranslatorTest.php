<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bridge\Ai;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationEntry;
use G\Translator\Bridge\Ai\BagTranslator;
use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Result\TranslationResult;
use G\Translator\Enum\PluralCategory;
use G\Translator\Tests\Stub\StubAiProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BagTranslator::class)]
final class BagTranslatorTest extends TestCase
{
    public function testEmptyBagProducesEmptyTargetBag(): void
    {
        $translator = new BagTranslator(new StubAiProvider());
        $target = $translator->translateBag(new TranslationBag('en'), 'fr-FR');

        self::assertCount(0, $target);
        self::assertSame('fr-FR', $target->locale());
    }

    public function testTargetBagCarriesTargetLocale(): void
    {
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'hello', content: 'Hello'));

        $target = (new BagTranslator(new StubAiProvider()))->translateBag($bag, 'pl-PL');

        self::assertSame('pl-PL', $target->locale());
    }

    public function testEachEntryProducesOneProviderRequest(): void
    {
        $provider = new StubAiProvider();
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'a', content: 'Alpha'));
        $bag->add(new TranslationEntry(id: 'b', content: 'Beta'));

        (new BagTranslator($provider))->translateBag($bag, 'fr-FR');

        self::assertCount(2, $provider->received);
        $sources = array_map(static fn ($r) => $r->sourceText, $provider->received);
        sort($sources);
        self::assertSame(['Alpha', 'Beta'], $sources);
    }

    public function testFallbackToIdWhenContentIsEmpty(): void
    {
        $provider = new StubAiProvider();
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'key.without.content'));

        (new BagTranslator($provider))->translateBag($bag, 'fr-FR');

        self::assertCount(1, $provider->received);
        self::assertSame('key.without.content', $provider->received[0]->sourceText);
    }

    public function testTargetBagPreservesEntryIdAndDomainAndContext(): void
    {
        $provider = new StubAiProvider();
        $provider->setResponse('Hello', new TranslationResult(translatedText: 'Bonjour'));
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'hello.key',
            content: 'Hello',
            domain: 'admin',
            context: 'noun',
        ));

        $target = (new BagTranslator($provider))->translateBag($bag, 'fr-FR');

        $entry = $target->get('hello.key', domain: 'admin', context: 'noun');
        self::assertSame('Bonjour', $entry->content());
    }

    public function testPluralFormsAreHydratedFromProvider(): void
    {
        $provider = new StubAiProvider();
        $provider->setResponse('One apple', new TranslationResult(
            translatedText: 'Une pomme',
            translatedPluralForms: [
                PluralCategory::ONE->value   => 'Une pomme',
                PluralCategory::OTHER->value => '%count% pommes',
            ],
        ));
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'apple',
            content: 'One apple',
            metadata: ['msgid_plural' => '%count% apples'],
        ));

        $target = (new BagTranslator($provider))->translateBag($bag, 'fr-FR');

        $entry = $target->get('apple');
        self::assertSame('Une pomme', $entry->content());
        self::assertSame('%count% pommes', $entry->pluralForms()[PluralCategory::OTHER->value]);
        self::assertSame('%count% apples', $provider->received[0]->sourcePluralText);
    }

    public function testPropagatedMetadataDropsPoHeader(): void
    {
        $provider = new StubAiProvider();
        $provider->setResponse('Hello', new TranslationResult(translatedText: 'Bonjour'));
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(
            id: 'hello',
            content: 'Hello',
            metadata: [
                'references' => ['file.php:1'],
                'po-header'  => ['Language' => 'en'],
            ],
        ));

        $target = (new BagTranslator($provider))->translateBag($bag, 'fr-FR');

        $metadata = $target->get('hello')->metadata();
        self::assertArrayHasKey('references', $metadata);
        self::assertArrayNotHasKey(
            'po-header',
            $metadata,
            'PO header from the source bag must not propagate to the target bag',
        );
    }

    public function testQualityAndMetadataPropagateOnEveryRequest(): void
    {
        $provider = new StubAiProvider();
        $bag = new TranslationBag('en');
        $bag->add(new TranslationEntry(id: 'a', content: 'A'));
        $bag->add(new TranslationEntry(id: 'b', content: 'B'));

        (new BagTranslator($provider))->translateBag(
            $bag,
            'fr-FR',
            TranslationQuality::HIGH,
            ['formality' => 'tu'],
        );

        foreach ($provider->received as $request) {
            self::assertSame(TranslationQuality::HIGH, $request->quality);
            self::assertSame(['formality' => 'tu'], $request->metadata);
        }
    }

    public function testProviderAccessor(): void
    {
        $provider = new StubAiProvider();
        $translator = new BagTranslator($provider);

        self::assertSame($provider, $translator->provider());
    }
}
