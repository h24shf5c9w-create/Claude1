<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

/**
 * Minimal assertion + runner framework.
 *
 * The project has no Composer dependencies on purpose, so rather than pulling
 * in PHPUnit the suite ships its own ~100-line runner. Same discipline: one
 * assertion helper set, isolated fixtures, a clear pass/fail summary.
 */
abstract class TestCase
{
    public static int $assertions = 0;

    /** @var list<string> */
    public static array $failures = [];

    private string $currentTest = '';

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    /** @return array{passed:int, failed:int} */
    public function run(): array
    {
        $passed = 0;
        $failed = 0;

        foreach (get_class_methods($this) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            $this->currentTest = static::class . '::' . $method;

            try {
                $this->setUp();
                $this->{$method}();
                $this->tearDown();
                $passed++;
                fwrite(STDOUT, "  \033[32m✓\033[0m " . $this->label($method) . PHP_EOL);
            } catch (AssertionFailed $failure) {
                $failed++;
                self::$failures[] = $this->currentTest . ': ' . $failure->getMessage();
                fwrite(STDOUT, "  \033[31m✗\033[0m " . $this->label($method) . PHP_EOL);
                fwrite(STDOUT, "      " . $failure->getMessage() . PHP_EOL);
                $this->safeTearDown();
            } catch (\Throwable $exception) {
                $failed++;
                self::$failures[] = $this->currentTest . ': ' . $exception->getMessage();
                fwrite(STDOUT, "  \033[31m✗\033[0m " . $this->label($method) . PHP_EOL);
                fwrite(STDOUT, '      ' . $exception::class . ': ' . $exception->getMessage()
                    . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine() . PHP_EOL);
                $this->safeTearDown();
            }
        }

        return ['passed' => $passed, 'failed' => $failed];
    }

    private function safeTearDown(): void
    {
        try {
            $this->tearDown();
        } catch (\Throwable) {
            /* the test already failed; don't mask it */
        }
    }

    private function label(string $method): string
    {
        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', substr($method, 4)) ?? $method;
        return strtolower(trim(str_replace('_', ' ', $words)));
    }

    /* ------------------------------------------------------- assertions */

    protected function assertTrue(mixed $value, string $message = 'Expected true'): void
    {
        self::$assertions++;
        if ($value !== true) {
            throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    protected function assertFalse(mixed $value, string $message = 'Expected false'): void
    {
        self::$assertions++;
        if ($value !== false) {
            throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = 'Values differ'): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                '%s — expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = 'Values differ'): void
    {
        self::$assertions++;
        if ($expected != $actual) {
            throw new AssertionFailed(sprintf(
                '%s — expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    protected function assertFloatEquals(float $expected, float $actual, float $epsilon = 1e-9, string $message = 'Floats differ'): void
    {
        self::$assertions++;
        if (abs($expected - $actual) > $epsilon) {
            throw new AssertionFailed(sprintf('%s — expected %.10f, got %.10f', $message, $expected, $actual));
        }
    }

    protected function assertGreaterThan(float $threshold, float $actual, string $message = 'Not greater'): void
    {
        self::$assertions++;
        if ($actual <= $threshold) {
            throw new AssertionFailed(sprintf('%s — %.6f is not greater than %.6f', $message, $actual, $threshold));
        }
    }

    protected function assertLessThan(float $threshold, float $actual, string $message = 'Not less'): void
    {
        self::$assertions++;
        if ($actual >= $threshold) {
            throw new AssertionFailed(sprintf('%s — %.6f is not less than %.6f', $message, $actual, $threshold));
        }
    }

    protected function assertNull(mixed $value, string $message = 'Expected null'): void
    {
        self::$assertions++;
        if ($value !== null) {
            throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    protected function assertNotNull(mixed $value, string $message = 'Expected a value'): void
    {
        self::$assertions++;
        if ($value === null) {
            throw new AssertionFailed($message);
        }
    }

    protected function assertCount(int $expected, array $actual, string $message = 'Wrong count'): void
    {
        self::$assertions++;
        if (count($actual) !== $expected) {
            throw new AssertionFailed(sprintf('%s — expected %d, got %d', $message, $expected, count($actual)));
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = 'Substring missing'): void
    {
        self::$assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(sprintf('%s — "%s" not found in "%s"', $message, $needle, $haystack));
        }
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }
}

final class AssertionFailed extends \RuntimeException
{
}
