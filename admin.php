<?php
/* Small local control panel. Credentials come from ENV; sessions and login counters stay in RAM. */
require_once __DIR__ . '/gateway.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

function admin_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function admin_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_text(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? $value : '';
}

function admin_start_session(string $directory): void
{
    $sessions = $directory . '/sessions';
    if (is_link($sessions) || (!is_dir($sessions) && !mkdir($sessions, 0700, true) && !is_dir($sessions))) {
        throw new RuntimeException('Cannot create RAM session directory.');
    }
    if (realpath($sessions) !== $sessions) {
        throw new RuntimeException('Session directory must remain inside the RAM state directory.');
    }
    chmod($sessions, 0700);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '1800');
    ini_set('session.gc_probability', '0');
    session_save_path($sessions);
    session_name('sms_gateway_admin');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    // Bound anonymous sessions too; a series of GET requests must not fill RAM.
    $lock = fopen($directory . '/sessions.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock sessions.');
    }
    try {
        $count = 0;
        foreach (new DirectoryIterator($sessions) as $entry) {
            if ($entry->isDot() || !str_starts_with($entry->getFilename(), 'sess_')) continue;
            if ($entry->isLink() || !$entry->isFile()) {
                throw new RuntimeException('Unexpected session entry.');
            }
            if ($entry->getMTime() < time() - 1800) {
                $old = fopen($entry->getPathname(), 'r+');
                if ($old !== false) {
                    if (flock($old, LOCK_EX | LOCK_NB)) {
                        unlink($entry->getPathname());
                        flock($old, LOCK_UN);
                        fclose($old);
                        continue;
                    }
                    fclose($old);
                }
            }
            $count++;
        }
        $cookie = $_COOKIE[session_name()] ?? '';
        $existing = is_string($cookie) && preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $cookie)
            && is_file($sessions . '/sess_' . $cookie) && !is_link($sessions . '/sess_' . $cookie);
        if ($count >= 256 && !$existing) {
            throw new RuntimeException('Session capacity reached.');
        }
        if (!session_start()) {
            throw new RuntimeException('Cannot start session.');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* Count login attempts before checking credentials, under one lock across all requests. */
function admin_login_attempt(string $directory, string $address, bool $clear = false): int
{
    $handle = fopen($directory . '/login-attempts.json', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Cannot lock login counters.');
    }
    try {
        $raw = stream_get_contents($handle);
        $entries = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries)) {
            throw new RuntimeException('Invalid login counters.');
        }
        $now = time();
        foreach ($entries as $key => $attempts) {
            $entries[$key] = array_values(array_filter($attempts, static fn($at) => is_int($at) && $at > $now - 600));
            if (!$entries[$key]) {
                unset($entries[$key]);
            }
        }
        $key = hash('sha256', $address);
        $attempts = $entries[$key] ?? [];
        $retryAfter = count($attempts) >= 5 ? max(1, $attempts[0] + 600 - $now) : 0;
        if ($clear) {
            unset($entries[$key]);
            $retryAfter = 0;
        } elseif ($retryAfter === 0) {
            // Bound the table as well as attempts per address.
            if (!isset($entries[$key]) && count($entries) >= 256) {
                return 600;
            }
            $attempts[] = $now;
            $entries[$key] = $attempts;
        }
        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            throw new RuntimeException('Cannot save login counters.');
        }
        return $retryAfter;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

$adminUser = getenv('ADMIN_USER');
$adminPassword = getenv('ADMIN_PASSWORD');
$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$api = $action !== '' && $action !== 'login' && $action !== 'logout';
$error = '';
$unavailable = false;
$authenticated = false;
$csrf = '';

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    admin_json(['error' => 'Request body is too large.'], 413);
}

try {
    if ($adminUser === false || $adminUser === '' || $adminPassword === false || $adminPassword === '') {
        throw new RuntimeException('Admin credentials are not configured.');
    }
    $stateDirectory = gateway_state_dir();
    admin_start_session($stateDirectory);
    $fingerprint = hash('sha256', $adminUser . "\0" . $adminPassword);
    $authenticated = isset($_SESSION['credentials'], $_SESSION['last_seen'], $_SESSION['login_at'])
        && hash_equals($fingerprint, (string) $_SESSION['credentials'])
        && $_SESSION['last_seen'] > time() - 1800
        && $_SESSION['login_at'] > time() - 43200;
    if (!$authenticated && isset($_SESSION['credentials'])) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['csrf'];
    if ($post && !hash_equals($csrf, admin_text('csrf'))) {
        if ($api) {
            admin_json(['error' => 'Your session changed. Reload the page and try again.'], 403);
        }
        http_response_code(403);
        $error = 'Your session changed. Reload the page and try again.';
    } elseif ($post && $action === 'login') {
        $retry = admin_login_attempt($stateDirectory, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if ($retry > 0) {
            http_response_code(429);
            header('Retry-After: ' . $retry);
            $error = 'Too many sign-in attempts. Try again in ' . (int) ceil($retry / 60) . ' minutes.';
        } else {
            // Compare both fields even when the username is wrong.
            $userMatches = hash_equals(hash('sha256', $adminUser), hash('sha256', admin_text('username')));
            $passwordMatches = hash_equals(hash('sha256', $adminPassword), hash('sha256', admin_text('password')));
            if ($userMatches && $passwordMatches) {
                admin_login_attempt($stateDirectory, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), true);
                session_regenerate_id(true);
                $_SESSION = ['credentials' => $fingerprint, 'last_seen' => time(), 'login_at' => time(), 'csrf' => bin2hex(random_bytes(32))];
                header('Location: /admin.php', true, 303);
                exit;
            }
            http_response_code(401);
            $error = 'Username or password is incorrect.';
        }
    } elseif ($post && $action === 'logout') {
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
        header('Location: /admin.php', true, 303);
        exit;
    } elseif ($api) {
        if (!$authenticated) {
            admin_json(['error' => 'Sign in to continue.'], 401);
        }
        $_SESSION['last_seen'] = time();
        session_write_close();
        if (!$post && $action === 'snapshot') {
            admin_json(gateway_snapshot());
        }
        if (!$post && $action === 'inbox') {
            admin_json(['messages' => gateway_inbox()]);
        }
        if (!$post) {
            header('Allow: POST');
            admin_json(['error' => 'Use POST for this action.'], 405);
        }
        if ($action === 'enqueue') {
            $record = gateway_enqueue(admin_text('phone'), admin_text('text'), (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), admin_text('request_id') ?: null);
            admin_json(['message' => 'Test SMS queued. The same sending limits apply.', 'record' => $record], 202);
        }
        if ($action === 'pause') {
            $value = admin_text('paused');
            if ($value !== 'true' && $value !== 'false') {
                admin_json(['error' => 'Invalid pause setting.'], 400);
            }
            gateway_set_paused($value === 'true');
            admin_json(['message' => $value === 'true' ? 'Sending paused.' : 'Sending resumed. Existing limits still apply.']);
        }
        if ($action === 'remove') {
            gateway_remove_queued(admin_text('id'));
            admin_json(['message' => 'Message removed from the queue.']);
        }
        if ($action === 'save_settings') {
            $settings = [];
            foreach (['interval_seconds', 'limit', 'window_seconds', 'startup_hold_seconds', 'max_attempts', 'max_age_seconds', 'max_queue', 'history_limit'] as $key) {
                $value = admin_text($key);
                if (!preg_match('/^[0-9]{1,9}$/D', $value)) {
                    admin_json(['error' => 'Enter whole numbers for all settings.'], 400);
                }
                $settings[$key] = (int) $value;
            }
            $paused = admin_text('paused');
            if ($paused !== 'true' && $paused !== 'false') {
                admin_json(['error' => 'Invalid saved pause setting.'], 400);
            }
            $settings['paused'] = $paused === 'true';
            gateway_save_settings($settings);
            admin_json(['message' => 'Settings saved.']);
        }
        admin_json(['error' => 'Unknown action.'], 404);
    }
    if ($authenticated) {
        $_SESSION['last_seen'] = time();
    }
    session_write_close();
} catch (Throwable $exception) {
    if ($api) {
        $known = $exception instanceof GatewayException;
        if ($known && $exception->retryAfter > 0) {
            header('Retry-After: ' . $exception->retryAfter);
        }
        admin_json(['error' => $known ? $exception->getMessage() : 'The control panel is unavailable. Check the container log.'], $known ? $exception->httpStatus : 503);
    }
    http_response_code(503);
    $unavailable = true;
    $error = ($adminUser === false || $adminUser === '' || $adminPassword === false || $adminPassword === '')
        ? 'Set ADMIN_USER and ADMIN_PASSWORD in the container environment to enable this page.'
        : 'The control panel is unavailable. Check the container log.';
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?= admin_escape($csrf) ?>">
    <title>SMS gateway</title>
    <link rel="stylesheet" href="/admin.css">
<?php if ($authenticated && !$unavailable): ?>
    <script src="/admin.js" defer></script>
<?php endif; ?>
</head>
<body>
<?php if (!$authenticated || $unavailable): ?>
<main class="login-shell">
    <section class="login-card">
        <div class="brand-mark" aria-hidden="true">SMS</div>
        <p class="eyebrow">MIKROTIK SMS GATEWAY</p>
        <h1>Sign in</h1>
        <?php if ($error !== ''): ?><p class="notice error" role="alert"><?= admin_escape($error) ?></p><?php endif; ?>
        <?php if (!$unavailable): ?>
        <form action="/admin.php?action=login" method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= admin_escape($csrf) ?>">
            <label>Username<input name="username" autocomplete="username" maxlength="256" required autofocus></label>
            <label>Password<input name="password" type="password" autocomplete="current-password" maxlength="4096" required></label>
            <button class="button primary" type="submit">Sign in</button>
        </form>
        <?php endif; ?>
    </section>
</main>
<?php else: ?>
<header class="site-header">
    <a class="brand" href="/admin.php"><span class="brand-mark" aria-hidden="true">SMS</span><span>SMS gateway<small>MikroTik</small></span></a>
    <div class="header-actions"><span id="connection" class="connection">Connecting…</span><form action="/admin.php?action=logout" method="post"><input type="hidden" name="csrf" value="<?= admin_escape($csrf) ?>"><button class="button quiet" type="submit">Sign out</button></form></div>
</header>
<main class="dashboard">
    <div class="page-heading"><h1>SMS gateway</h1><button id="pause-button" class="button secondary" type="button" disabled>Pause sending</button></div>
    <div id="notification" class="notice" role="status" hidden></div>
    <section id="status-banner" class="status-banner" aria-live="polite"><span class="status-dot"></span><div><strong id="status-title">Loading gateway status…</strong><p id="status-detail">Checking the queue and sending limits.</p></div></section>
    <section class="metrics" aria-label="Sending status">
        <div class="metric"><span>In queue</span><strong id="metric-queue">—</strong><small>Oldest first</small></div>
        <div class="metric"><span>Send attempts</span><strong id="metric-budget">—</strong><small id="metric-window">—</small></div>
        <div class="metric"><span>Next possible attempt</span><strong id="metric-next">—</strong><small id="metric-interval">Includes retries and test messages</small></div>
        <div class="metric"><span>Recent successful sends</span><strong id="metric-sent">—</strong><small>Accepted by the router</small></div>
    </section>
    <div class="workspace">
        <section class="panel messages-panel">
            <div class="tabs" role="tablist" aria-label="Gateway views">
                <button class="tab active" id="tab-queue" role="tab" aria-controls="view-queue" aria-selected="true" data-view="queue">Queue <span id="queue-count" class="count">0</span></button>
                <button class="tab" id="tab-history" role="tab" aria-controls="view-history" aria-selected="false" tabindex="-1" data-view="history">Recent activity</button>
                <button class="tab" id="tab-inbox" role="tab" aria-controls="view-inbox" aria-selected="false" tabindex="-1" data-view="inbox">Inbox</button>
                <button class="tab" id="tab-settings" role="tab" aria-controls="view-settings" aria-selected="false" tabindex="-1" data-view="settings">Settings</button>
            </div>
            <section id="view-queue" role="tabpanel" aria-labelledby="tab-queue" class="view">
                <div class="section-heading"><div><h2>Queue</h2><p>Oldest first · UTC</p></div></div>
                <div id="queue-list" class="message-list"><p class="empty">Loading messages…</p></div>
            </section>
            <section id="view-history" role="tabpanel" aria-labelledby="tab-history" class="view" hidden>
                <div class="section-heading"><div><h2>Recent activity</h2><p id="history-note">Recent results, including failed and uncertain attempts.</p></div><select id="history-filter" aria-label="Filter activity"><option value="all">All results</option><option value="sent">Sent</option><option value="attention">Needs attention</option></select></div>
                <div id="history-list" class="message-list"></div>
            </section>
            <section id="view-inbox" role="tabpanel" aria-labelledby="tab-inbox" class="view" hidden>
                <div class="section-heading"><div><h2>Router inbox</h2><p>Read-only. SMS reception must be enabled on the router.</p></div><button id="inbox-refresh" class="button secondary small" type="button">Load inbox</button></div>
                <div id="inbox-list" class="message-list"><p class="empty">Load received messages from the router.</p></div>
            </section>
            <section id="view-settings" role="tabpanel" aria-labelledby="tab-settings" class="view" hidden>
                <div class="section-heading"><h2>Settings</h2></div>
                <form id="settings-form" class="settings-form">
                    <fieldset class="settings-group">
                        <legend>Sending limits</legend>
                        <label class="setting-row"><span>Minimum interval</span><input type="number" name="interval_seconds" min="5" max="3600" step="1" required><span class="setting-unit">seconds</span></label>
                        <label class="setting-row"><span>Maximum attempts</span><input type="number" name="limit" min="1" max="1000" step="1" required><span class="setting-unit">per period</span></label>
                        <label class="setting-row"><span>Counting period</span><input type="number" name="window_seconds" min="60" max="86400" step="1" required><span class="setting-unit">seconds</span></label>
                        <p class="group-note">Counts all attempts in the preceding period, including retries.</p>
                    </fieldset>
                    <fieldset class="settings-group">
                        <legend>Queue &amp; retries</legend>
                        <label class="setting-row"><span>Queue capacity</span><input type="number" name="max_queue" min="1" max="1000" step="1" required><span class="setting-unit">messages</span></label>
                        <label class="setting-row"><span>Expire after</span><input type="number" name="max_age_seconds" min="60" max="604800" step="1" required><span class="setting-unit">seconds</span></label>
                        <label class="setting-row"><span>Maximum attempts</span><input type="number" name="max_attempts" min="1" max="10" step="1" required><span class="setting-unit">per message</span></label>
                    </fieldset>
                    <fieldset class="settings-group">
                        <legend>History</legend>
                        <label class="setting-row"><span>Keep recent results</span><input type="number" name="history_limit" min="1" max="1000" step="1" required><span class="setting-unit">messages</span></label>
                    </fieldset>
                    <fieldset class="settings-group">
                        <legend>Startup</legend>
                        <label class="setting-row"><span>Wait after RAM reset</span><input type="number" name="startup_hold_seconds" min="0" max="86400" step="1" required><span class="setting-unit">seconds</span></label>
                        <label class="checkbox-field"><input type="checkbox" name="paused"><span>Start paused after RAM reset</span></label>
                        <p class="group-note">Saving a changed pause setting also pauses or resumes sending now.</p>
                    </fieldset>
                    <div class="form-footer"><span id="settings-state" class="muted">Loading settings…</span><button class="button primary" type="submit" disabled>Save settings</button></div>
                </form>
            </section>
        </section>
        <aside class="panel test-panel">
            <div class="section-heading"><h2>Test SMS</h2></div>
            <form id="test-form" class="stack">
                <label>Phone number<input name="phone" type="tel" placeholder="+316XXXXXXXX" autocomplete="off" maxlength="32" required></label>
                <label>Message<textarea name="text" rows="4" placeholder="Test message" maxlength="160" required></textarea></label>
                <div class="compose-footer"><span id="character-count">0 / 160 bytes</span><span>Single SMS · GSM-7 text</span></div>
                <button class="button primary" type="submit" disabled>Add test to queue</button>
            </form>
        </aside>
    </div>
    <footer class="page-footer"><span id="last-updated">Waiting for first update</span></footer>
</main>
<?php endif; ?>
</body>
</html>
