# Contributing to SF-Doctor

Thank you for your interest in SF-Doctor.
This document explains how to contribute to the project.

---

## Prerequisites

- PHP 8.2 or higher
- Composer
- Git

---

## Local setup
```bash
git clone https://github.com/pierre-arthur/sf-doctor.git
cd sf-doctor
composer install
```

Run the test suite:
```bash
vendor/bin/phpunit
```

Run static analysis:
```bash
vendor/bin/phpstan analyse
```

Both must pass before submitting a Pull Request.

---

## Project conventions

### Code style

- PSR-12 coding standard
- PHP 8.2+ features (readonly properties, enums, named arguments)
- All comments in French
- Class names, method names and variable names in English

### Tests

Every contribution must include tests.
New analyzers must be covered by unit tests with a temporary filesystem
(see existing analyzers in `tests/Unit/Analyzer/` for examples).

Minimum coverage expectations:
- Each public method has at least one test
- Both the happy path and the edge cases are covered

### Commits

One commit per logical change.
Use the `feat:`, `fix:`, `test:`, `docs:`, `refactor:` prefixes.

Examples:
```
feat: add RoutingAnalyzer to detect missing route requirements
fix: handle empty YAML files in YamlConfigReader
test: add edge cases for AccessControlAnalyzer
docs: add example output to README
```

---

## Adding a new Analyzer

SF-Doctor uses a tag-based system to discover analyzers automatically.
Any class implementing `AnalyzerInterface` and tagged with `sf_doctor.analyzer`
is picked up by the bundle at compile time.

A dedicated guide is available: [docs/analyzers.md](docs/analyzers.md).
Read it before writing your first analyzer.

---

## Submitting a Pull Request

1. Fork the repository
2. Create a branch: `git checkout -b feat/my-analyzer`
3. Write your code and tests
4. Run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse` - both must pass
5. Open a Pull Request against the `main` branch
6. Describe what your PR does and why

Pull Requests that break existing tests or lower PHPStan level will not be merged.

---

## Questions

Open a GitHub issue with the `question` label.