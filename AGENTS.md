# AGENTS.md – Guidelines for Agentic Coding Assistants

This document defines **mandatory rules** for any automated or semi-automated coding agent (including LLM-based tools such as opencode) that modifies this repository.

The overriding goals are:

- **Deterministic, reviewable diffs**
- **Zero protocol surprises**
- **Explicit, boring PHP code**
- **No “magic” or suppressed errors**

If an agent cannot follow these rules, it should refuse to proceed.

---

## Project Overview

**Survos JsonlBundle** is a Symfony 7.4/8.0 / PHP 8.4 bundle providing:

- Streaming JSONL readers and writers
- Transparent gzip support
- Concurrent-safe writes (Symfony Lock)
- Sidecar-based progress and resumability
- Token indexes and metadata tracking for large datasets

The bundle is used in long-running data pipelines where **correctness and resumability matter more than convenience**.

---

## Supported Environment

- **PHP**: 8.4+
- **Symfony**: 7.4|^8.0
- **Composer**: 2.x
- **Testing**: PHPUnit

---

## CRITICAL: Agent Output Requirements

### 1. Output Format (Non-Negotiable)

Agents **MUST emit plain text output only**.

- ❌ Do NOT emit structured response items
- ❌ Do NOT emit `reasoning`, `analysis`, or multi-item protocols
- ❌ Do NOT rely on streaming item sequences
- ✅ Emit deterministic, reviewable text suitable for patch/diff workflows

If the agent platform forces structured output, **do not run the agent**.

---

### 2. Change Scope and Granularity

- Prefer **small, surgical diffs**
- Do not reformat unrelated code
- Do not introduce opportunistic refactors
- If a full-file rewrite is required, state it explicitly

---

## PHP Coding Standards

### Required in Every PHP File

```php
declare(strict_types=1);
```

### Classes and Types

- Use `final class` unless extension is explicitly required
- Use `readonly` for immutable value objects
- Type all parameters, returns, and properties
- Avoid `mixed` unless absolutely necessary

---

## Global Function Usage (IMPORTANT)

### Required Style

When using PHP built-in functions inside a namespace, **import them explicitly**:

```php
use function json_encode;
use function json_decode;
use function sprintf;
use function fopen;
use function fwrite;
use function fclose;
use function gzopen;
use function gzwrite;
use function gzclose;
use function file_get_contents;
use function file_put_contents;
use function rename;
use function unlink;
use function mkdir;
use function dirname;
use function is_dir;
use function is_file;
use function sha1;
use function strtolower;
use function trim;
use function in_array;
```

### Forbidden Patterns

- ❌ Leading backslashes on global functions  
  (`\json_encode()`, `\fopen()`, etc.)
- ❌ The `@` error suppression operator

All failures must be handled **explicitly**.

---

## Error Handling Rules

- Never suppress warnings or notices
- If a failure matters, throw an exception
- Fail fast on programmer errors
- Prefer `RuntimeException` or `InvalidArgumentException`

Example:

```php
$bytes = fwrite($this->fh, $line);
if ($bytes === false) {
    throw new \RuntimeException(sprintf(
        'Failed writing to "%s".',
        $this->filename
    ));
}
```

---

## File and Directory Responsibilities

- Directory creation and lifecycle are **application concerns**
- IO classes must not silently manage application paths
- Prefer explicit path setup via path services (e.g. DataPaths)

IO utilities may *optionally* create directories **only when explicitly configured**.

---

## API Design Guidelines

- Defaults must be explicit and safe
- Avoid boolean flags in public APIs when semantics matter
- Prefer small value/option objects over associative arrays
- APIs should read clearly at the call site

Example:

```php
JsonlWriter::open(
    $path,
    mode: 'w',
    options: new JsonlWriterOptions(ensureDir: false)
);
```

---

## Bundle Structure

```
src/
├── Command/          # Symfony console commands
├── Contract/         # Interfaces and contracts
├── IO/               # Readers / Writers (core streaming logic)
├── Model/            # Value objects and result models
├── Path/             # Naming strategies and path utilities
├── Service/          # Internal services
├── Util/             # Small helpers
└── SurvosJsonlBundle.php
```

Tests should mirror this structure under `tests/`.

---

## Testing Guidelines

- Tests must be deterministic
- Use temporary directories with random names
- Clean up all filesystem artifacts in teardown
- Prefer real filesystem IO over mocks for IO classes
- Use `#[Test]` and `#[CoversClass]` attributes

---

## Performance and Safety Principles

- Stream data; never load full datasets into memory
- Use atomic writes where applicable
- Protect concurrent writers using Symfony Lock
- Keep gzip transparent to callers
- Ensure sidecar and index consistency on reset/truncate

---

## Security Considerations

- Validate file paths before writing
- Avoid leaking absolute paths in error messages
- Clean up temporary files reliably
- Do not trust external input blindly

---

## Agent Expectations Summary

Agents working on this repository **MUST**:

- Emit plain text only
- Avoid structured or reasoning-based output
- Follow PHP 8.4 / Symfony 7.3 idioms
- Use `use function` imports for built-ins
- Never use `@` error suppression
- Make minimal, reviewable changes

If a requested change would violate these rules, the agent must **ask before proceeding**.

---

## Recommended Agent Configuration

For best results with patch-based workflows:

- **Model**: GPT-5.2 Chat
- **Response format**: text only
- **Reasoning output**: disabled
- **Streaming items**: disabled

This avoids protocol-level errors and ensures stable diffs.

---

## Symfony Console Command Rules (MANDATORY)

These rules apply to **all Symfony console commands** created or modified in this repository.

They exist to enforce consistency, reduce boilerplate, and avoid subtle Symfony conflicts.

---

## Core Requirements

### 1. Use `__invoke()` (Always)

- Commands **must** implement `__invoke()`
- Do **not** implement `execute()`
- Do **not** override `configure()`

```php
final class ExampleCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        // command logic
        return Command::SUCCESS;
    }
}
```

---

### 2. Do **NOT** Extend `Command` Unless Necessary

- **Do not extend** `Symfony\Component\Console\Command\Command` unless you explicitly need:
    - constants such as `Command::SUCCESS`, **or**
    - advanced lifecycle hooks (rare)
- Prefer **plain invokable classes** with the `#[AsCommand]` attribute

If you need the constants, import them statically or reference them explicitly.

Preferred:
```php
use Symfony\Component\Console\Command\Command;

return Command::SUCCESS;
```

Still **do not** extend `Command` unless there is a concrete reason.

---

### 3. Use Attributes for Arguments and Options

- Use PHP attributes (`#[Argument]`, `#[Option]`)
- Define them **directly on `__invoke()` parameters**
- Do not define arguments or options anywhere else

```php
public function __invoke(
    SymfonyStyle $io,
    #[Argument('Input file path')]
    string $input,
    #[Option('Overwrite existing output')]
    bool $force = false,
): int {
```

---

### 4. `AsCommand` Attribute Rules

```php
#[AsCommand(
    'app:example',
    'Short, human-readable description'
)]
```

#### Important:

- ❌ **Never** use a named `description:` argument
- ✅ The description is the **second positional argument**
- ❌ Do not pass `name:` if it matches the PHP filename

**Correct**
```php
#[AsCommand('app:example', 'Do something useful')]
```

**Incorrect**
```php
#[AsCommand(name: 'app:example', description: 'Do something useful')]
```

---

### 5. Argument and Option Rules

#### Arguments

- Arguments are positional
- Description is the **first and only required parameter**
- Defaults make arguments optional

```php
#[Argument('Dataset key')]
?string $dataset = null
```

#### Options

- Options must always have a default
- Options may be nullable **only if default is `null`**

Valid:
```php
#[Option('Limit number of records')]
?int $limit = null
```

Valid:
```php
#[Option('Transport name')]
string $transport = 'sync'
```

Invalid:
```php
#[Option('Transport name')]
?string $transport = 'sync' // ❌ never do this
```

---

### 6. Reserved Parameters (NEVER USE)

Do **not** define parameters named:

- `$verbose`
- `$version`
- `$help`

These are reserved by Symfony and will break commands in subtle ways.

---

### 7. Always Inject `SymfonyStyle`

- `SymfonyStyle $io` must be the **first parameter** of `__invoke()`
- Do not fetch it from the container

```php
public function __invoke(
    SymfonyStyle $io,
    #[Argument('Input file')]
    string $input,
): int {
```

---

### 8. Command Return Value

- Always return a Symfony command status code
- Usually `Command::SUCCESS`

```php
use Symfony\Component\Console\Command\Command;

return Command::SUCCESS;
```

---

## Canonical Command Template (Preferred)

```php
<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:example', 'Example command demonstrating house style')]
final class ExampleCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dataset key')]
        ?string $dataset = null,
        #[Option('Overwrite existing output')]
        bool $force = false,
        #[Option('Limit number of rows')]
        ?int $limit = null,
    ): int {
        // Command logic here

        return Command::SUCCESS;
    }
}
```

---

## Summary (Non-Negotiable)

- Use `__invoke()` only
- Do **not** extend `Command` unless necessary
- Use attributes only
- No named `description:`
- No reserved parameters
- `SymfonyStyle` first
- Explicit defaults for all options

If any of these rules are violated, the command must be rewritten before merging.
