<?php

/**
 * Run every test suite and fail as one command:
 *
 *   php tests/run.php            # all suites
 *   php tests/run.php api web    # only the named ones (suffix .php optional)
 *
 * Each suite is a plain PHP script with its own assertions that exits non-zero
 * when a check fails; this only aggregates their output and exit codes.
 */

declare(strict_types=1);

$suites = glob(__DIR__ . '/*.php') ?: [];
$suites = array_values(array_filter($suites, static fn (string $p): bool => basename($p) !== 'run.php'));
sort($suites);

$wanted = array_slice($argv ?? [], 1);
if ($wanted !== []) {
    $suites = array_values(array_filter(
        $suites,
        static fn (string $p): bool => in_array(basename($p, '.php'), $wanted, true)
    ));
}

if ($suites === []) {
    fwrite(STDERR, "No test suite matched.\n");
    exit(1);
}

$failing = [];
foreach ($suites as $suite) {
    $output = [];
    $status = 0;
    exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($suite)), $output, $status);
    echo str_pad(basename($suite, '.php'), 14), ' ';
    if ($status === 0) {
        echo ($output === [] ? 'no output' : (string) end($output)), "\n";
        continue;
    }
    echo "FAILED\n", implode("\n", $output), "\n";
    $failing[] = basename($suite, '.php');
}

printf("\n%d suite(s), %d failed\n", count($suites), count($failing));
exit($failing === [] ? 0 : 1);
