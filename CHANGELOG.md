# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pre-1.0 versions (`0.x.y`) MAY break public APIs without bumping major.
Each breaking change is explicitly listed in its section.

## [Unreleased]

### Fixed

- `G\Translator\Io\Serializer\JsonSerializer` and
  `G\Translator\Io\Serializer\PhpSerializer` docblocks
  incorrectly described context-collision behavior as
  "first-write-wins". The actual code is PHP's standard
  `$data[$id] = $content` which is last-write-wins. Docblocks
  updated to match the implementation; code behavior is
  unchanged. Caught by the new example 07 round-trip.

### Added

#### Phase G addendum — Runnable README examples

- `examples/01_load_and_translate.php` through
  `examples/10_procedural_api.php`: one runnable script per README
  section. Each is invokable as `php examples/NN_*.php` from the
  repo root and exits non-zero on any deviation from the README's
  documented output. The scripts use FileSource + the existing PO
  format pair for sections 1-5, the engine for section 6, the
  JSON serializer for section 7, and the package's own
  `StubAiProvider` / `StubHttpClient` from `tests/Stub/` for the
  AI sections (8 and 9) — no live API calls, no API keys required.
- `examples/data/fr-FR.po`, `examples/data/fr-FR.admin.po`,
  `examples/data/en.po`: small PO fixtures exercising singular,
  plural, msgctxt, and multi-domain entries.
- `examples/_bootstrap.php`: shared loader + `assert_same()`
  helper that surfaces deviations to STDERR and exits 1 so the
  runner picks them up.
- `tools/run-examples.php`: example runner used by
  `composer test-examples` and the CI workflow. Invokes every
  `examples/NN_*.php` script in a child PHP process, captures
  stdout/stderr, and fails when any of them exits non-zero with
  a per-script summary.
- `composer.json`: new `test-examples` script wired into
  `test-all` between `test-unit` and `test-mnd` so example
  regressions break the standard local CI loop.
- `.github/workflows/ci.yml`: example runner added to every PHP
  matrix entry so a broken README example fails CI before merge.

#### Phase G — Tooling, CI, and procedural API tests

- `tools/check-dag.php`: standalone script that walks every PHP
  file under `src/`, extracts every `use` statement targeting
  `G\Translator\*`, and enforces the layered DAG documented in
  `.github/copilot-instructions.md` section 3 (Bridge → Runtime →
  Engine → Bag → Io → Exception/Enum, with Http transverse and
  Functions on top of Runtime). Same-layer and external imports
  are always allowed. Exits non-zero with a per-violation report
  when a forbidden direction is found.
- `tests/Tools/DagCheckTest.php`: PHPUnit wrapper that shells out
  to the script so CI runs the check via the regular `phpunit`
  invocation.
- `tests/Functions/TranslateFunctionsTest.php`: smoke test for the
  opt-in procedural API. Loads `src/Functions/translate.php` once
  and asserts each of the eight `__*()` functions delegates to the
  expected `trans()` / `plural()` method with the right arguments
  on the underlying translator. Covers scope switching via
  `TranslatorRegistry::use()` and the empty-registry error path.
- `tests/Stub/StubTranslator.php`: `TranslatorInterface`
  implementation that records every call, used by the procedural
  API test.
- `.github/workflows/ci.yml`: GitHub Actions matrix workflow
  exercising PHP 8.3, 8.4, and 8.5 on Linux. Runs `composer
  validate`, the DAG check, PHPStan, PHPUnit, and (advisory)
  PHP_CodeSniffer.
- `composer.json`: new `test-dag` script (`php tools/check-dag.php`)
  added to the `test-all` pipeline between `test-cs` and `test-stan`
  so the architectural check runs alongside style and static
  analysis locally.

#### Phase F — HTTP transport + AI providers

- `G\Translator\Http\CurlHttpClient`: default
  `HttpClientInterface` implementation. `send()` via `curl_exec`,
  `sendMany()` via genuine `curl_multi_*` parallelism. Security
  defaults: HTTPS-only (opt-out via constructor `allowHttp` flag),
  full TLS verification, no redirect following. Response headers
  lowercased. Compatible with PHP 8.5 — does not call the
  deprecated `curl_close()` since curl handles are objects since
  PHP 8.0.
- `G\Translator\Exception\Bridge\HttpTransportException`: concrete
  transport-layer error (connection refused, DNS failure, TLS
  handshake, non-HTTPS in HTTPS-only mode).
- `G\Translator\Exception\Bridge\HttpTimeoutException`: `final`
  subclass mapped from `CURLE_OPERATION_TIMEDOUT` so retry/backoff
  code can match the timeout case specifically.
- `G\Translator\Bridge\Ai\Provider\ClaudeProvider`: Anthropic
  Messages API; model per `TranslationQuality`
  (`claude-haiku-4-5-20251001` / `claude-sonnet-4-6` /
  `claude-opus-4-7`); plural support via a strict JSON-envelope
  system prompt. HTTP 401/403 → `AiAuthenticationException`,
  429 → `AiRateLimitException`, others → `AiProviderException`.
  Plural results missing the mandatory `other` category are
  rejected. Fail-fast batch policy.
- `G\Translator\Bridge\Ai\Provider\OpenAiProvider`: Chat
  Completions API with `response_format: json_object`. Same
  quality → model split (`gpt-4o-mini` / `gpt-4o`), same plural
  handling, same exception mapping, same fail-fast batch policy.
- `G\Translator\Bridge\Ai\Provider\DeeplProvider`: text-only
  translation. **Ignores `sourcePluralText`** (DeepL has no plural
  primitive) and always returns an empty
  `translatedPluralForms`. Endpoint auto-detected: free keys ending
  in `:fx` route to `api-free.deepl.com`, others to `api.deepl.com`.
  Locales upper-cased to DeepL convention (`pt_BR` → `PT-BR`).
- `G\Translator\Bridge\Ai\BagTranslator`: end-to-end orchestrator.
  Builds one `TranslationRequest` per source entry, batches via
  `translateBatch()`, hydrates a fresh target bag. Source-text
  resolution: `content !== '' ? content : id`. Source bag never
  mutated. Metadata propagated except `po-header` (the source's
  PO header does not belong on the translated bag).

#### Phase E — Engine (stacking + merge strategies)

- `G\Translator\Engine\Strategy\Handler\ThrowOnConflictHandler`:
  raises `MergeException` on every collision; the default behavior
  every other strategy is an opt-in alternative to.
- `G\Translator\Engine\Strategy\Handler\ReplaceHandler`: returns
  `$incoming` (last-write-wins).
- `G\Translator\Engine\Strategy\Handler\KeepExistingHandler`:
  returns `$existing` (first-write-wins).
- `G\Translator\Engine\Strategy\MergeStrategyResolver`: canonical
  resolver that pre-registers the three built-in handlers eagerly;
  `register()` replaces any registered handler for the same
  strategy so custom implementations can swap built-ins without
  subclassing.
- `G\Translator\Engine\StackEngine`: canonical orchestrator. Sorts
  layers by `priority` ascending via `array_multisort` with the
  original index as tiebreaker (stable sort; PHP's `usort` is
  intentionally not). Folds bags through the resolver's handler so
  custom merge handlers plug in without engine changes.
  Empty-engine short-circuit handles the `range(0, -1)` PHP
  pitfall safely.

#### Phase D — Parsers and serializers

- `G\Translator\Io\Parser\PoParser` / `G\Translator\Io\Serializer\PoSerializer`:
  full GNU Gettext PO support — header parsing (with `Plural-Forms`
  expression and `nplurals` resolution), `msgctxt` disambiguation,
  multi-line strings (`""` opener + continuation lines), the five
  PO escape sequences (`\\`, `\"`, `\n`, `\t`, `\r`), all four
  comment families (`#`, `#.`, `#:`, `#,`), and plural variants
  via a fixed nplurals → CLDR-category mapping that mirrors
  `GettextExpressionPluralResolver` (duplication is intentional —
  the DAG forbids `Io → Runtime` dependencies; both files
  cross-reference each other in their docblocks). Metadata schema
  documented at the top of `PoParser`: `comments`, `extracted`,
  `references`, `flags`, `msgid_plural`, `po-header`.
- `G\Translator\Io\Parser\JsonParser` /
  `G\Translator\Io\Serializer\JsonSerializer`: flat-or-nested
  decode (nested objects flattened to dotted keys), flat
  pretty-printed output (`JSON_PRETTY_PRINT`, `JSON_UNESCAPED_UNICODE`,
  `JSON_UNESCAPED_SLASHES`). No plurals / context / metadata in
  the JSON shape.
- `G\Translator\Io\Parser\AngularJsonParser` /
  `G\Translator\Io\Serializer\AngularJsonSerializer`: same parse
  logic as JSON, but the serializer un-flattens dotted ids back
  into nested objects (ngx-translate convention). Conflicts
  between a string leaf and a nested object at the same path raise
  `SerializerException`.
- `G\Translator\Io\Parser\PhpParser` /
  `G\Translator\Io\Serializer\PhpSerializer`: temp-file `require`
  with unique `tempnam()` slots (no OPcache contamination between
  parses), `var_export`-based output prefixed with
  `declare(strict_types=1)`. Same flatten convention as
  `JsonParser`.
- `G\Translator\Io\Parser\YamlParser` /
  `G\Translator\Io\Serializer\YamlSerializer`: hand-rolled
  recursive-descent parser for the strict translation subset
  (nested maps + scalar strings only). DELIBERATELY rejects
  multi-line scalars (`|`, `>`), anchors (`&`, `*`), tags (`!!`),
  flow style (`{key: value}`, `[a, b]`), and list items (`-`),
  with explicit `ParserException` messages naming the rejected
  feature. The serializer always double-quotes values to bypass
  YAML's implicit typing (`yes`, `true`, `42`).
- `G\Translator\Io\Parser\XliffParser` /
  `G\Translator\Io\Serializer\XliffSerializer`: dual-version
  support (XLIFF 1.2 and 2.0). Parser detects version from the
  root `version` attribute and dispatches to the matching walker;
  serializer emits the version stored in
  `metadata['xliff-version']` (defaults to 1.2 when absent).
  Notes attached to units survive round-trip through
  `metadata['notes']`.
- `G\Translator\Io\Detector\FormatDetector::registerBuiltins()`:
  fully wired — every built-in parser and serializer is
  registered eagerly so `new FormatDetector()` is plug-and-play.

#### Phase C — I/O sources and format detection

- `G\Translator\Io\Source\FileSource`: `final readonly`
  filesystem-backed source. Path validated in the constructor
  (empty rejected); existence / readability / permission checks
  deferred to `read()` for cheap construction and hot-reload
  scenarios. Extension extracted via `pathinfo`, lowercased.
- `G\Translator\Io\Source\StringSource`: `final readonly`
  in-memory source. Default identifier is a stable
  `string://<sha1-prefix>` derived from the payload (12 hex chars,
  48 bits). Both identifier and extension can be overridden
  explicitly via constructor args.
- `G\Translator\Io\Source\ResourceSource`: PHP stream resource
  source. Validates the argument is an open `stream`-type
  resource. Reads in full on first `read()` and caches the content,
  then releases the underlying resource for GC — `read()` is
  idempotent across calls even on non-seekable streams
  (`php://input`, sockets, pipes).
- `G\Translator\Io\Detector\FormatDetector`: canonical
  `DetectorInterface` implementation acting as both Factory and
  Registry. Constructor wires built-ins via a private
  `registerBuiltins()` hook reserved for Phase D; consumers can
  call `registerParser()` / `registerSerializer()` to override
  any built-in driver. Detection is extension-first, with
  `resolveJsonFlavor()` content inspection to disambiguate
  `Format::JSON` from `Format::ANGULAR_JSON`, and a content-only
  fallback for extensionless sources.

#### Phase B — Runtime layer

- `G\Translator\Runtime\Placeholder\DefaultPlaceholderResolver`: canonical
  implementation of `PlaceholderResolverInterface`. Supports the four
  documented syntaxes (sprintf positional, `%name%`, `{name}`) with
  deterministic family detection (priority: sprintf > %name% > {name} >
  none). Escape sequences (`%%`, `{{`, `}}`) are collapsed AFTER
  substitution to prevent user-supplied values from re-entering the
  pass. Values must be `scalar` or `Stringable`; anything else throws
  `InvalidPlaceholderValueException`.
- `G\Translator\Runtime\Plural\CldrPluralResolver`: CLDR-backed
  `PluralResolverInterface` covering ~50 locale aliases grouped in 15
  rule families (Germanic, French-style, East-Slavic, Polish, Czech /
  Slovak, Romanian, Arabic, Hebrew, Slovenian, Latvian, Lithuanian,
  Irish, Welsh, Maltese, no-plural). Rules are embedded in PHP, not
  loaded from JSON at runtime.
- `G\Translator\Runtime\Plural\GettextExpressionPluralResolver`:
  evaluates Gettext `Plural-Forms` expressions against a registered
  per-locale AST. **No `eval()`, no `create_function()`** — a
  hand-rolled tokenizer + recursive-descent parser is the only
  evaluation path. The form-index → CLDR category mapping is
  documented on the class docblock.
- `G\Translator\Runtime\Plural\Gettext\*`: the AST hierarchy
  (`ExpressionNode` base, `IntegerLiteral`, `CountVariable`,
  `BinaryOperation`, `UnaryOperation`, `TernaryOperation`) plus the
  `BinaryOperator` / `UnaryOperator` enums and the `ExpressionParser`
  itself. Short-circuit evaluation for `&&` / `||`; division /
  modulo by zero raise `PluralResolutionException`.
- `G\Translator\Runtime\Plural\CompositePluralResolver`: delegates to
  the Gettext resolver when an expression is registered for the
  locale, falls back to CLDR otherwise. Stateless wrapper.
- `G\Translator\Runtime\Translator`: canonical
  `TranslatorInterface` implementation. Orchestrator only — no
  translation logic beyond the locale-chain walk and resolver
  delegation. `injectCountIfNeeded()` private helper auto-injects the
  count into named placeholders (`{count}`, `%count%`) for `plural()`,
  never for positional ones. Locale override is applied via a
  `clone` of the configured fallback so the chain and auto-parent
  setting are preserved.

#### Phase A — Foundation layer

- `G\Translator\Bag\TranslationEntry`: canonical immutable implementation
  of `TranslationEntryInterface`. `final readonly` value object with
  constructor-time invariant enforcement (`id` / `domain` non-empty,
  `context` either `null` or non-empty, mandatory `OTHER` category
  whenever any plural form is present) and `with*()` mutators returning
  fresh instances. Composite key encoded as
  `{domain}::{context|__none__}::{id}` via the public static
  `buildKey()` helper.
- `G\Translator\Bag\TranslationBag`: canonical mutable implementation of
  `TranslationBagInterface`. Backed by an associative array keyed on
  the composite key, preserves insertion order, refuses to merge bags
  whose locales mismatch, resolves collisions through `MergeStrategy`
  (`THROW_ON_CONFLICT` / `REPLACE` / `KEEP_EXISTING`).
- `G\Translator\Runtime\LocaleNormalizer`: pure static utility producing
  the canonical BCP-47 form of any accepted locale string
  (`pl_PL` / `pl-pl` / `PL-PL` → `pl-PL`) and computing the parent
  locale by trimming the rightmost subtag. Single source of truth for
  locale handling across the package.
- `G\Translator\Runtime\LocaleFallback`: canonical implementation of
  `LocaleFallbackInterface`. Maintains a primary locale, an explicit
  fallback chain (auto-normalized, deduped, with the primary stripped)
  and an opt-in automatic parent expansion. `chain()` returns the
  deduplicated resolution chain as a `non-empty-list<non-empty-string>`.
- `G\Translator\Runtime\BagRegistry`: canonical locale → bag registry
  with strict `get()` and lenient `find()` accessors, normalized locale
  keys, and replace-on-re-register semantics for hot-reload scenarios.
- `G\Translator\Exception\Bag\InvalidEntryException`: new bag-level
  exception thrown by `TranslationEntry` when its structural invariants
  are violated at construction time.

### Tooling

- Added `autoload-dev` PSR-4 mapping `G\Translator\Tests\` → `tests/`
  to `composer.json`. The production `require` section is unchanged.

### Tested

- 335 PHPUnit tests / 583 assertions covering all Phase A → D
  classes. Highlights:
  - Phase A: design-documentation tests for the
    PHPDoc-as-validation trade-off, garbage-in-garbage-out semantics,
    and composite-key disambiguation under `THROW_ON_CONFLICT`.
  - Phase B: ~60 CLDR test cases across every supported locale,
    canonical Gettext expressions for English / French / Russian /
    Polish / Arabic / Japanese, a dedicated security suite asserting
    that `phpinfo()`, `system(...)`, `$n`, assignments, string
    literals, backticks, array syntax, and other PHP-flavoured
    inputs are rejected by `ExpressionParser`.
- PHPStan level `max` analysis is clean (zero errors, zero baseline)
  across both phases.
