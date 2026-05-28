<?php

declare(strict_types=1);

namespace G\Translator\Tests\Runtime\Placeholder;

use G\Translator\Exception\Runtime\InvalidPlaceholderValueException;
use G\Translator\Runtime\Placeholder\DefaultPlaceholderResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(DefaultPlaceholderResolver::class)]
final class DefaultPlaceholderResolverTest extends TestCase
{
    private DefaultPlaceholderResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DefaultPlaceholderResolver();
    }

    public function testReturnsEmptyStringAsIs(): void
    {
        self::assertSame('', $this->resolver->resolve('', ['x' => 1]));
    }

    public function testNoPlaceholdersReturnsVerbatim(): void
    {
        self::assertSame('Hello world', $this->resolver->resolve('Hello world', []));
    }

    public function testPercentNamedSubstitution(): void
    {
        $out = $this->resolver->resolve('Hello %name%', ['name' => 'Willy']);
        self::assertSame('Hello Willy', $out);
    }

    public function testPercentNamedEscapeCollapsesAfterSubstitution(): void
    {
        $out = $this->resolver->resolve('Discount %%50 on %item%', ['item' => 'shoes']);
        self::assertSame('Discount %50 on shoes', $out);
    }

    public function testPercentNamedUnmatchedIsLeftUntouched(): void
    {
        $out = $this->resolver->resolve('Hello %unknown%', []);
        self::assertSame('Hello %unknown%', $out);
    }

    public function testBraceNamedSubstitution(): void
    {
        $out = $this->resolver->resolve('Hi {name}', ['name' => 'Willy']);
        self::assertSame('Hi Willy', $out);
    }

    public function testBraceNamedEscapeCollapsesAfterSubstitution(): void
    {
        $out = $this->resolver->resolve('Block {{name}} pattern in {file}', ['file' => 'log']);
        self::assertSame('Block {name} pattern in log', $out);
    }

    public function testSprintfSequentialPositional(): void
    {
        $out = $this->resolver->resolve('%s likes %s', ['Willy', 'apples']);
        self::assertSame('Willy likes apples', $out);
    }

    public function testSprintfIndexedPositional(): void
    {
        $out = $this->resolver->resolve('%2$s likes %1$s', ['apples', 'Willy']);
        self::assertSame('Willy likes apples', $out);
    }

    public function testSprintfPercentEscape(): void
    {
        $out = $this->resolver->resolve('100%% sure: %s', ['yes']);
        self::assertSame('100% sure: yes', $out);
    }

    public function testSprintfWinsOverNamedFamiliesWhenMixed(): void
    {
        // Mixing families is undefined behavior per the contract, but
        // the resolver MUST behave deterministically: sprintf wins.
        // `%name%` is left untouched because the active family is sprintf.
        $out = $this->resolver->resolve('Hello %s, today %name%', ['Willy']);
        self::assertSame('Hello Willy, today %name%', $out);
    }

    public function testIntScalarIsAccepted(): void
    {
        self::assertSame('42 apples', $this->resolver->resolve('%count% apples', ['count' => 42]));
    }

    public function testFloatScalarIsAccepted(): void
    {
        self::assertSame('1.5 apples', $this->resolver->resolve('%count% apples', ['count' => 1.5]));
    }

    public function testBoolScalarIsAccepted(): void
    {
        self::assertSame('Active: 1', $this->resolver->resolve('Active: %flag%', ['flag' => true]));
        self::assertSame('Active: 0', $this->resolver->resolve('Active: %flag%', ['flag' => false]));
    }

    public function testStringableValueIsAccepted(): void
    {
        $stringable = new class () implements Stringable {
            public function __toString(): string
            {
                return 'stringy';
            }
        };

        self::assertSame(
            'It is stringy',
            $this->resolver->resolve('It is %thing%', ['thing' => $stringable]),
        );
    }

    public function testNullValueThrowsInvalidPlaceholder(): void
    {
        $this->expectException(InvalidPlaceholderValueException::class);
$this->resolver->resolve('Hello %name%', ['name' => null]);
    }

    public function testArrayValueThrowsInvalidPlaceholder(): void
    {
        $this->expectException(InvalidPlaceholderValueException::class);
$this->resolver->resolve('Hello %name%', ['name' => ['nope']]);
    }

    public function testNonStringableObjectThrowsInvalidPlaceholder(): void
    {
        $this->expectException(InvalidPlaceholderValueException::class);
$this->resolver->resolve('Hello %name%', ['name' => new \stdClass()]);
    }

    public function testIdentifiersOutsidePlaceholderSyntaxAreNotTouched(): void
    {
        // `name` alone (no surrounding `%` or `{}`) is not a placeholder.
        $out = $this->resolver->resolve('name is name', ['name' => 'Willy']);
        self::assertSame('name is name', $out);
    }

    public function testSprintfMissingParamLeavesTokenUntouched(): void
    {
        $out = $this->resolver->resolve('%s and %s', ['Willy']);
        self::assertSame('Willy and %s', $out);
    }

    public function testBraceNamedUnmatchedIsLeftUntouched(): void
    {
        $out = $this->resolver->resolve('Hello {name}', []);
        self::assertSame('Hello {name}', $out);
    }
}
