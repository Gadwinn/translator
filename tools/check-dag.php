<?php

declare(strict_types=1);

/**
 * Custom DAG check for `gadwinn/translator`.
 *
 * Walks every PHP file under `src/`, extracts every `use` statement
 * referencing a class inside the package, and verifies that the
 * dependency direction matches the table in
 * `.github/copilot-instructions.md` section 3:
 *
 *     Bridge → Runtime → Engine → Bag → Io → (Exception, Enum)
 *                                       ↑
 *                                       Http (transverse utility)
 *                                       Functions (opt-in shell)
 *
 * Allowed cross-layer dependencies (exact match or prefix-with-`\`)
 * are encoded in `ALLOWED_DEPENDENCIES` below. Same-layer imports
 * are always allowed. Imports outside `G\Translator\` are always
 * allowed (PHP standard library, third-party).
 *
 * Exits 0 when every file is compliant, non-zero with a per-file
 * report of violations otherwise.
 *
 * Run:
 *     php tools/check-dag.php
 */

const VENDOR_NAMESPACE = 'G\\Translator\\';

/**
 * Cross-layer dependency table, keyed by the SOURCE layer.
 *
 * Same-layer imports are always permitted and not listed here.
 * Every entry is matched as `target === entry` OR
 * `str_starts_with(target, entry . '\\')`.
 *
 * Mirrors `.github/copilot-instructions.md` section 3.
 */
const ALLOWED_DEPENDENCIES = [
    'Exception' => [],
    'Enum'      => [],
    'Http'      => ['Exception\\Bridge', 'Http\\Enum'],
    'Io'        => ['Exception\\Io', 'Enum', 'Bag'],
    'Bag'       => ['Exception\\Bag', 'Exception\\Engine', 'Enum'],
    'Engine'    => ['Exception\\Engine', 'Exception\\Io', 'Enum', 'Bag', 'Io'],
    'Runtime'   => ['Exception\\Runtime', 'Exception\\Bag', 'Enum', 'Bag'],
    'Bridge'    => ['Exception\\Bridge', 'Enum', 'Bag', 'Http'],
    'Functions' => ['Runtime', 'Exception\\Configuration'],
];

$rootDir = dirname(__DIR__);
$srcDir = $rootDir . DIRECTORY_SEPARATOR . 'src';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "DAG check: src/ directory not found at {$srcDir}\n");
    exit(2);
}

$violations = [];
$scannedFiles = 0;

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $scannedFiles++;
    $path = $file->getPathname();
    $relative = ltrim(str_replace($srcDir, '', $path), DIRECTORY_SEPARATOR);
    $sourceLayer = layerFromRelativePath($relative);

    if ($sourceLayer === null) {
        continue; // file directly at src/ root: ignore.
    }

    $allowedForLayer = ALLOWED_DEPENDENCIES[$sourceLayer] ?? null;
    if ($allowedForLayer === null) {
        $violations[] = sprintf(
            '%s: unknown source layer "%s" (no entry in ALLOWED_DEPENDENCIES).',
            $relative,
            $sourceLayer,
        );
        continue;
    }

    foreach (extractUses($path) as $useFqcn) {
        $targetRelative = stripVendorPrefix($useFqcn);
        if ($targetRelative === null) {
            continue; // outside G\Translator\: always allowed.
        }

        $targetLayer = firstSegment($targetRelative);
        if ($targetLayer === $sourceLayer) {
            continue; // same-layer imports are always allowed.
        }

        if (!isDependencyAllowed($targetRelative, $allowedForLayer)) {
            $violations[] = sprintf(
                "%s\n    forbidden import: G\\Translator\\%s\n    source layer:    %s\n    allowed targets: %s",
                $relative,
                $targetRelative,
                $sourceLayer,
                $allowedForLayer === [] ? '(none — this layer must be a leaf)' : implode(', ', $allowedForLayer),
            );
        }
    }
}

if ($violations === []) {
    fwrite(STDOUT, sprintf("DAG check OK — %d file(s) scanned.\n", $scannedFiles));
    exit(0);
}

fwrite(STDERR, sprintf(
    "DAG check FAILED — %d violation(s) across %d scanned file(s):\n\n%s\n\n",
    count($violations),
    $scannedFiles,
    implode("\n\n", $violations),
));
exit(1);

/**
 * Returns the first top-level directory component of a path
 * relative to `src/`, or `null` for files directly at the root.
 */
function layerFromRelativePath(string $relative): ?string
{
    $normalized = str_replace('\\', '/', $relative);
    $segments = explode('/', $normalized);
    if (count($segments) < 2) {
        return null;
    }

    return $segments[0];
}

/**
 * @return list<string> Fully-qualified class names imported via
 *         `use` statements in the file.
 */
function extractUses(string $path): array
{
    $contents = (string) file_get_contents($path);
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);

    if (preg_match_all('/^use\s+([^;]+);/m', $contents, $matches) === false) {
        return [];
    }

    $result = [];
    foreach ($matches[1] as $captured) {
        // Strip `function ` / `const ` modifiers and aliases.
        $trimmed = preg_replace('/^(function|const)\s+/i', '', trim($captured)) ?? '';
        $trimmed = preg_replace('/\s+as\s+\S+$/i', '', $trimmed) ?? '';
        $trimmed = trim($trimmed);

        // A `use Foo\{Bar, Baz}` group expands into Foo\Bar, Foo\Baz.
        if (preg_match('/^(.+?)\\\\\{([^}]+)}$/', $trimmed, $group) === 1) {
            $prefix = $group[1];
            foreach (explode(',', $group[2]) as $tail) {
                $tail = trim($tail);
                if ($tail !== '') {
                    $result[] = $prefix . '\\' . $tail;
                }
            }
            continue;
        }

        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
    }

    return $result;
}

/**
 * Returns the FQCN with the package prefix removed, or `null` when
 * the import is outside the package.
 */
function stripVendorPrefix(string $fqcn): ?string
{
    if (!str_starts_with($fqcn, VENDOR_NAMESPACE)) {
        return null;
    }

    return substr($fqcn, strlen(VENDOR_NAMESPACE));
}

function firstSegment(string $relative): string
{
    $backslash = strpos($relative, '\\');
    if ($backslash === false) {
        return $relative;
    }

    return substr($relative, 0, $backslash);
}

/**
 * @param list<string> $allowedForLayer
 */
function isDependencyAllowed(string $targetRelative, array $allowedForLayer): bool
{
    foreach ($allowedForLayer as $allowed) {
        if ($targetRelative === $allowed) {
            return true;
        }
        if (str_starts_with($targetRelative, $allowed . '\\')) {
            return true;
        }
    }

    return false;
}
