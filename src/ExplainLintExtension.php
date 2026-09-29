<?php

declare(strict_types=1);

namespace ExplainLint\Codeception;

use Codeception\Event\PrintResultEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension;
use ExplainLint\Adapter\MySqlAdapter;
use ExplainLint\Adapter\PostgresAdapter;
use ExplainLint\Adapter\SqliteNoopAdapter;
use ExplainLint\Config\Config;
use ExplainLint\Config\ConfigLoader;
use ExplainLint\Engine\ExplainRunner;
use ExplainLint\PHPUnit\TestAnalysisRunner;
use ExplainLint\Recorder\QueryLedger;
use ExplainLint\Recorder\QueryRecorder;
use ExplainLint\Report\ConsoleReporter;
use ExplainLint\Report\GithubAnnotationsReporter;
use ExplainLint\Report\JUnitReporter;
use ExplainLint\Report\ResultCollector;
use ExplainLint\Rules\RuleEngine;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Codeception-native equivalent of ExplainLint\PHPUnit\ExplainLintExtension.
 *
 * Codeception 5 does not read `phpunit.xml` and does not bootstrap PHPUnit's
 * native `Runner\Extension\Extension` mechanism — `Codeception\Suite` builds
 * an in-memory `DefaultConfiguration` purely so `PHPUnit\Framework\TestCase`
 * has a configuration to consult, and drives every test through its own
 * `Suite::run()` / Symfony `EventDispatcher`, never through
 * `PHPUnit\TextUI\Application` (the only thing that parses `<extensions>`
 * and calls `Extension::bootstrap()`). So the PHPUnit extension's subscribers
 * are simply never registered when tests run via `codecept run`, regardless
 * of whether a `phpunit.xml` exists. This class reuses the exact same
 * analysis/reporting pipeline (`TestAnalysisRunner`, `ResultCollector`, the
 * reporters) but wires it to Codeception's own event dispatcher instead.
 *
 * Register in codeception.yml:
 *
 *   extensions:
 *       enabled:
 *           - ExplainLint\Codeception\ExplainLintExtension
 *       config:
 *           ExplainLint\Codeception\ExplainLintExtension:
 *               config: explain-lint.php
 */
final class ExplainLintExtension extends Extension
{
    /**
     * @var array<string, string>
     */
    public static array $events = [
        Events::TEST_BEFORE => 'onTestBefore',
        Events::TEST_END => 'onTestEnd',
        Events::RESULT_PRINT_AFTER => 'onResultPrintAfter',
    ];

    private Config $explainLintConfig;

    private QueryRecorder $recorder;

    private TestAnalysisRunner $analysisRunner;

    private ResultCollector $results;

    /**
     * `Extension::$config` (the raw config array from codeception.yml) is
     * only populated by the parent constructor, which runs before this — so
     * the resolved ExplainLint\Config\Config object is built here, in a
     * differently-named property, rather than in the constructor.
     */
    public function _initialize(): void
    {
        parent::_initialize();

        $configPath = ConfigLoader::resolvePath(
            isset($this->config['config']) ? (string) $this->config['config'] : null,
            getcwd() ?: '.'
        );
        $this->explainLintConfig = ConfigLoader::load($configPath);

        $this->recorder = QueryRecorder::instance();
        $ledger = new QueryLedger();
        $explainRunner = new ExplainRunner(adapters: [
            new MySqlAdapter(),
            new PostgresAdapter(),
            new SqliteNoopAdapter(),
        ]);
        $ruleEngine = new RuleEngine($this->explainLintConfig);
        $this->results = new ResultCollector();
        $this->analysisRunner = new TestAnalysisRunner($this->recorder, $ledger, $explainRunner, $ruleEngine, $this->results);
    }

    public function onTestBefore(TestEvent $event): void
    {
        // No PHPUnit `Prepared` equivalent exists at the Codeception level:
        // `TestCaseWrapper::test()` calls the wrapped TestCase's `runBare()`
        // directly, which runs setUp()/test body/tearDown() as one opaque
        // unit Codeception has no visibility into. Everything captured
        // between test.before and test.end is analyzed — unlike the PHPUnit
        // extension, this includes setUp()/tearDown() queries.
        $this->recorder->clear();
    }

    public function onTestEnd(TestEvent $event): void
    {
        $test = $event->getTest();
        $this->analysisRunner->analyze(TestDescriptor::id($test), TestDescriptor::name($test));
    }

    public function onResultPrintAfter(PrintResultEvent $event): void
    {
        // Codeception keeps printing its own result summary after this event
        // fires for other subscribers — a shutdown function guarantees the
        // report is the true last thing printed, and that our exit() call
        // (below) doesn't preempt Codeception's own output.
        register_shutdown_function(function (): void {
            $this->render();
        });
    }

    private function render(): void
    {
        if ($this->explainLintConfig->report['console']) {
            (new ConsoleReporter())->report($this->results, new ConsoleOutput());
        }

        $junitPath = $this->explainLintConfig->report['junit'];
        if (is_string($junitPath) && $junitPath !== '') {
            (new JUnitReporter())->write($this->results, $junitPath);
        }

        $githubReporter = new GithubAnnotationsReporter();
        if ($githubReporter->shouldRun((string) $this->explainLintConfig->report['github_annotations'])) {
            $githubReporter->report($this->results);
        }

        if ($this->explainLintConfig->mode === 'strict' && $this->results->hasErrors()) {
            exit(1);
        }
    }
}
