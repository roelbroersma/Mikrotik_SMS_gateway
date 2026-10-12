<?php

/* Only this CLI worker contacts the modem. Restarting it never clears the RAM budget. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/gateway.php';

$last_error = '';
$last_report = 0;
do {
    try {
        gateway_tick();
        $last_error = '';
    } catch (Throwable $e) {
        // Avoid flooding container logs when configuration or the RAM mount is unavailable.
        if ($e->getMessage() !== $last_error || time() - $last_report >= 60) {
            fwrite(STDERR, gateway_iso(time()) . ' SMS worker stopped sending: ' . $e->getMessage() . PHP_EOL);
            $last_error = $e->getMessage();
            $last_report = time();
        }
    }
    if (in_array('--once', $argv, true)) break;
    usleep(250000);
} while (true);
