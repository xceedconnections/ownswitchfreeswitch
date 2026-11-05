<?php

declare(strict_types=1);

namespace Ownswitch\Tests;

final class SkipTest extends \Exception
{
}

final class AssertionFailed extends \Exception
{
}

/** Minimal xUnit-style base class (no PHPUnit required). Test methods must start with "test". */
abstract class TestCase
{
    public int $assertions = 0;

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public function runSetUp(): void
    {
        $this->setUp();
    }

    public function runTearDown(): void
    {
        $this->tearDown();
    }

    protected function skip(string $reason): never
    {
        throw new SkipTest($reason);
    }

    protected function fail(string $msg): never
    {
        throw new AssertionFailed($msg);
    }

    protected function assertTrue(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== true) {
            $this->fail($msg ?: 'Expected true, got ' . var_export($v, true));
        }
    }

    protected function assertFalse(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== false) {
            $this->fail($msg ?: 'Expected false, got ' . var_export($v, true));
        }
    }

    protected function assertNull(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== null) {
            $this->fail($msg ?: 'Expected null, got ' . var_export($v, true));
        }
    }

    protected function assertNotNull(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v === null) {
            $this->fail($msg ?: 'Expected non-null');
        }
    }

    /** Strict (===) comparison. */
    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(($msg ? $msg . ': ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($unexpected === $actual) {
            $this->fail(($msg ? $msg . ': ' : '') . 'Did not expect ' . var_export($actual, true));
        }
    }

    protected function assertCount(int $n, array $a, string $msg = ''): void
    {
        $this->assertions++;
        if (count($a) !== $n) {
            $this->fail(($msg ?: 'Count mismatch') . ": expected {$n}, got " . count($a));
        }
    }

    protected function assertContains(mixed $needle, array $hay, string $msg = ''): void
    {
        $this->assertions++;
        if (!in_array($needle, $hay, true)) {
            $this->fail($msg ?: 'Array does not contain ' . var_export($needle, true));
        }
    }

    protected function assertStringContains(string $needle, string $hay, string $msg = ''): void
    {
        $this->assertions++;
        if (!str_contains($hay, $needle)) {
            $this->fail($msg ?: "String does not contain '{$needle}': '{$hay}'");
        }
    }

    protected function assertStringNotContains(string $needle, string $hay, string $msg = ''): void
    {
        $this->assertions++;
        if (str_contains($hay, $needle)) {
            $this->fail($msg ?: "String unexpectedly contains '{$needle}': '{$hay}'");
        }
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $msg = ''): \Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $t) {
            if ($t instanceof $class) {
                return $t;
            }
            $this->fail(($msg ?: 'Wrong exception') . ": expected {$class}, got " . get_class($t) . ': ' . $t->getMessage());
        }
        $this->fail(($msg ?: 'No exception') . ": expected {$class}");
    }
}
