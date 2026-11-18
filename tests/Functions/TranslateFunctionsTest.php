<?php

declare(strict_types=1);

namespace G\Translator\Tests\Functions;

use G\Translator\Exception\Configuration\InvalidConfigurationException;
use G\Translator\Functions\TranslatorRegistry;
use G\Translator\Tests\Stub\StubTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for the opt-in procedural API.
 *
 * Loads `src/Functions/translate.php` once (the file declares its
 * functions under `function_exists` guards, so re-requires are
 * harmless), wires a `StubTranslator` into the global registry,
 * invokes each of the eight `__*()` functions, and asserts the
 * underlying `trans()` / `plural()` call landed with the right
 * arguments.
 *
 * Scope switching via `TranslatorRegistry::use()` is exercised at
 * the end of the file.
 */
#[CoversClass(TranslatorRegistry::class)]
#[CoversFunction('__')]
#[CoversFunction('__d')]
#[CoversFunction('__n')]
#[CoversFunction('__p')]
#[CoversFunction('__dn')]
#[CoversFunction('__dp')]
#[CoversFunction('__np')]
#[CoversFunction('__dnp')]
final class TranslateFunctionsTest extends TestCase
{
    private StubTranslator $stub;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Functions/translate.php';
    }

    protected function setUp(): void
    {
        TranslatorRegistry::reset();
        $this->stub = new StubTranslator();
        TranslatorRegistry::setDefault($this->stub);
    }

    protected function tearDown(): void
    {
        TranslatorRegistry::reset();
    }

    public function testUnderscoreRoutesToTrans(): void
    {
        self::assertSame('trans:Hello %name%', __('Hello %name%', ['name' => 'Alice']));

        $call = $this->stub->calls[0];
        self::assertSame('trans', $call['kind']);
        self::assertSame('Hello %name%', $call['id']);
        self::assertSame('messages', $call['domain']);
        self::assertNull($call['context']);
        self::assertSame(['name' => 'Alice'], $call['params']);
    }

    public function testDRoutesToTransWithDomain(): void
    {
        __d('admin', 'Welcome');

        $call = $this->stub->calls[0];
        self::assertSame('trans', $call['kind']);
        self::assertSame('admin', $call['domain']);
        self::assertSame('Welcome', $call['id']);
    }

    public function testNRoutesToPlural(): void
    {
        __n('One apple', '{count} apples', 5);

        $call = $this->stub->calls[0];
        self::assertSame('plural', $call['kind']);
        self::assertSame('One apple', $call['id']);
        self::assertSame('{count} apples', $call['plural']);
        self::assertSame(5, $call['count']);
        self::assertSame('messages', $call['domain']);
    }

    public function testPRoutesToTransWithContext(): void
    {
        __p('verb', 'Post');

        $call = $this->stub->calls[0];
        self::assertSame('trans', $call['kind']);
        self::assertSame('Post', $call['id']);
        self::assertSame('verb', $call['context']);
    }

    public function testDnRoutesToPluralWithDomain(): void
    {
        __dn('admin', 'One user', '{count} users', 12);

        $call = $this->stub->calls[0];
        self::assertSame('plural', $call['kind']);
        self::assertSame('admin', $call['domain']);
        self::assertSame('One user', $call['id']);
        self::assertSame('{count} users', $call['plural']);
        self::assertSame(12, $call['count']);
    }

    public function testDpRoutesToTransWithDomainAndContext(): void
    {
        __dp('admin', 'noun', 'Post');

        $call = $this->stub->calls[0];
        self::assertSame('trans', $call['kind']);
        self::assertSame('admin', $call['domain']);
        self::assertSame('noun', $call['context']);
        self::assertSame('Post', $call['id']);
    }

    public function testNpRoutesToPluralWithContext(): void
    {
        __np('verb', 'One reply', '{count} replies', 3);

        $call = $this->stub->calls[0];
        self::assertSame('plural', $call['kind']);
        self::assertSame('verb', $call['context']);
        self::assertSame('One reply', $call['id']);
        self::assertSame('{count} replies', $call['plural']);
        self::assertSame(3, $call['count']);
    }

    public function testDnpRoutesToPluralWithDomainAndContext(): void
    {
        __dnp('admin', 'noun', 'One reply', '{count} replies', 3);

        $call = $this->stub->calls[0];
        self::assertSame('plural', $call['kind']);
        self::assertSame('admin', $call['domain']);
        self::assertSame('noun', $call['context']);
        self::assertSame('One reply', $call['id']);
        self::assertSame('{count} replies', $call['plural']);
        self::assertSame(3, $call['count']);
    }

    public function testRegistryThrowsWhenNoTranslatorRegistered(): void
    {
        TranslatorRegistry::reset();

        $this->expectException(InvalidConfigurationException::class);
        __('anything');
    }

    public function testNamedScopeRoutesToScopedTranslator(): void
    {
        $admin = new StubTranslator();
        TranslatorRegistry::register('admin', $admin);
        TranslatorRegistry::use('admin');

        __('Edit user');

        self::assertCount(1, $admin->calls);
        self::assertCount(0, $this->stub->calls);
    }

    public function testNullScopeRevertsToDefault(): void
    {
        $admin = new StubTranslator();
        TranslatorRegistry::register('admin', $admin);
        TranslatorRegistry::use('admin');
        __('to admin');
        TranslatorRegistry::use(null);
        __('to default');

        self::assertCount(1, $admin->calls);
        self::assertCount(1, $this->stub->calls);
    }

    public function testUseRejectsUnknownScope(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        TranslatorRegistry::use('does-not-exist');
    }
}
