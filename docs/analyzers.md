# Creating a custom Analyzer

This guide explains how to add a new analyzer to SF-Doctor.

---

## How analyzers work

SF-Doctor uses a tag-based discovery system.
At container compile time, every service tagged with `sf_doctor.analyzer`
is injected into `AuditCommand` via a `TaggedIterator`.

If you use SF-Doctor as a Symfony bundle, tagging is automatic:
any class implementing `AnalyzerInterface` is autoconfigured with the tag.
You only need to implement the interface.

---

## The AnalyzerInterface
```php
namespace PierreArthur\SfDoctor\Analyzer;

use PierreArthur\SfDoctor\Model\AuditReport;

interface AnalyzerInterface
{
    public function supports(string $projectPath): bool;
    public function analyze(string $projectPath, AuditReport $report): void;
}
```

### `supports(string $projectPath): bool`

Called before `analyze()`.
Return `true` if this analyzer can run on the given project.
Return `false` to skip silently (no error, no output).

Use `supports()` to check for the presence of a required dependency or file.
Examples:
- Check `class_exists(SecurityBundle::class)` before analyzing security config
- Check that `$projectPath . '/src/Form'` exists before scanning Form classes
- Check that `.env` exists before reading environment variables

### `analyze(string $projectPath, AuditReport $report): void`

Contains the analysis logic.
Add issues to the report via `$report->addIssue()`.

Never throw exceptions for missing optional files.
Use `supports()` to guard against missing prerequisites.

---

## The Issue model
```php
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Model\Module;

$report->addIssue(new Issue(
    severity: Severity::CRITICAL,
    module:   Module::SECURITY,
    message:  'CSRF protection is globally disabled in framework.yaml.',
    file:     'config/packages/framework.yaml',
));
```

### Severity

| Value | Meaning | Score impact |
|-------|---------|-------------|
| `Severity::CRITICAL` | Must be fixed before production | -20 |
| `Severity::WARNING` | Should be reviewed | -5 |
| `Severity::INFO` | Informational, no action required | 0 |

### Module

Use the module that best describes what your analyzer checks:

| Value | Use case |
|-------|---------|
| `Module::SECURITY` | Authentication, authorization, CSRF, secrets |
| `Module::ARCHITECTURE` | Controller logic, service responsibilities |
| `Module::CONFIGURATION` | Environment variables, debug mode, framework config |
| `Module::PERFORMANCE` | Caching, lazy loading, query count |

Add a new `Module` case if none of the existing ones fits.

---

## Minimal example

Here is a complete analyzer that detects `APP_ENV=dev` in `.env.prod`:
```php
<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Configuration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class EnvAnalyzer implements AnalyzerInterface
{
    public function supports(string $projectPath): bool
    {
        return file_exists($projectPath . '/.env.prod')
            || file_exists($projectPath . '/.env');
    }

    public function analyze(string $projectPath, AuditReport $report): void
    {
        $file = $projectPath . '/.env.prod';

        if (!file_exists($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            if (preg_match('/^APP_ENV\s*=\s*["\']?dev["\']?/', $line)) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module:   Module::CONFIGURATION,
                    message:  'APP_ENV is set to "dev" in .env.prod.',
                    file:     '.env.prod',
                ));
                return;
            }
        }
    }
}
```

---

## File structure conventions

Place your analyzer in the directory that matches its module:
```
src/
  Analyzer/
    Security/        # Module::SECURITY
    Architecture/    # Module::ARCHITECTURE
    Configuration/   # Module::CONFIGURATION
    Performance/     # Module::PERFORMANCE
```

---

## Tests

Unit tests for analyzers live in `tests/Unit/Analyzer/`.

Analyzers that read the filesystem need real temporary directories.
Use `sys_get_temp_dir() . '/' . uniqid('sf_doctor_test_', true)` to create an
isolated directory for each test, and delete it in `tearDown()`.

See `tests/Unit/Analyzer/Security/CsrfAnalyzerTest.php` for a complete example.

---

## Checklist before opening a Pull Request

- [ ] The analyzer implements `AnalyzerInterface`
- [ ] `supports()` returns `false` when the prerequisite is missing
- [ ] Unit tests cover both `supports()` and `analyze()`
- [ ] Tests cover the happy path and at least one edge case (missing file, empty config)
- [ ] `vendor/bin/phpunit` passes
- [ ] `vendor/bin/phpstan analyse` passes at level 8