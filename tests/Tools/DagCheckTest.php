<?php

declare(strict_types=1);

namespace G\Translator\Tests\Tools;

use PHPUnit\Framework\TestCase;

/**
 * Wraps `tools/check-dag.php` so CI executes it through the regular
 * `phpunit` invocation. Failure prints the violation report straight
 * into the PHPUnit output, exactly like a real DAG check.
 */
final class DagCheckTest extends TestCase
{
    public function testEveryFileInSrcRespectsTheLayerDag(): void
    {
        $script = realpath(__DIR__ . '/../../tools/check-dag.php');
        self::assertNotFalse($script, 'tools/check-dag.php must exist');

        $php = PHP_BINARY;
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            "DAG check failed:\n" . implode("\n", $output),
        );
    }
}
