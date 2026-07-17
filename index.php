<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'PaypalRefund\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

return new PaypalRefund\PaypalRefundPlugin();
