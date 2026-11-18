<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bridge\Ai\Result;

use G\Translator\Bridge\Ai\Result\TranslationResult;
use G\Translator\Enum\PluralCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationResult::class)]
final class TranslationResultTest extends TestCase
{
    public function testConstructorSetsRequiredProperty(): void
    {
        $result = new TranslationResult(translatedText: 'Bonjour');

        self::assertSame('Bonjour', $result->translatedText);
    }

    public function testDefaultValuesAreApplied(): void
    {
        $result = new TranslationResult(translatedText: 'Bonjour');

        self::assertSame([], $result->translatedPluralForms);
        self::assertNull($result->confidence);
        self::assertSame([], $result->metadata);
    }

    public function testAllOptionalPropertiesCanBeSet(): void
    {
        $pluralForms = [
            PluralCategory::ONE->value => 'un élément',
            PluralCategory::OTHER->value => '%d éléments',
        ];

        $result = new TranslationResult(
            translatedText: 'un élément',
            translatedPluralForms: $pluralForms,
            confidence: 0.95,
            metadata: ['model' => 'gpt-4o', 'tokens' => 42],
        );

        self::assertSame($pluralForms, $result->translatedPluralForms);
        self::assertEqualsWithDelta(0.95, $result->confidence, 0.0001);
        self::assertSame(['model' => 'gpt-4o', 'tokens' => 42], $result->metadata);
    }

    public function testEmptyTranslatedTextIsAccepted(): void
    {
        $result = new TranslationResult(translatedText: '');

        self::assertSame('', $result->translatedText);
    }
}
