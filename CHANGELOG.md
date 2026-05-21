# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pre-1.0 versions (`0.x.y`) MAY break public APIs without bumping major.
Each breaking change is explicitly listed in its section.

## [Unreleased]

### Added

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

- 264 PHPUnit tests / 340 assertions covering all Phase A, Phase B,
  and Phase C classes. Highlights:
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
