<?php

declare(strict_types=1);

namespace G\Translator\Tests\Engine\Strategy\Handler;

use G\Translator\Bag\TranslationEntry;
use G\Translator\Engine\Strategy\Handler\KeepExistingHandler;
use G\Translator\Engine\Strategy\Handler\ReplaceHandler;
use G\Translator\Engine\Strategy\Handler\ThrowOnConflictHandler;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Engine\MergeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThrowOnConflictHandler::class)]
#[CoversClass(ReplaceHandler::class)]
#[CoversClass(KeepExistingHandler::class)]
final class HandlerTest extends TestCase
{
    public function testThrowOnConflictAlwaysRaises(): void
    {
        $existing = new TranslationEntry(id: 'hello', content: 'Hi');
        $incoming = new TranslationEntry(id: 'hello', content: 'Hello');

        $this->expectException(MergeException::class);
        (new ThrowOnConflictHandler())->resolve($existing, $incoming);
    }

    public function testThrowOnConflictStrategyMatchesEnumCase(): void
    {
        self::assertSame(MergeStrategy::THROW_ON_CONFLICT, (new ThrowOnConflictHandler())->strategy());
    }

    public function testReplaceReturnsIncoming(): void
    {
        $existing = new TranslationEntry(id: 'hello', content: 'Hi');
        $incoming = new TranslationEntry(id: 'hello', content: 'Hello');

        $winner = (new ReplaceHandler())->resolve($existing, $incoming);
        self::assertSame($incoming, $winner);
    }

    public function testReplaceStrategyMatchesEnumCase(): void
    {
        self::assertSame(MergeStrategy::REPLACE, (new ReplaceHandler())->strategy());
    }

    public function testKeepExistingReturnsExisting(): void
    {
        $existing = new TranslationEntry(id: 'hello', content: 'Hi');
        $incoming = new TranslationEntry(id: 'hello', content: 'Hello');

        $winner = (new KeepExistingHandler())->resolve($existing, $incoming);
        self::assertSame($existing, $winner);
    }

    public function testKeepExistingStrategyMatchesEnumCase(): void
    {
        self::assertSame(MergeStrategy::KEEP_EXISTING, (new KeepExistingHandler())->strategy());
    }
}
