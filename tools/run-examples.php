<?php

declare(strict_types=1);

/**
 * Runs every `examples/NN_*.php` script in a child PHP process and
 * fails when any of them exits non-zero.
 *
 * This is the "compile + run" contract for the README: each
 * example file is the canonical illustration of one README
 * section, and a green run proves the surface still matches what
 * the README promises.
 *
 * Invoked by `composer test-examples` and chained into
 * `composer test-all`.
 */

$rootDir = dirname(__DIR__);
$examplesDir = $rootDir . DIRECTORY_SEPARATOR . 'examples';

if (!is_dir($examplesDir)) {
    fwrite(STDERR, "run-examples: examples/ directory not found at {$examplesDir}\n");
    exit(2);
}

$files = glob($examplesDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
sort($files);

// Only run example scripts whose filename starts with a digit;
// `_bootstrap.php` and any future helpers stay out of the runner.
$examples = array_values(array_filter(
    $files,
    static function (string $path): bool {
        $name = basename($path);
        return $name !== '' && $name[0] >= '0' && $name[0] <= '9';
    },
));

if ($examples === []) {
    fwrite(STDERR, "run-examples: no NN_*.php scripts found under examples/.\n");
    exit(2);
}

$php = PHP_BINARY;
$failures = [];

fwrite(STDOUT, sprintf("Running %d example(s):\n\n", count($examples)));

foreach ($examples as $script) {
    $relative = ltrim(str_replace($rootDir, '', $script), DIRECTORY_SEPARATOR);
    fwrite(STDOUT, "▶ {$relative}\n");

    $command = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
    $output = [];
    $exit = 0;
    exec($command, $output, $exit);

    foreach ($output as $line) {
        fwrite(STDOUT, '  ' . $line . "\n");
    }

    if ($exit !== 0) {
        $failures[$relative] = $exit;
    }

    fwrite(STDOUT, "\n");
}

if ($failures === []) {
    fwrite(STDOUT, sprintf("All %d example(s) passed.\n", count($examples)));
    exit(0);
}

fwrite(STDERR, sprintf(
    "%d example(s) failed:\n%s\n",
    count($failures),
    implode("\n", array_map(
        static fn (string $name, int $code): string => "  - {$name} (exit {$code})",
        array_keys($failures),
        array_values($failures),
    )),
));
exit(1);
