<?php

declare(strict_types=1);

namespace G\Translator\Tests\Engine;

use G\Translator\Engine\StackEngine;
use G\Translator\Engine\StackedSource;
use G\Translator\Engine\Strategy\MergeStrategyResolver;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Engine\MergeException;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\StringSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StackEngine::class)]
final class StackEngineTest extends TestCase
{
    private FormatDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new FormatDetector();
    }

    public function testEmptyEngineProducesEmptyBag(): void
    {
        $engine = new StackEngine($this->detector);
        $bag = $engine->resolve('en');

        self::assertCount(0, $bag);
        self::assertSame('en', $bag->locale());
    }

    public function testAddedLayersAreReturnedInInsertionOrder(): void
    {
        $engine = new StackEngine($this->detector);
        $layer1 = new StackedSource(new StringSource('{}', extension: 'json'), 0);
        $layer2 = new StackedSource(new StringSource('{}', extension: 'json'), 10);

        $engine->add($layer1);
        $engine->add($layer2);

        self::assertSame([$layer1, $layer2], $engine->layers());
    }

    public function testNonOverlappingLayersAreUnioned(): void
    {
        $engine = new StackEngine($this->detector);
        $engine->add(new StackedSource(
            new StringSource('{"hello":"Hello"}', extension: 'json'),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"bye":"Goodbye"}', extension: 'json'),
            priority: 10,
        ));

        $bag = $engine->resolve('en');

        self::assertCount(2, $bag);
        self::assertSame('Hello', $bag->get('hello')->content());
        self::assertSame('Goodbye', $bag->get('bye')->content());
    }

    public function testThrowOnConflictRaisesMergeExceptionByDefault(): void
    {
        $engine = new StackEngine($this->detector);
        $engine->add(new StackedSource(
            new StringSource('{"hello":"Hi"}', extension: 'json'),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"hello":"Hello"}', extension: 'json'),
            priority: 10,
        ));

        $this->expectException(MergeException::class);
        $engine->resolve('en');
    }

    public function testReplaceLetsHigherPriorityWin(): void
    {
        $engine = new StackEngine($this->detector);
        $engine->add(new StackedSource(
            new StringSource('{"hello":"Hi"}', extension: 'json'),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"hello":"Hello"}', extension: 'json'),
            priority: 10,
        ));

        $bag = $engine->resolve('en', MergeStrategy::REPLACE);
        self::assertSame('Hello', $bag->get('hello')->content());
    }

    public function testKeepExistingLetsLowerPriorityWin(): void
    {
        $engine = new StackEngine($this->detector);
        $engine->add(new StackedSource(
            new StringSource('{"hello":"Hi"}', extension: 'json'),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"hello":"Hello"}', extension: 'json'),
            priority: 10,
        ));

        $bag = $engine->resolve('en', MergeStrategy::KEEP_EXISTING);
        self::assertSame('Hi', $bag->get('hello')->content());
    }

    public function testLayersAreSortedByPriorityAscending(): void
    {
        $engine = new StackEngine($this->detector);
        // Insert OUT OF ORDER on purpose.
        $engine->add(new StackedSource(
            new StringSource('{"key":"P20"}', extension: 'json'),
            priority: 20,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"key":"P0"}', extension: 'json'),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"key":"P10"}', extension: 'json'),
            priority: 10,
        ));

        // REPLACE wins highest-priority, so the P20 layer's content is the
        // final value.
        $bag = $engine->resolve('en', MergeStrategy::REPLACE);
        self::assertSame('P20', $bag->get('key')->content());
    }

    public function testEqualPriorityPreservesInsertionOrder(): void
    {
        $engine = new StackEngine($this->detector);
        // Two layers at priority 5. KEEP_EXISTING means the FIRST one inserted wins.
        $engine->add(new StackedSource(
            new StringSource('{"key":"first"}', extension: 'json'),
            priority: 5,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"key":"second"}', extension: 'json'),
            priority: 5,
        ));

        $bag = $engine->resolve('en', MergeStrategy::KEEP_EXISTING);
        self::assertSame('first', $bag->get('key')->content());
    }

    public function testNegativePrioritiesAreSupported(): void
    {
        $engine = new StackEngine($this->detector);
        $engine->add(new StackedSource(
            new StringSource('{"key":"low"}', extension: 'json'),
            priority: -10,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"key":"high"}', extension: 'json'),
            priority: 0,
        ));

        $bag = $engine->resolve('en', MergeStrategy::REPLACE);
        self::assertSame('high', $bag->get('key')->content());
    }

    public function testThreeLayerStackOverridePyramid(): void
    {
        // Real-world pattern: base + tenant + user overrides.
        $engine = new StackEngine($this->detector);
        $engine->add(new StackedSource(
            new StringSource(
                '{"greeting":"Hello","brand":"Default"}',
                extension: 'json',
            ),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"brand":"Acme"}', extension: 'json'),
            priority: 10,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"greeting":"Bonjour, Willy!"}', extension: 'json'),
            priority: 20,
        ));

        $bag = $engine->resolve('fr-FR', MergeStrategy::REPLACE);
        self::assertSame('Bonjour, Willy!', $bag->get('greeting')->content());
        self::assertSame('Acme', $bag->get('brand')->content());
    }

    public function testCustomResolverIsRespected(): void
    {
        // Inject a custom resolver; explicitly fail-fast on REPLACE
        // to prove the engine actually delegates.
        $resolver = new MergeStrategyResolver();
        $resolver->register(new class () implements \G\Translator\Engine\Strategy\MergeStrategyHandlerInterface {
            public function resolve(
                \G\Translator\Bag\TranslationEntryInterface $existing,
                \G\Translator\Bag\TranslationEntryInterface $incoming,
            ): \G\Translator\Bag\TranslationEntryInterface {
                throw new MergeException('custom replace forbidden');
            }

            public function strategy(): MergeStrategy
            {
                return MergeStrategy::REPLACE;
            }
        });

        $engine = new StackEngine($this->detector, $resolver);
        $engine->add(new StackedSource(
            new StringSource('{"key":"a"}', extension: 'json'),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource('{"key":"b"}', extension: 'json'),
            priority: 10,
        ));

        $this->expectException(MergeException::class);
        $engine->resolve('en', MergeStrategy::REPLACE);
    }

    public function testLayersFromDifferentFormatsCanCohabit(): void
    {
        $engine = new StackEngine($this->detector);
        $engine->add(new StackedSource(
            new StringSource('{"k1":"json"}', extension: 'json'),
            priority: 0,
        ));
        $engine->add(new StackedSource(
            new StringSource(
                "msgid \"k2\"\nmsgstr \"po-value\"\n",
                extension: 'po',
            ),
            priority: 10,
        ));

        $bag = $engine->resolve('en');
        self::assertSame('json', $bag->get('k1')->content());
        self::assertSame('po-value', $bag->get('k2')->content());
    }
}
