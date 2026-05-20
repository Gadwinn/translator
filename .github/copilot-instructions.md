# Copilot Instructions — `gadwinn/translator`

This document is the **single source of truth** for AI coding assistants (Copilot, Claude, etc.) and human contributors. Every rule here is binding for any code submitted to the repository.

---

## 1. Package Identity

- **Name**: `gadwinn/translator` (Packagist)
- **Root namespace**: `G\Translator\`
- **License**: MIT
- **PHP requirement**: `^8.3`
- **Versioning**: Strict SemVer 2.0. Public API = every `public` / `protected` member of every interface, enum, value object, and final class under `src/`.

---

## 2. Core Philosophy

The package is built on four non-negotiable principles:

| Principle | Concretely means |
|---|---|
| **Zero-dependency** | NO `require` in production `composer.json` outside of PHP itself and standard extensions (`mbstring`, `json`, `curl`). NO PSR adapter unless implemented internally as a thin maison interface. NO Guzzle, NO Symfony components, NO `league/*`, NO `psr/*`. |
| **Pure PHP / framework-agnostic** | The library MUST work in isolation. No dependency on Symfony, Laravel, WordPress, or any framework. Adapters for those frameworks live in separate packages. |
| **SOLID** | Especially Interface Segregation and Dependency Inversion. Every collaborator is injected; nothing is `new`-ed inside business code except in factories explicitly named as such. |
| **KISS / YAGNI** | No speculative abstractions. A class or method exists only when there is a concrete use case for it. Premature features are rejected. |

---

## 3. Layered Architecture and DAG Rule

The codebase is structured in seven internal layers. The dependency graph between them is a **strict DAG** (no cycles). Imports flow in **one direction only**:

```
Bridge → Runtime → Engine → Bag → Io → (Exception, Enum)
                                    ↑
                                   Http (transverse utility)
                                   Functions (opt-in procedural shell on top of Runtime)
```

### The DAG Rule (binding)

> **No class in a "downstream" layer (e.g., `Io`) may reference (`use`) a class from an "upstream" layer (e.g., `Runtime` or `Bridge`).**

Allowed dependencies, layer by layer:

| Layer | May depend on |
|---|---|
| `Exception/*` | nothing (pure PHP) |
| `Enum/*` | nothing (pure PHP) |
| `Http/*` | `Exception/Bridge/*`, `Http/Enum/*` |
| `Io/*` | `Exception/Io/*`, `Enum/*`, `Bag/*` (interfaces only) |
| `Bag/*` | `Exception/Bag/*`, `Exception/Engine/*`, `Enum/*` |
| `Engine/*` | `Exception/Engine/*`, `Exception/Io/*`, `Enum/*`, `Bag/*`, `Io/*` |
| `Runtime/*` | `Exception/Runtime/*`, `Exception/Bag/*`, `Enum/*`, `Bag/*` |
| `Bridge/Ai/*` | `Exception/Bridge/*`, `Enum/*`, `Bag/*`, `Http/*` |
| `Functions/*` | `Runtime/*`, `Exception/Configuration/*` |

### Why this matters

The DAG enables the codebase to be split into multiple Composer packages later (e.g., `gadwinn/translator-io` + `gadwinn/translator-runtime` + `gadwinn/translator-ai`) without any refactoring beyond moving directories and updating `composer.json`. **Even if the split never happens, the discipline keeps each layer reasonable in scope.**

---

## 4. Coding Standards

### 4.1 PHP language features

- **`declare(strict_types=1);` is mandatory** at the top of every `.php` file.
- **`readonly class`** for every Value Object (PHP 8.3+).
- **Constructor property promotion** is the default; long-form constructors are tolerated only when the body must do non-trivial validation.
- **Named arguments** are encouraged on calls with more than two parameters.
- **`match` over `switch`** for value-based branching.
- **Typed class constants** (`public const string FOO = 'foo';`) — PHP 8.3+.
- **No magic methods** (`__get`, `__set`, `__call`) except `__toString` on Stringable-friendly value objects.

### 4.2 Documentation

- **Every interface, enum, and class MUST carry a docblock** describing its role, invariants, and expected behavior.
- **Every method on an interface MUST document**: `@param` for every argument, `@return` for the return type, and **at least one `@throws`** referencing only exceptions of the package (`TranslatorExceptionInterface`).
- **All docblocks are written in English**, no exceptions.
- **PHPStan-compatible array shapes** (`array<int|string, scalar|Stringable>`, `list<...>`, `non-empty-list<...>`, `non-empty-string`) are used wherever they sharpen typing.

### 4.3 Naming

- **Interfaces** end with `Interface` (`ParserInterface`, `TranslatorInterface`).
- **Abstract classes** are named after their category (`IoException`, `BridgeException`).
- **Concrete final classes** are descriptive and `final` by default (`ParserException`, `FileSource`).
- **Enums** are `UPPER_CASE` for case names, `lower_snake_case` for backing strings.
- **Value objects** carry no `Dto`, `Model`, or `Object` suffix (`TranslationRequest`, not `TranslationRequestDto`).

### 4.4 Exception hierarchy

- The root contract is `G\Translator\Exception\TranslatorExceptionInterface` (marker interface, no methods).
- Every package exception MUST implement it (directly or via an abstract category class).
- Concrete exception classes are `final`. Abstract category classes (`IoException`, `EngineException`, …) extend `\RuntimeException` or `\LogicException` depending on whether the failure is environmental or programmatic.
- **Never throw a built-in PHP exception (`\InvalidArgumentException`, `\RuntimeException` directly)** from package code. Always use a package exception.

---

## 5. Core API Contracts (synthetic reminder)

| Concept | Contract | Notes |
|---|---|---|
| Reading source | `SourceInterface` | `read(): string`, `identifier(): string`, `extension(): ?string`. |
| Parsing | `ParserInterface` | `parse(Source, locale, domain = 'messages'): TranslationBag`. Stateless. |
| Serializing | `SerializerInterface` | `serialize(Bag, ?domain): string`. Does NOT persist; the caller does. |
| Auto-detection | `DetectorInterface` | Factory + Registry over `Format`. |
| Entry | `TranslationEntryInterface` | Immutable. Identity = `(id, domain, context)`. |
| Bag | `TranslationBagInterface` | Mutable. Bound to one locale. `add()` strict, `set()` idempotent. |
| Merging | `MergeStrategy` enum | Default = `THROW_ON_CONFLICT` (fail-fast). Other cases: `REPLACE`, `KEEP_EXISTING`. |
| Stacking | `StackEngineInterface` | `add(StackedSource)`, `resolve(locale, strategy)`. |
| Runtime | `TranslatorInterface` | Methods: `trans()`, `plural()`. Named arguments. Default strict mode OFF. |
| Locale fallback | `LocaleFallbackInterface` | Explicit chain + opt-in auto-parent (BCP-47 dropdown). |
| Plurals | CLDR (default) + Gettext expression (when present in PO header). |
| Placeholders | 4 syntaxes: `%name%`, `{name}`, `%s`/`%d`, `%1$s`. Mixing families in one string is undefined. Escape with `%%`, `{{`, `}}`. |
| Multi-locale | `BagRegistryInterface` (locale → Bag). |
| AI | `AiProviderInterface` (`translate`, `translateBatch`). |
| AI orchestration | `BagTranslatorInterface` (`translateBag` Bag → Bag end-to-end). |
| HTTP | `HttpClientInterface` (`send`, `sendMany`). Maison contract, default impl based on `ext-curl`. |

---

## 6. Specific Behavior Rules

- **`MergeStrategy::THROW_ON_CONFLICT` is the default** for `Bag::merge()` and `StackEngine::resolve()`. Silent overrides require an explicit `REPLACE` or `KEEP_EXISTING`.
- **`Translator::strict` defaults to OFF**. Missing translations return the source identifier (or its plural fallback) silently. Turning strict ON throws `MissingTranslationException`.
- **Locale auto-parent fallback defaults to OFF**. It must be enabled explicitly via `LocaleFallback::setAutoParent(true)`.
- **`Translator::plural()` auto-injects `$count` into the `count` placeholder key** for NAMED syntaxes only (`{count}`, `%count%`). Positional placeholders (`%s`, `%d`) are NEVER auto-filled.
- **Placeholder values MUST be `scalar` or `Stringable`.** `null`, arrays, plain objects, and resources throw `InvalidPlaceholderValueException`. The package never silently coerces them to an empty string.
- **`AiProviderInterface::translateBatch()` policy** (fail-fast vs. collect-and-throw) is implementation-defined and MUST be documented per provider.
- **`BagTranslator::translateBag()` returns a NEW bag**, never mutates the source.
- **`HttpClient` returns `HttpResponse` for ANY HTTP status code**. Only transport-level failures throw `BridgeException`.

---

## 7. Procedural API (opt-in)

The procedural API lives in `src/Functions/translate.php` and is opt-in via the consumer's `composer.json` `"autoload": { "files": [...] }`.

It exposes eight global functions inspired by GNU Gettext: `__`, `__d`, `__n`, `__p`, `__dn`, `__dp`, `__np`, `__dnp`. Argument order follows the historical Gettext convention for tool compatibility (Poedit, xgettext, …).

The procedural API is backed by `G\Translator\Functions\TranslatorRegistry`, which is the **only piece of global state in the entire package**. The object-oriented core (`TranslatorInterface` and its collaborators) has none.

---

## 8. Forbidden Patterns

The following are not negotiable and MUST be rejected in code review:

- Adding any package to the production `require` section of `composer.json` (only PHP and standard extensions allowed).
- Using `\InvalidArgumentException`, `\RuntimeException`, `\LogicException` directly from package code.
- Calling any function from `Functions/translate.php` from inside the package itself (`src/`). The procedural API is for consumers only.
- Using `static` properties to hold business state. Only `TranslatorRegistry` is allowed.
- Using `eval()`, `exec()`, `shell_exec()`, dynamic class instantiation via `new $variable()`, or `__call` magic.
- Hardcoding API keys, URLs, or any secret in source code.
- Mixing two placeholder families in the same translated string.
- Mutating an upstream layer from a downstream layer (DAG violation).
- Adding tests for code that does not yet exist (test-first is welcome, but the corresponding contract must land in the same PR).

---

## 9. Tests and Static Analysis

- **PHPUnit** for tests. Test classes live under `tests/`, namespace `G\Translator\Tests\`.
- **PHPStan level 9 (or `max`)** with `phpstan/phpstan-strict-rules`. Zero baseline tolerance: every new violation must be fixed, not suppressed.
- Every interface introduced MUST have a contract test (a test that any conforming implementation can run against itself).
- The DAG rule SHOULD be enforced by an automated check (custom PHPStan rule or a small CI script that greps `use` statements).

---

## 10. Commits and Releases

- **Conventional Commits** format: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, etc.
- **Pre-1.0 versions** (`0.x.y`) MAY break public APIs without bumping major. Each breaking change MUST be explicitly listed in the changelog.
- **Post-1.0** strictly follows SemVer 2.0. A breaking change requires a major bump and a migration note.
