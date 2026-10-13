<?php

/*
 * Shared SMS gateway code. The webserver queues; only worker.php sends.
 * Queue, counters and recent history live in tmpfs. Only settings are persistent.
 * Keep the sender lock separate from the state lock: a slow modem must not block
 * incoming requests, but there may never be two simultaneous modem requests.
 */

class GatewayException extends RuntimeException {
    public $httpStatus;
    public $retryAfter;

    public function __construct($message, $httpStatus = 503, $retryAfter = 0) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->retryAfter = $retryAfter;
    }
}

function gateway_env($name, $default) {
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function gateway_bool($value, $name) {
    if ($value === true || $value === 'true' || $value === '1') return true;
    if ($value === false || $value === 'false' || $value === '0') return false;
    throw new GatewayException('Invalid ' . $name . ': use true or false.', 400);
}

function gateway_settings_schema() {
    return array(
        'interval_seconds' => array(5, 3600),
        'limit' => array(1, 1000),
        'window_seconds' => array(60, 86400),
        'startup_hold_seconds' => array(0, 86400),
        'max_attempts' => array(1, 10),
        'max_age_seconds' => array(60, 604800),
        'max_queue' => array(1, 1000),
        'history_limit' => array(1, 1000)
    );
}

function gateway_validate_settings(array $input, $partial = false) {
    $schema = gateway_settings_schema();
    $result = array();
    foreach ($input as $key => $value) {
        if ($key === 'paused') {
            $result[$key] = gateway_bool($value, $key);
            continue;
        }
        if (!isset($schema[$key])) throw new GatewayException('Unknown setting: ' . $key, 400);
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string)$value)) {
            throw new GatewayException('Invalid setting: ' . $key, 400);
        }
        $number = (int)$value;
        if ($number < $schema[$key][0] || $number > $schema[$key][1]) {
            throw new GatewayException($key . ' must be between ' . $schema[$key][0] . ' and ' . $schema[$key][1] . '.', 400);
        }
        $result[$key] = $number;
    }
    if (!$partial && count($result) !== count($schema) + 1) throw new GatewayException('Incomplete settings.', 400);
    return $result;
}

/* A missing settings file uses ENV defaults; an unreadable or invalid file stops sending. */
function gateway_config() {
    $send_limits = gateway_env('SEND_LIMITS', '20/3600');
    if (!preg_match('/^([1-9][0-9]*)\/([1-9][0-9]*)$/D', $send_limits, $matches)) {
        throw new GatewayException('Invalid SEND_LIMITS configuration.');
    }
    try {
        $config = gateway_validate_settings(array(
            'interval_seconds' => gateway_env('SEND_INTERVAL_SECONDS', '10'),
            'limit' => $matches[1], 'window_seconds' => $matches[2],
            'startup_hold_seconds' => gateway_env('STARTUP_HOLD_SECONDS', '300'),
            'max_attempts' => gateway_env('SEND_MAX_ATTEMPTS', '3'),
            'max_age_seconds' => gateway_env('QUEUE_MAX_AGE_SECONDS', '3600'),
            'max_queue' => gateway_env('QUEUE_MAX_MESSAGES', '100'),
            'history_limit' => gateway_env('HISTORY_LIMIT', '200'),
            'paused' => false
        ));
        $file = gateway_env('SMS_CONFIG_FILE', '/config/settings.json');
        if ($file === '' || $file[0] !== '/') throw new GatewayException('SMS_CONFIG_FILE must be an absolute path.');
        clearstatcache(true, $file);
        if (file_exists($file)) {
            if (is_link($file) || !is_readable($file) || filesize($file) > 16384) throw new GatewayException('Settings file is unreadable or too large.');
            $contents = file_get_contents($file);
            $saved = json_decode($contents, true);
            if (!is_array($saved) || json_last_error() !== JSON_ERROR_NONE) throw new GatewayException('Invalid settings file.');
            $config = array_replace($config, gateway_validate_settings($saved, true));
        }
        return $config;
    } catch (GatewayException $e) {
        throw new GatewayException('Configuration unavailable: ' . $e->getMessage());
    }
}

/* Require Linux tmpfs, even when SMS_STATE_DIR is overridden. No silent flash fallback. */
function gateway_state_dir() {
    $dir = gateway_env('SMS_STATE_DIR', '/dev/shm/sms-gateway');
    if ($dir === '' || $dir[0] !== '/') throw new GatewayException('SMS_STATE_DIR must be an absolute tmpfs path.');
    // Verify an existing parent first: even an incorrect path must not create a flash directory.
    $parent = $dir;
    while (!file_exists($parent) && dirname($parent) !== $parent) $parent = dirname($parent);
    $real = realpath($parent);
    $mounts = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
    if ($real === false || $mounts === false) throw new GatewayException('Cannot verify tmpfs state directory.');
    $best = -1;
    $type = '';
    foreach ($mounts as $line) {
        $parts = explode(' - ', $line, 2);
        if (count($parts) !== 2) continue;
        $fields = explode(' ', $parts[0]);
        $fs = explode(' ', $parts[1]);
        if (!isset($fields[4], $fs[0])) continue;
        $mount = preg_replace_callback('/\\\\([0-7]{3})/', function ($match) { return chr(octdec($match[1])); }, $fields[4]);
        if (($real === $mount || strpos($real . '/', rtrim($mount, '/') . '/') === 0) && strlen($mount) > $best) {
            $best = strlen($mount);
            $type = $fs[0];
        }
    }
    if ($type !== 'tmpfs') throw new GatewayException('SMS_STATE_DIR must be on tmpfs; refusing flash writes.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new GatewayException('Cannot create RAM state directory.');
    $real = realpath($dir);
    if ($real === false) throw new GatewayException('Cannot resolve RAM state directory.');
    @chmod($real, 0700);
    return $real;
}

function gateway_mono() { return hrtime(true) / 1000000000; }
function gateway_iso($timestamp) { return gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp); }
function gateway_boot_id() {
    $id = @file_get_contents('/proc/sys/kernel/random/boot_id');
    if ($id === false || trim($id) === '') throw new GatewayException('Cannot identify this RAM lifetime.');
    return trim($id);
}

function gateway_lock($path, $wait_seconds = 2.0) {
    if (is_link($path)) throw new GatewayException('Invalid lock file.');
    $handle = @fopen($path, 'c');
    if ($handle === false) throw new GatewayException('Cannot open gateway lock.');
    @chmod($path, 0600);
    $deadline = gateway_mono() + $wait_seconds;
    do {
        if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        if ($wait_seconds <= 0) break;
        usleep(10000);
    } while (gateway_mono() < $deadline);
    fclose($handle);
    return false;
}

function gateway_unlock($handle) { flock($handle, LOCK_UN); fclose($handle); }

function gateway_atomic_write($file, array $data) {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > 8388608) throw new GatewayException('RAM state size limit reached.');
    $temp = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(8));
    $handle = @fopen($temp, 'x');
    if ($handle === false) throw new GatewayException('Cannot write gateway state.');
    @chmod($temp, 0600);
    try {
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) throw new GatewayException('Cannot write gateway state.');
            $offset += $written;
        }
        if (!fflush($handle)) throw new GatewayException('Cannot flush gateway state.');
        fclose($handle);
        $handle = null;
        if (!@rename($temp, $file)) throw new GatewayException('Cannot replace gateway state.');
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (file_exists($temp)) @unlink($temp);
    }
}

function gateway_new_state(array $config, $now) {
    return array(
        'version' => 1, 'boot_id' => gateway_boot_id(), 'clock_mono' => $now, 'origin_mono' => $now,
        'queue' => array(), 'history' => array(), 'reservations' => array(),
        'last_attempt_mono' => null, 'cooldown_mono' => $now + $config['startup_hold_seconds'], 'max_window_seconds' => $config['window_seconds'],
        'incoming' => array(), 'paused' => null
    );
}

/* Treat malformed RAM state as an error, never as an empty budget or queue. */
function gateway_validate_state(array $state, $now) {
    foreach (array('clock_mono', 'origin_mono', 'cooldown_mono') as $field) {
        if (!isset($state[$field]) || !is_numeric($state[$field]) || !is_finite((float)$state[$field]) || $state[$field] < 0) throw new GatewayException('RAM state clock is corrupt.');
    }
    if ($state['origin_mono'] > $state['clock_mono'] || $state['clock_mono'] > $now) throw new GatewayException('RAM state clock is corrupt.');
    if (!array_key_exists('last_attempt_mono', $state) || ($state['last_attempt_mono'] !== null && (!is_numeric($state['last_attempt_mono']) || $state['last_attempt_mono'] < 0 || $state['last_attempt_mono'] > $now))) throw new GatewayException('RAM send budget is corrupt.');
    if (!array_key_exists('paused', $state) || ($state['paused'] !== null && !is_bool($state['paused']))) throw new GatewayException('RAM pause state is corrupt.');
    foreach (array('queue' => 1000, 'history' => 1000, 'reservations' => 10000, 'incoming' => 128) as $field => $maximum) {
        if (!isset($state[$field]) || !is_array($state[$field]) || count($state[$field]) > $maximum) throw new GatewayException('RAM state exceeds its bounds.');
    }
    if (!isset($state['max_window_seconds']) || !is_int($state['max_window_seconds']) || $state['max_window_seconds'] < 60 || $state['max_window_seconds'] > 86400) throw new GatewayException('RAM window is corrupt.');
    $previous = 0;
    foreach ($state['reservations'] as $ts) {
        if (!is_numeric($ts) || !is_finite((float)$ts) || $ts < $previous || $ts > $now) throw new GatewayException('RAM send budget is corrupt.');
        $previous = $ts;
    }
    foreach (array_merge($state['queue'], $state['history']) as $item) {
        if (!is_array($item) || !isset($item['id'], $item['phone'], $item['text'], $item['status'], $item['source_ip'], $item['created_mono'], $item['created_at'], $item['created_ts'], $item['attempts'], $item['next_attempt_mono'])) throw new GatewayException('RAM message is corrupt.');
        if (!is_string($item['id']) || !preg_match('/^[a-f0-9]{24}$/D', $item['id']) || !is_string($item['phone']) || !is_string($item['text']) || strlen($item['phone']) > 32 || strlen($item['text']) > 160 || !is_int($item['attempts']) || $item['attempts'] < 0 || $item['attempts'] > 10) throw new GatewayException('RAM message is corrupt.');
        if (!in_array($item['status'], array('queued', 'sending', 'retry', 'sent', 'failed', 'uncertain', 'expired', 'cancelled'), true) || !is_numeric($item['created_mono']) || $item['created_mono'] < 0 || $item['created_mono'] > $now || !is_numeric($item['next_attempt_mono']) || $item['next_attempt_mono'] < 0) throw new GatewayException('RAM message is corrupt.');
    }
    foreach ($state['incoming'] as $bucket) {
        if (!is_array($bucket) || !isset($bucket['timestamps'], $bucket['expires']) || !is_array($bucket['timestamps']) || count($bucket['timestamps']) > 1000 || !is_numeric($bucket['expires'])) throw new GatewayException('RAM incoming limiter is corrupt.');
        foreach ($bucket['timestamps'] as $ts) if (!is_numeric($ts) || $ts < 0 || $ts > $now) throw new GatewayException('RAM incoming limiter is corrupt.');
    }
}

/* The clock used for limits never follows wall-clock/NTP corrections. */
function gateway_with_state(callable $callback) {
    $dir = gateway_state_dir();
    $lock = gateway_lock($dir . '/state.lock');
    if ($lock === false) throw new GatewayException('Gateway is busy; try again.');
    try {
        $config = gateway_config();
        $file = $dir . '/state.json';
        $now = gateway_mono();
        clearstatcache(true, $file);
        if (!file_exists($file)) {
            $state = gateway_new_state($config, $now);
        } else {
            if (is_link($file) || !is_readable($file) || filesize($file) > 8388608) throw new GatewayException('Invalid RAM state file.');
            $state = json_decode(file_get_contents($file), true);
            if (!is_array($state) || ($state['version'] ?? null) !== 1 || !isset($state['boot_id'], $state['clock_mono'], $state['origin_mono'], $state['cooldown_mono'])) {
                throw new GatewayException('RAM state is corrupt; sending has stopped.');
            }
            foreach (array('queue', 'history', 'reservations', 'incoming') as $key) {
                if (!isset($state[$key]) || !is_array($state[$key])) throw new GatewayException('RAM state is corrupt; sending has stopped.');
            }
            if ($state['boot_id'] !== gateway_boot_id() || $now < $state['clock_mono']) throw new GatewayException('RAM clock changed; sending has stopped.');
        }
        gateway_validate_state($state, $now);
        // Anchor the hold to the RAM lifetime, never to a worker or container restart.
        // Recalculate for settings changes and v2.0 state without resetting known attempts.
        $state['cooldown_mono'] = $state['origin_mono'] + $config['startup_hold_seconds'];
        $state['max_window_seconds'] = max($state['max_window_seconds'], $config['window_seconds']);
        $state['clock_mono'] = $now;
        // Retain the full allowed settings horizon, so changing the window cannot reset a budget.
        $state['reservations'] = array_values(array_filter($state['reservations'], function ($ts) use ($now) { return is_numeric($ts) && $ts > $now - 86400; }));
        foreach ($state['incoming'] as $key => $bucket) {
            if (($bucket['expires'] ?? 0) <= $now) unset($state['incoming'][$key]);
        }
        $result = $callback($state, $config, $now);
        gateway_atomic_write($file, $state);
        return $result;
    } finally {
        gateway_unlock($lock);
    }
}

function gateway_record_history(array &$state, array $record, array $config) {
    $state['history'][] = $record;
    $state['history'] = array_slice($state['history'], -$config['history_limit']);
}

function gateway_public_record(array $record) {
    unset($record['created_mono'], $record['next_attempt_mono'], $record['sending_mono'], $record['request_key']);
    return $record;
}

function gateway_validate_message($phone, $text) {
    if (!is_string($phone) || !is_string($text)) throw new GatewayException('phone and text must be strings.', 400);
    $phone = preg_replace('/[\s\-]+/', '', trim($phone));
    if (preg_match('/^316\d{8}$/D', $phone)) $phone = '+' . $phone;
    if (preg_match('/^06\d{8}$/D', $phone)) $phone = '+31' . substr($phone, 1);
    $text = preg_replace('/[\r\n\t]+/', ' ', trim($text));
    if ($phone === '' || $text === '') throw new GatewayException('NO VALID DATA SEND.', 400);
    if (!preg_match('/^(\+[0-9]{8,14}|0[1-9][0-9]{8}|00[0-9]{7,14})$/D', $phone)) throw new GatewayException('NUMBER NOT SEND IN INTERNATIONAL FORMAT.', 400);
    if (gateway_bool(strtolower(gateway_env('ONLY_DUTCH', 'true')), 'ONLY_DUTCH') && !preg_match('/^\+316[0-9]{8}$/D', $phone)) {
        throw new GatewayException('ONLY DUTCH INTERNATIONAL MOBILE NUMBERS ARE ALLOWED.', 400);
    }
    // Keep the existing 160-byte limit. Control characters cannot reach RouterOS script strings.
    if (strlen($text) > 160) throw new GatewayException('TEXT TOO LONG, MAX 160 BYTES ALLOWED.', 400);
    if (!preg_match('//u', $text) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) throw new GatewayException('Invalid SMS text.', 400);
    // RouterOS supports GSM 03.38, not UCS2. Extended characters consume two septets.
    $basic = '@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    $extended = '^{}\\[~]|€';
    $septets = 0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        if (str_contains($basic, $character)) $septets++;
        elseif (str_contains($extended, $character)) $septets += 2;
        else throw new GatewayException('Text contains characters unsupported by RouterOS GSM7 SMS.', 400);
    }
    if ($septets > 160) throw new GatewayException('TEXT TOO LONG FOR ONE SMS (MAX 160 GSM7 SEPTETS).', 400);
    return array($phone, $text);
}

/* Append at the bottom. request_id makes a caller retry idempotent while its record is retained. */
function gateway_enqueue($phone, $text, $sourceIp, $requestId = null) {
    list($phone, $text) = gateway_validate_message($phone, $text);
    if (!is_string($sourceIp) || !filter_var($sourceIp, FILTER_VALIDATE_IP)) throw new GatewayException('Invalid source IP.', 400);
    if ($requestId !== null && (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $requestId))) throw new GatewayException('Invalid request_id.', 400);
    $requestKey = $requestId === null ? null : hash('sha256', $sourceIp . '|' . $requestId);
    $record = gateway_with_state(function (&$state, $config, $now) use ($phone, $text, $sourceIp, $requestKey) {
        if ($requestKey !== null) {
            foreach (array_merge($state['queue'], $state['history']) as $existing) {
                if (($existing['request_key'] ?? null) === $requestKey) {
                    if ($existing['phone'] !== $phone || $existing['text'] !== $text) throw new GatewayException('request_id already used for a different SMS.', 409);
                    $existing = gateway_public_record($existing);
                    $existing['duplicate'] = true;
                    return $existing;
                }
            }
        }
        if (count($state['queue']) >= $config['max_queue']) throw new GatewayException('SMS queue is full.', 429, 60);
        $record = array(
            'id' => bin2hex(random_bytes(12)), 'phone' => $phone, 'text' => $text, 'source_ip' => $sourceIp,
            'created_at' => gateway_iso(time()), 'created_ts' => time(), 'created_mono' => $now,
            'status' => 'queued', 'attempts' => 0, 'next_attempt_mono' => $now,
            'next_attempt_ts' => time(), 'request_key' => $requestKey
        );
        $state['queue'][] = $record;
        return gateway_public_record($record);
    });
    if (empty($record['duplicate'])) gateway_log('QUEUED: ' . $phone . ' - ' . $text . ' - ID: ' . $record['id'], $sourceIp);
    return $record;
}

function gateway_paused(array $state, array $config) {
    return $state['paused'] === null ? $config['paused'] : $state['paused'];
}

function gateway_budget(array $state, array $config, $now) {
    $attempts = array_values(array_filter($state['reservations'], function ($ts) use ($now, $config) { return $ts > $now - $config['window_seconds']; }));
    $next = max($now, $state['cooldown_mono']);
    if ($state['last_attempt_mono'] !== null) $next = max($next, $state['last_attempt_mono'] + $config['interval_seconds']);
    if (count($attempts) >= $config['limit']) $next = max($next, $attempts[count($attempts) - $config['limit']] + $config['window_seconds']);
    if (count($state['reservations']) >= 10000) $next = max($next, $state['reservations'][0] + 86400);
    return array('attempts' => count($attempts), 'next' => $next);
}

function gateway_snapshot() {
    return gateway_with_state(function (&$state, $config, $now) {
        $budget = gateway_budget($state, $config, $now);
        return array(
            'queue' => array_map('gateway_public_record', $state['queue']),
            'history' => array_map('gateway_public_record', array_reverse($state['history'])),
            'paused' => gateway_paused($state, $config),
            'cooldown_until' => time() + max(0, (int)ceil($state['cooldown_mono'] - $now)),
            'next_send_at' => time() + max(0, (int)ceil($budget['next'] - $now)),
            'attempts_in_window' => $budget['attempts'], 'limit' => $config['limit'],
            'window_seconds' => $config['window_seconds'], 'settings' => $config, 'now' => time()
        );
    });
}

function gateway_save_settings(array $input) {
    $validated = gateway_validate_settings($input, true);
    if (!$validated) throw new GatewayException('No settings supplied.', 400);
    $file = gateway_env('SMS_CONFIG_FILE', '/config/settings.json');
    // Lock RAM first, consistently with every worker/config read. The only flash write is this Save.
    gateway_with_state(function (&$state, $config, $now) use ($validated, $file) {
        $saved = array_replace($config, $validated);
        $dir = dirname($file);
        if (!is_dir($dir) || !is_writable($dir)) throw new GatewayException('Configuration mount is not writable.');
        // Apply the selected hold from the original RAM start, including elapsed time.
        $state['cooldown_mono'] = $state['origin_mono'] + $saved['startup_hold_seconds'];
        // Preserve the recorded attempts before publishing the changed settings.
        gateway_atomic_write(gateway_state_dir() . '/state.json', $state);
        gateway_atomic_write($file, $saved);
        // Saving unrelated settings must not silently cancel an emergency RAM pause.
        if (array_key_exists('paused', $validated) && $validated['paused'] !== $config['paused']) $state['paused'] = null;
        $state['history'] = array_slice($state['history'], -$saved['history_limit']);
    });
}

function gateway_set_paused($paused) {
    if (!is_bool($paused)) throw new GatewayException('Invalid pause value.', 400);
    gateway_with_state(function (&$state) use ($paused) { $state['paused'] = $paused; });
}

function gateway_remove_queued($id) {
    gateway_with_state(function (&$state, $config) use ($id) {
        foreach ($state['queue'] as $index => $record) {
            if ($record['id'] !== $id) continue;
            if ($record['status'] === 'sending') throw new GatewayException('This SMS is already being sent.', 409);
            $record['status'] = 'cancelled';
            $record['reason'] = 'Removed by administrator.';
            $record['finished_at'] = gateway_iso(time());
            gateway_record_history($state, $record, $config);
            array_splice($state['queue'], $index, 1);
            return;
        }
        throw new GatewayException('Queued SMS not found.', 404);
    });
}

/* Preserve the existing IPv4 allow-list and first matching IP/CIDR rate rule. */
function ip_in_range($ip, $range) {
    $parts = explode('/', trim($range));
    if (count($parts) > 2 || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new GatewayException('Invalid IPv4 CIDR configuration.');
    $bits = $parts[1] ?? '32';
    if (!preg_match('/^(?:[0-9]|[12][0-9]|3[0-2])$/D', (string)$bits)) throw new GatewayException('Invalid IPv4 netmask.');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $mask = (int)$bits === 0 ? 0 : (-1 << (32 - (int)$bits));
    return (ip2long($ip) & $mask) === (ip2long($parts[0]) & $mask);
}

function gateway_ip_allowed($ip) {
    $ranges = explode(',', gateway_env('ALLOWED_IP_RANGES', '192.168.0.0/21,192.168.10.0/24'));
    $allowed = false;
    foreach ($ranges as $range) if (ip_in_range($ip, $range)) $allowed = true;
    return $allowed;
}

function get_rate_limit_for_ip($ip, $rules_raw) {
    if (in_array(strtolower(trim($rules_raw)), array('off', 'none', 'false', '0'), true)) return false;
    if (strlen($rules_raw) > 16384 || trim($rules_raw) === '') throw new GatewayException('Invalid RATE_LIMITS configuration.');
    $fallback = false;
    $match = false;
    foreach (explode(',', $rules_raw) as $rule) {
        $separator = strrpos($rule, ':');
        if ($separator === false) throw new GatewayException('Invalid RATE_LIMITS rule.');
        $selector = trim(substr($rule, 0, $separator));
        if (!preg_match('/^([1-9][0-9]*)\/([1-9][0-9]*)$/D', trim(substr($rule, $separator + 1)), $parts)) throw new GatewayException('Invalid RATE_LIMITS rule.');
        $limit = array('max' => (int)$parts[1], 'seconds' => (int)$parts[2]);
        if ($limit['max'] > 1000 || $limit['seconds'] > 86400) throw new GatewayException('RATE_LIMITS exceeds supported RAM bounds.');
        if ($selector === '*') $fallback = $limit;
        elseif (ip_in_range($ip, $selector) && $match === false) $match = $limit;
    }
    return $match === false ? $fallback : $match;
}

/* Shared RAM state also bounds the number of IP counters. No APCu eviction/reset bypass. */
function consume_rate_limit($ip, $maximum, $seconds) {
    return gateway_with_state(function (&$state, $config, $now) use ($ip, $maximum, $seconds) {
        $key = hash('sha256', $ip . '|' . $maximum . '|' . $seconds);
        if (!isset($state['incoming'][$key]) && count($state['incoming']) >= 128) throw new GatewayException('Incoming limiter capacity reached.', 503, 60);
        $timestamps = $state['incoming'][$key]['timestamps'] ?? array();
        $timestamps = array_values(array_filter($timestamps, function ($ts) use ($now, $seconds) { return $ts > $now - $seconds; }));
        $allowed = count($timestamps) < $maximum;
        if ($allowed) $timestamps[] = $now;
        $retry = $allowed ? 0 : max(1, (int)ceil($timestamps[0] + $seconds - $now));
        $state['incoming'][$key] = array('timestamps' => $timestamps, 'expires' => end($timestamps) + $seconds);
        return array('allowed' => $allowed, 'remaining' => max(0, $maximum - count($timestamps)), 'retry_after' => $retry, 'reset' => time() + max(1, (int)ceil($timestamps[0] + $seconds - $now)));
    });
}

/* Credentials stay in ENV. No call stores them in state, settings, history or responses. */
function gateway_router_config() {
    if (!function_exists('curl_init')) throw new GatewayException('PHP curl extension is required.');
    $base = rtrim(gateway_env('SMS_GATEWAY_URL', 'http://localhost'), '/');
    $parts = parse_url($base);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', array('http', 'https'), true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new GatewayException('Invalid SMS_GATEWAY_URL.');
    $user = gateway_env('SMS_GATEWAY_USER', '');
    $pass = gateway_env('SMS_GATEWAY_PASS', '');
    if ($user === '' || $pass === '') throw new GatewayException('RouterOS credentials are missing.');
    $verify = gateway_bool(strtolower(gateway_env('SMS_GATEWAY_VERIFY_TLS', 'false')), 'SMS_GATEWAY_VERIFY_TLS');
    $port = gateway_env('SMS_PORT', 'lte1');
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $port)) throw new GatewayException('Invalid SMS_PORT.');
    return array('base' => $base, 'user' => $user, 'pass' => $pass, 'verify' => $verify, 'port' => $port);
}

function gateway_rest($path, $method = 'GET', $data = null) {
    $connection = gateway_router_config();
    extract($connection, EXTR_SKIP);
    $handle = curl_init($base . '/rest/' . ltrim($path, '/'));
    $body = '';
    curl_setopt_array($handle, array(
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > 1048576) return 0;
            $body .= $chunk;
            return strlen($chunk);
        }
    ));
    if ($data !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
    curl_exec($handle);
    $error = curl_errno($handle);
    $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $decoded = json_decode($body, true);
    $valid = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    return array('http' => $code, 'error' => $error, 'json' => $decoded, 'valid_json' => $valid);
}

function gateway_classify_send(array $response) {
    if ($response['error'] !== 0) {
        // DNS/connect failures happen before delivery; timeout or dropped responses are ambiguous.
        if (in_array($response['error'], array(CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT), true)) {
            return array('status' => 'retry', 'reason' => 'Could not connect to RouterOS.');
        }
        return array('status' => 'uncertain', 'reason' => 'No reliable RouterOS reply; automatic retry stopped.');
    }
    $code = $response['http'];
    $json = $response['json'];
    if ($response['valid_json'] && $code >= 200 && $code < 300 && !isset($json['error'])) {
        // RouterOS command success is [] (or an empty object); arbitrary JSON is not an acknowledgement.
        if ($json === array()) return array('status' => 'sent', 'reason' => 'RouterOS accepted the SMS; handset delivery is not confirmed.');
    }
    if ($response['valid_json'] && isset($json['error']) && in_array($code, array(400, 401, 403, 404, 405, 422, 429), true)) {
        return array('status' => $code === 429 ? 'retry' : 'failed', 'reason' => 'RouterOS rejected the request (HTTP ' . $code . ').');
    }
    return array('status' => 'uncertain', 'reason' => 'Unexpected RouterOS reply (HTTP ' . $code . '); automatic retry stopped.');
}

/* One tick makes at most one attempt. A reservation is committed BEFORE contacting the modem. */
function gateway_tick() {
    gateway_router_config();
    $sender = gateway_lock(gateway_state_dir() . '/sender.lock', 0);
    if ($sender === false) return array('status' => 'busy');
    try {
        $record = gateway_with_state(function (&$state, $config, $now) {
            $queue = array();
            foreach ($state['queue'] as $item) {
                if ($item['status'] === 'sending') {
                    // We hold sender.lock, so an old sending record belongs to an interrupted process.
                    $item['status'] = 'uncertain';
                    $item['reason'] = 'Worker stopped during a send; automatic retry stopped.';
                    $item['finished_at'] = gateway_iso(time());
                    gateway_record_history($state, $item, $config);
                    $state['last_attempt_mono'] = $now;
                } elseif ($item['attempts'] >= $config['max_attempts']) {
                    $item['status'] = 'failed';
                    $item['reason'] = 'Maximum attempts reached.';
                    $item['finished_at'] = gateway_iso(time());
                    gateway_record_history($state, $item, $config);
                } elseif ($now - $item['created_mono'] >= $config['max_age_seconds']) {
                    $item['status'] = 'expired';
                    $item['reason'] = 'Maximum queue age reached.';
                    $item['finished_at'] = gateway_iso(time());
                    gateway_record_history($state, $item, $config);
                } else {
                    $queue[] = $item;
                }
            }
            $state['queue'] = $queue;
            $budget = gateway_budget($state, $config, $now);
            if (gateway_paused($state, $config) || $now < $budget['next']) return null;
            foreach ($state['queue'] as &$item) {
                if ($item['next_attempt_mono'] > $now) continue;
                $item['status'] = 'sending';
                $item['attempts']++;
                $item['sending_mono'] = $now;
                $item['last_attempt_at'] = gateway_iso(time());
                $state['reservations'][] = $now;
                $state['last_attempt_mono'] = $now;
                return $item;
            }
            unset($item);
            return null;
        });
        if ($record === null) return array('status' => 'waiting');
        try {
            $response = gateway_rest('tool/sms/send', 'POST', array('port' => gateway_env('SMS_PORT', 'lte1'), 'phone-number' => $record['phone'], 'message' => $record['text']));
            $result = gateway_classify_send($response);
        } catch (Throwable $e) {
            // Even an unexpected local failure cannot authorize a duplicate transmission.
            $result = array('status' => 'uncertain', 'reason' => 'Sending failed without a reliable acknowledgement.');
        }
        $final_status = gateway_with_state(function (&$state, $config, $now) use ($record, $result) {
            // Space attempts after completion too: a slow modem must not create a back-to-back burst.
            $state['last_attempt_mono'] = $now;
            foreach ($state['queue'] as $index => $item) {
                if ($item['id'] !== $record['id']) continue;
                $item['status'] = $result['status'];
                $item['reason'] = $result['reason'];
                unset($item['sending_mono']);
                if ($result['status'] === 'retry' && $item['attempts'] < $config['max_attempts']) {
                    $delay = $item['attempts'] === 1 ? 60 : 300;
                    $item['next_attempt_mono'] = $now + $delay;
                    $item['next_attempt_ts'] = time() + $delay;
                    $state['queue'][$index] = $item;
                } else {
                    if ($item['status'] === 'retry') { $item['status'] = 'failed'; $item['reason'] = 'Maximum attempts reached. ' . $item['reason']; }
                    $item['finished_at'] = gateway_iso(time());
                    if ($item['status'] === 'sent') { $item['sent_at'] = gateway_iso(time()); $item['sent_ts'] = time(); }
                    gateway_record_history($state, $item, $config);
                    array_splice($state['queue'], $index, 1);
                }
                return $item['status'];
            }
            throw new GatewayException('Sending record disappeared; worker has stopped.');
        });
        $result['status'] = $final_status;
        $line = ($result['status'] === 'sent' ? 'SMS SENT: ' : 'SMS ' . strtoupper($result['status']) . ': ') . $record['phone'] . ' - ' . $record['text'];
        gateway_log($line, $record['source_ip']);
        if ($result['status'] === 'sent') gateway_routeros_log($line . ' - Source IP: ' . $record['source_ip']);
        return array('status' => $result['status'], 'id' => $record['id']);
    } finally {
        gateway_unlock($sender);
    }
}

/* Optional file logging stays inside the same verified RAM directory and rotates at 256 KiB. */
function gateway_log($text, $sourceIp = 'worker') {
    try {
        if (!gateway_bool(strtolower(gateway_env('LOG_TO_FILE', 'false')), 'LOG_TO_FILE')) return;
        $dir = gateway_state_dir();
        $file = gateway_env('SMS_LOG_FILE', $dir . '/sms.log');
        if (dirname($file) !== $dir || is_link($file)) throw new GatewayException('SMS_LOG_FILE must be directly inside SMS_STATE_DIR.');
        $lock = gateway_lock($dir . '/log.lock');
        if ($lock === false) return;
        try {
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) >= 262144 && !@rename($file, $file . '.1')) throw new GatewayException('Cannot rotate RAM log.');
            $line = gateway_iso(time()) . ' - ' . $sourceIp . ' - ' . str_replace(array("\r", "\n"), ' ', $text) . PHP_EOL;
            if (@file_put_contents($file, $line, FILE_APPEND) === false) throw new GatewayException('Cannot write RAM log.');
            @chmod($file, 0600);
        } finally { gateway_unlock($lock); }
    } catch (Throwable $e) {
        // Never change an accepted request or resend an SMS because logging failed.
        error_log('SMS gateway file logging failed: ' . $e->getMessage());
    }
}

function gateway_routeros_log($text) {
    try {
        if (!gateway_bool(strtolower(gateway_env('LOG_TO_ROUTEROS', 'false')), 'LOG_TO_ROUTEROS')) return;
        $escaped = str_replace(array('\\', '"', '$', "\r", "\n"), array('\\\\', '\\"', '\\$', ' ', ' '), $text);
        $result = gateway_rest('execute', 'POST', array('script' => '/log/info "' . $escaped . '"'));
        if ($result['error'] !== 0 || $result['http'] < 200 || $result['http'] >= 300) error_log('SMS gateway RouterOS logging failed.');
    } catch (Throwable $e) { error_log('SMS gateway RouterOS logging failed.'); }
}

function gateway_inbox() {
    $result = gateway_rest('tool/sms/inbox');
    if ($result['error'] !== 0 || $result['http'] !== 200 || !$result['valid_json'] || !array_is_list($result['json'])) throw new GatewayException('RouterOS inbox is unavailable.', 502);
    $messages = array();
    foreach (array_slice($result['json'], -100) as $item) {
        if (!is_array($item)) continue;
        $safe = array();
        foreach (array('.id', 'phone', 'phone-number', 'timestamp', 'message', 'type') as $field) {
            if (isset($item[$field]) && is_scalar($item[$field])) $safe[$field] = substr((string)$item[$field], 0, 2048);
        }
        $messages[] = $safe;
    }
    return array_reverse($messages);
}
