<?php

declare(strict_types=1);

namespace G\Translator\Tests\Engine\Strategy;

use G\Translator\Bag\TranslationEntry;
use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Engine\Strategy\Handler\KeepExistingHandler;
use G\Translator\Engine\Strategy\Handler\ReplaceHandler;
use G\Translator\Engine\Strategy\Handler\ThrowOnConflictHandler;
use G\Translator\Engine\Strategy\MergeStrategyHandlerInterface;
use G\Translator\Engine\Strategy\MergeStrategyResolver;
use G\Translator\Enum\MergeStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MergeStrategyResolver::class)]
final class MergeStrategyResolverTest extends TestCase
{
    public function testAllBuiltinsPreRegistered(): void
    {
        $resolver = new MergeStrategyResolver();

        self::assertTrue($resolver->has(MergeStrategy::THROW_ON_CONFLICT));
        self::assertTrue($resolver->has(MergeStrategy::REPLACE));
        self::assertTrue($resolver->has(MergeStrategy::KEEP_EXISTING));
    }

    public function testResolveReturnsExpectedHandlerClasses(): void
    {
        $resolver = new MergeStrategyResolver();

        self::assertInstanceOf(
            ThrowOnConflictHandler::class,
            $resolver->resolve(MergeStrategy::THROW_ON_CONFLICT),
        );
        self::assertInstanceOf(
            ReplaceHandler::class,
            $resolver->resolve(MergeStrategy::REPLACE),
        );
        self::assertInstanceOf(
            KeepExistingHandler::class,
            $resolver->resolve(MergeStrategy::KEEP_EXISTING),
        );
    }

    public function testRegisterReplacesBuiltin(): void
    {
        $resolver = new MergeStrategyResolver();

        $custom = new class () implements MergeStrategyHandlerInterface {
            public function resolve(
                TranslationEntryInterface $existing,
                TranslationEntryInterface $incoming,
            ): TranslationEntryInterface {
                return $incoming->withContent('custom: ' . $incoming->content());
            }

            public function strategy(): MergeStrategy
            {
                return MergeStrategy::REPLACE;
            }
        };

        $resolver->register($custom);

        self::assertSame($custom, $resolver->resolve(MergeStrategy::REPLACE));
    }

    public function testCustomHandlerSemanticsObserved(): void
    {
        $resolver = new MergeStrategyResolver();
        $resolver->register(new class () implements MergeStrategyHandlerInterface {
            public function resolve(
                TranslationEntryInterface $existing,
                TranslationEntryInterface $incoming,
            ): TranslationEntryInterface {
                return $existing->withContent($existing->content() . ' / ' . $incoming->content());
            }

            public function strategy(): MergeStrategy
            {
                return MergeStrategy::REPLACE;
            }
        });

        $handler = $resolver->resolve(MergeStrategy::REPLACE);
        $winner = $handler->resolve(
            new TranslationEntry(id: 'x', content: 'first'),
            new TranslationEntry(id: 'x', content: 'second'),
        );

        self::assertSame('first / second', $winner->content());
    }
}
