<?php

declare(strict_types=1);

/**
 * Royal Spin test runner.
 *
 *   php tests/run.php                    # everything
 *   php tests/run.php SlotEngineTest     # one suite
 *
 * Database-backed suites create their own throwaway SQLite file per test, so
 * nothing here touches your development database.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use RoyalSpin\Tests\TestCase;

require __DIR__ . '/TestCase.php';
require __DIR__ . '/DatabaseTestCase.php';

$filter = $argv[1] ?? null;

$suites = [];
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    $class = 'RoyalSpin\\Tests\\' . basename($file, '.php');
    if ($filter !== null && !str_contains(basename($file, '.php'), $filter)) {
        continue;
    }
    require $file;
    if (class_exists($class)) {
        $suites[] = $class;
    }
}

if ($suites === []) {
    fwrite(STDERR, "No test suites matched." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "\n\033[1mROYAL SPIN — TEST SUITE\033[0m\n");

$totalPassed = 0;
$totalFailed = 0;
$started     = microtime(true);

foreach ($suites as $class) {
    fwrite(STDOUT, "\n\033[1m" . str_replace('RoyalSpin\\Tests\\', '', $class) . "\033[0m\n");

    /** @var TestCase $suite */
    $suite  = new $class();
    $result = $suite->run();

    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
}

$elapsed = microtime(true) - $started;

fwrite(STDOUT, "\n" . str_repeat('-', 62) . "\n");
if ($totalFailed === 0) {
    fwrite(STDOUT, sprintf(
        "\033[32mPASS\033[0m  %d tests, %d assertions in %.2fs\n\n",
        $totalPassed,
        TestCase::$assertions,
        $elapsed
    ));
    exit(0);
}

fwrite(STDOUT, sprintf(
    "\033[31mFAIL\033[0m  %d passed, %d failed, %d assertions in %.2fs\n\n",
    $totalPassed,
    $totalFailed,
    TestCase::$assertions,
    $elapsed
));
foreach (TestCase::$failures as $failure) {
    fwrite(STDOUT, "  · {$failure}\n");
}
fwrite(STDOUT, "\n");
exit(1);
