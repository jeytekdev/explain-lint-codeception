<?php

declare(strict_types=1);

namespace ExplainLint\Codeception;

use Codeception\Test\Descriptor;
use Codeception\Test\Interfaces\Descriptive;

/**
 * Mirrors ExplainLint\PHPUnit\TestValueAdapter: every access goes through
 * here, defensively, so this degrades to a best-effort identifier instead of
 * fatal-erroring on a Codeception test type (Cest, Unit, Gherkin, ...) that
 * doesn't implement `Descriptive`. `Codeception\Test\Test` — the common base
 * for Cest/TestCaseWrapper — does implement it (`Descriptive extends
 * SelfDescribing`), so this covers every stock Codeception test format.
 */
final class TestDescriptor
{
    public static function id(object $test): string
    {
        if ($test instanceof Descriptive) {
            return Descriptor::getTestSignatureUnique($test);
        }

        return spl_object_hash($test);
    }

    public static function name(object $test): string
    {
        if ($test instanceof Descriptive) {
            return Descriptor::getTestAsString($test);
        }

        return self::id($test);
    }
}
