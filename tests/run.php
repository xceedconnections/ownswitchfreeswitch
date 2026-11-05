<?php

declare(strict_types=1);

/**
 * Dependency-free test runner.
 *   php tests/run.php                 run everything
 *   php tests/run.php Billing         run test files whose path contains "Billing"
 *   php tests/run.php --verbose       print each passing test
 * Exit code 0 = all passed (skips allowed), 1 = failures/errors.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

// Tests must never touch a real database or a developer's .env.
putenv('OWNSWITCH_ENV_FILE=' . __DIR__ . '/.nonexistent.env');
putenv('APP_KEY=0123456789abcdef0123456789abcdef0123456789abcdef');
putenv('APP_DEBUG=true');

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/TestCase.php';

use Ownswitch\Tests\AssertionFailed;
use Ownswitch\Tests\SkipTest;
use Ownswitch\Tests\TestCase;

$verbose = in_array('--verbose', $argv, true);
$filter = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a !== '--verbose') {
        $filter = $a;
    }
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), 'Test.php') && ($filter === null || str_contains(str_replace('\\', '/', $f->getPathname()), $filter))) {
        $files[] = $f->getPathname();
    }
}
sort($files);

$passed = $failed = $skipped = $assertions = 0;
$failures = [];
$start = microtime(true);

foreach ($files as $file) {
    $before = get_declared_classes();
    require_once $file;
    $classes = array_diff(get_declared_classes(), $before);
    foreach ($classes as $class) {
        if (!is_subclass_of($class, TestCase::class)) {
            continue;
        }
        $rc = new ReflectionClass($class);
        if ($rc->isAbstract()) {
            continue;
        }
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if (!str_starts_with($m->getName(), 'test')) {
                continue;
            }
            $name = $rc->getShortName() . '::' . $m->getName();
            /** @var TestCase $t */
            $t = new $class();
            try {
                $t->runSetUp();
                $m->invoke($t);
                $t->runTearDown();
                $passed++;
                if ($verbose) {
                    echo "  PASS  {$name}\n";
                }
            } catch (SkipTest $s) {
                $skipped++;
                echo "  SKIP  {$name}: {$s->getMessage()}\n";
            } catch (AssertionFailed $e) {
                $failed++;
                $failures[] = [$name, $e->getMessage(), $e->getTrace()[1] ?? $e->getTrace()[0] ?? []];
                echo "  FAIL  {$name}\n";
            } catch (Throwable $e) {
                $failed++;
                $failures[] = [$name, get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]];
                echo "  ERROR {$name}\n";
            }
            $assertions += $t->assertions;
        }
    }
}

echo "\n";
foreach ($failures as [$n, $msg, $loc]) {
    echo "--- {$n}\n    {$msg}\n";
    if (!empty($loc['file'])) {
        echo '    at ' . $loc['file'] . ':' . ($loc['line'] ?? '?') . "\n";
    }
}
printf("\nTests: %d passed, %d failed, %d skipped | assertions: %d | %.2fs\n", $passed, $failed, $skipped, $assertions, microtime(true) - $start);
exit($failed > 0 ? 1 : 0);
