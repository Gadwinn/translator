<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bridge\Ai\Request;

use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationRequest::class)]
final class TranslationRequestTest extends TestCase
{
    public function testConstructorSetsRequiredProperties(): void
    {
        $request = new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr',
        );

        self::assertSame('Hello', $request->sourceText);
        self::assertSame('en', $request->sourceLocale);
        self::assertSame('fr', $request->targetLocale);
    }

    public function testDefaultValuesAreApplied(): void
    {
        $request = new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr',
        );

        self::assertNull($request->sourcePluralText);
        self::assertNull($request->context);
        self::assertSame(TranslationQuality::STANDARD, $request->quality);
        self::assertSame([], $request->metadata);
    }

    public function testAllOptionalPropertiesCanBeSet(): void
    {
        $request = new TranslationRequest(
            sourceText: 'One item',
            sourceLocale: 'en',
            targetLocale: 'pl',
            sourcePluralText: '%d items',
            context: 'product count',
            quality: TranslationQuality::HIGH,
            metadata: ['formality' => 'formal'],
        );

        self::assertSame('%d items', $request->sourcePluralText);
        self::assertSame('product count', $request->context);
        self::assertSame(TranslationQuality::HIGH, $request->quality);
        self::assertSame(['formality' => 'formal'], $request->metadata);
    }
}
