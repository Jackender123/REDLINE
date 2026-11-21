<?php
/** Exercise the webhook entry point without credentials, network access or a database connection. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

register_shutdown_function(static function (): void {
    if (!function_exists('db')) {
        fwrite(STDERR, "FAIL: Stripe webhook cannot load its database connection helper.\n");
        exit(1);
    }
    if (http_response_code() !== 405) {
        fwrite(STDERR, "FAIL: Stripe webhook did not reject a non-POST request.\n");
        exit(1);
    }
    echo "\nPASS: Stripe webhook bootstraps the database helper and rejects non-POST requests.\n";
});

$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/customer/visa_webhook.php';
