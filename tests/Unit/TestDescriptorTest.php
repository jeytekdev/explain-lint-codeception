<?php

declare(strict_types=1);

namespace ExplainLint\Codeception\Tests\Unit;

use Codeception\Test\Interfaces\Descriptive;
use ExplainLint\Codeception\TestDescriptor;
use PHPUnit\Framework\TestCase;

final class TestDescriptorTest extends TestCase
{
    public function testIdAndNameUseTheDescriptiveContractWhenAvailable(): void
    {
        $test = new class implements Descriptive {
            public function getFileName(): string
            {
                return '/tests/unit/OrdersCestTest.php';
            }

            public function getSignature(): string
            {
                return 'OrdersCestTest:testPendingOrders';
            }

            public function toString(): string
            {
                return 'OrdersCestTest: testPendingOrders';
            }
        };

        self::assertSame('OrdersCestTest:testPendingOrders', TestDescriptor::id($test));
        self::assertSame('OrdersCestTest: testPendingOrders', TestDescriptor::name($test));
    }

    public function testIdAndNameFallBackToObjectHashForUnknownTestTypes(): void
    {
        $test = new class {
        };

        self::assertSame(spl_object_hash($test), TestDescriptor::id($test));
        self::assertSame(spl_object_hash($test), TestDescriptor::name($test));
    }
}
