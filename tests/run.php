<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$appRoot = getenv('LECONFE_APP_PATH') ?: dirname($pluginRoot, 2);
$phpunit = $appRoot.'/vendor/bin/phpunit';

if (! is_file($phpunit)) {
    fwrite(STDERR, sprintf(
        "LECONFE_APP_PATH is invalid. Expected PHPUnit at [%s].\n",
        $phpunit
    ));

    exit(1);
}

$command = sprintf(
    '%s %s -c %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($phpunit),
    escapeshellarg($pluginRoot.'/phpunit.xml'),
);

passthru($command, $exitCode);

exit($exitCode);
