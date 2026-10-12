<?php

/*
 ########################################################################
 # FILE:         SENDSMS.PHP                                             #
 # AUTHOR:       ROEL BROERSMA                                           #
 # DESCRIPTION:  THIS FILE CAN BE RUN FROM A PHP HOST OR FROM DOCKER      #
 #               AND WILL WORK AS A REST API TO QUEUE A SMS FOR A         #
 #               MIKROTIK DEVICE. WORKER.PHP SENDS THE QUEUE WITH ONE     #
 #               SHARED OUTGOING LIMIT. KEEP THE WORKER RUNNING.          #
 ########################################################################
 */

require_once __DIR__ . '/gateway.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new GatewayException('Use POST with phone and text.', 405);
    }
    /* Use the connecting IP. Never trust a caller-supplied X-Forwarded-For header. */
    $ipaddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!gateway_ip_allowed($ipaddr)) throw new GatewayException('NOT ALLOWED! THIS REQUEST IS LOGGED.', 403);

    /* This is the incoming per-IP limiter; every outgoing attempt is limited again by the worker. */
    $rate_limit = get_rate_limit_for_ip($ipaddr, gateway_env('RATE_LIMITS', '*:10/600'));
    if ($rate_limit !== false) {
        $rate_status = consume_rate_limit($ipaddr, $rate_limit['max'], $rate_limit['seconds']);
        header('X-RateLimit-Limit: ' . $rate_limit['max']);
        header('X-RateLimit-Remaining: ' . $rate_status['remaining']);
        header('X-RateLimit-Reset: ' . $rate_status['reset']);
        if (!$rate_status['allowed']) throw new GatewayException('RATE LIMIT EXCEEDED.', 429, $rate_status['retry_after']);
    }

    /* Accept form-data first, otherwise JSON. Keep large request bodies out of RAM state. */
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) throw new GatewayException('Request body too large.', 413);
    if (!empty($_POST)) {
        $data = $_POST;
    } else {
        $body = file_get_contents('php://input', false, null, 0, 8193);
        if ($body === false || strlen($body) > 8192) throw new GatewayException('Request body too large.', 413);
        $data = json_decode($body, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) throw new GatewayException('Invalid JSON body.', 400);
    }
    $record = gateway_enqueue($data['phone'] ?? '', $data['text'] ?? '', $ipaddr, $data['request_id'] ?? null);
    http_response_code(in_array($record['status'], array('queued', 'retry', 'sending'), true) ? 202 : 200);
    echo json_encode(array('status' => $record['status'], 'id' => $record['id'], 'created_at' => $record['created_at']), JSON_UNESCAPED_SLASHES);
} catch (GatewayException $e) {
    http_response_code($e->httpStatus);
    if ($e->retryAfter > 0) header('Retry-After: ' . $e->retryAfter);
    gateway_log('REJECTED: ' . $e->getMessage(), $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Operational details belong to local logs, not unauthenticated HTTP responses.
    $message = $e->httpStatus >= 500 ? 'SMS GATEWAY UNAVAILABLE.' : $e->getMessage();
    echo json_encode(array('error' => $message));
} catch (Throwable $e) {
    http_response_code(503);
    error_log('SMS gateway request failed.');
    echo json_encode(array('error' => 'SMS GATEWAY UNAVAILABLE.'));
}
