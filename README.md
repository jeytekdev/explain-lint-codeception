# jeytekdev/explain-lint-codeception

Codeception bridge for [jeytekdev/explain-lint](../core/README.md) — re-runs `EXPLAIN` against every query your test suite executes, and fails the build on full table scans, lost indexes, filesort and temporary tables.

## Why this package exists

[`packages/core`](../core/README.md)'s reporting/analysis pipeline is a PHPUnit extension, registered via `<extensions>` in `phpunit.xml` and driven by PHPUnit's native `Runner\Extension`/`Event` system.

**Codeception 5 never reads `phpunit.xml` and never bootstraps that mechanism.** `Codeception\Suite::initPHPUnitConfiguration()` builds an in-memory `DefaultConfiguration` purely so `PHPUnit\Framework\TestCase` has something to consult, and every test runs through `Codeception\Suite::run()` / Codeception's own Symfony `EventDispatcher` — never through `PHPUnit\TextUI\Application`, the only thing that parses `<extensions>` and calls `Extension::bootstrap()`. Concretely: `Codeception\Test\TestCaseWrapper::test()` calls the wrapped `TestCase::runBare()` directly, bypassing the `PHPUnit\Framework\TestRunner\TestRunner` class that emits the `Finished` event the core PHPUnit extension listens for.

So if your project runs tests via `vendor/bin/codecept run` (the default for the Yii2 basic/advanced templates, and common with Laravel/Symfony too) — as opposed to `vendor/bin/phpunit` directly — installing `jeytekdev/explain-lint`'s PHPUnit extension alone does nothing: query capture (via the Laravel/Doctrine/Yii2 bridge) still works, but no `EXPLAIN` ever runs and no report is ever produced, silently.

This package re-implements the same subscriber wiring as a `Codeception\Extension`, registered in `codeception.yml` instead of `phpunit.xml`, using Codeception's own event dispatcher. It reuses `ExplainLint\PHPUnit\TestAnalysisRunner` and `ExplainLint\Report\*` from core unchanged — only the event source differs.

## Install (2 minutes)

```bash
composer require --dev jeytekdev/explain-lint-codeception
```

You still need a capture adapter for your stack — this package only handles analysis/reporting:

- [Laravel](../laravel/README.md) — `jeytekdev/explain-lint-laravel`
- [Symfony / Doctrine DBAL](../doctrine/README.md) — `jeytekdev/explain-lint-doctrine`
- [Yii2](../yii2/README.md) — `jeytekdev/explain-lint-yii2`
- Bare PDO — [`ExplainLint\Pdo\ExplainLintPdo`](../core/README.md#bare-pdo-2-minutes)

Register the extension in `codeception.yml` (or a per-suite `<suite>.suite.yml` if you only want it on one suite):

```yaml
extensions:
    enabled:
        - ExplainLint\Codeception\ExplainLintExtension
    config:
        ExplainLint\Codeception\ExplainLintExtension:
            config: explain-lint.php
```

`config` is optional and defaults to `explain-lint.php` in the working directory `codecept run` is invoked from — same config file format as core, see the [core README](../core/README.md#configuration).

Do **not** also run `vendor/bin/explain-lint explain-lint:install` for a Codeception project — it registers the PHPUnit extension in `phpunit.xml`, which this package makes unnecessary (and which Codeception ignores anyway). Create `explain-lint.php` by hand, or copy `packages/core/stubs/explain-lint.php.stub`.

Then run your suite as usual:

```bash
vendor/bin/codecept run
```

## Known limitation: no setUp()/tearDown() split

The PHPUnit extension only analyzes queries executed inside the test method body itself — `setUp()`/migrations/fixture loading are excluded, via a `PreparationStarted` → `Prepared` phase transition PHPUnit dispatches around `setUp()`.

Codeception has no equivalent signal: `TestCaseWrapper::test()` calls `runBare()` directly, which runs `setUp()`, the test body, and `tearDown()` as one opaque unit Codeception never sees the inside of. This extension therefore analyzes **everything** captured between `test.before` and `test.end` — including `setUp()`/fixture queries. In practice this mostly matters if your fixtures themselves contain a query bad enough to trip a rule; allowlist it by table/fingerprint the same way you would any other finding (see the [core README](../core/README.md#allowlisting-a-known-ok-query)).

## License

MIT
