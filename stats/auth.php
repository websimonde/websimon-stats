<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
// Login for the statistics page, without .htaccess:
// - first visit: set a password (stored only as a hash in config.php)
// - afterwards: login form, session cookie only for the statistics folder
// - after 5 wrong passwords: 15 minutes waiting time for this visitor
// - logout button on the statistics page

require_once __DIR__ . '/i18n.php';

const STATS_MAX_ATTEMPTS = 5;
const STATS_LOCK_SECONDS = 900;
const STATS_SESSION_HOURS = 8;
const STATS_MIN_PASSWORD = 8;

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

function stats_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function stats_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('stats_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => stats_web_path() . '/',
        'secure' => stats_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function stats_csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function stats_csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

// Failed logins per visitor (only a hash of the IP address, removed after the waiting time)
function stats_visitor_key() {
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . __DIR__), 0, 16);
}

function stats_attempts_load() {
    $data = @json_decode(stats_read_guarded(STATS_ATTEMPTS), true);
    if (!is_array($data)) {
        return [];
    }
    $now = time();
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array)$times, function ($t) use ($now) {
            return $t > $now - STATS_LOCK_SECONDS;
        }));
        if (!$data[$k]) {
            unset($data[$k]);
        }
    }
    return $data;
}

function stats_locked_seconds() {
    $times = stats_attempts_load()[stats_visitor_key()] ?? [];
    if (count($times) < STATS_MAX_ATTEMPTS) {
        return 0;
    }
    return max(1, min($times) + STATS_LOCK_SECONDS - time());
}

function stats_attempt_failed() {
    $data = stats_attempts_load();
    $data[stats_visitor_key()][] = time();
    stats_write_guarded(STATS_ATTEMPTS, json_encode($data));
}

function stats_attempts_clear() {
    $data = stats_attempts_load();
    unset($data[stats_visitor_key()]);
    stats_write_guarded(STATS_ATTEMPTS, json_encode($data));
}

function stats_redirect_self() {
    $url = strtok($_SERVER['REQUEST_URI'] ?? './', '?');
    header('Location: ' . $url, true, 303);
    exit;
}

// Small page for setup and login, in the look of the statistics
function stats_render_auth_page($mode, $error = '', $info = '') {
    $host = htmlspecialchars(stats_own_host());
    $csrf = htmlspecialchars(stats_csrf_token());
    $css = 'stats.css?v=' . @filemtime(__DIR__ . '/stats.css');
    $lock = ($mode === 'login') ? stats_locked_seconds() : 0;
    ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(stats_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $mode === 'setup' ? te('setup_title') : te('login_title'); ?> - <?php echo $host; ?></title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('stats-theme');
                if (t !== 'dark' && t !== 'light') {
                    t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="<?php echo $css; ?>">
    <style>
        .auth-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; }
        .auth-card { width: 100%; max-width: 380px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--radius); box-shadow: var(--shadow); padding: 28px; box-sizing: border-box; }
        .auth-card h1 { font-size: 1.3rem; text-align: left; margin: 0 0 4px; }
        .auth-card .host { color: var(--text-muted); margin: 0 0 20px; }
        .auth-card label { display: block; font-weight: 600; margin: 14px 0 6px; }
        .auth-card input[type=password] { width: 100%; box-sizing: border-box; padding: 9px 11px; font: inherit; color: var(--text-main); background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 6px; }
        .auth-card input[type=password]:focus { outline: 2px solid var(--primary); outline-offset: 1px; }
        .auth-card button { margin-top: 20px; width: 100%; padding: 10px; font: inherit; font-weight: 600; color: var(--active-text); background: var(--active-bg); border: 1px solid var(--active-bg); border-radius: 6px; cursor: pointer; }
        .auth-card button:disabled { opacity: 0.5; cursor: default; }
        .auth-msg { margin: 0 0 6px; padding: 9px 11px; border-radius: 6px; font-size: 0.9em; }
        .auth-msg.error { background: rgba(239, 68, 68, 0.12); color: #b91c1c; }
        .auth-msg.info { background: rgba(59, 130, 246, 0.12); color: var(--text-main); }
        html[data-theme="dark"] .auth-msg.error { color: #fca5a5; }
        .auth-hint { color: var(--text-muted); font-size: 0.9em; margin: 14px 0 0; }
    </style>
</head>
<body>
    <div class="auth-wrap">
        <form class="auth-card" method="post" action="">
            <h1><?php echo $mode === 'setup' ? te('setup_title') : te('title'); ?></h1>
            <p class="host"><?php echo $host; ?></p>
            <?php if ($error !== ''): ?><p class="auth-msg error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
            <?php if ($info !== ''): ?><p class="auth-msg info"><?php echo htmlspecialchars($info); ?></p><?php endif; ?>
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <?php if ($mode === 'setup'): ?>
                <input type="hidden" name="action" value="setup">
                <label for="pw1"><?php echo te('choose_password'); ?></label>
                <input type="password" id="pw1" name="password" minlength="<?php echo STATS_MIN_PASSWORD; ?>" autocomplete="new-password" required autofocus>
                <label for="pw2"><?php echo te('repeat_password'); ?></label>
                <input type="password" id="pw2" name="password2" minlength="<?php echo STATS_MIN_PASSWORD; ?>" autocomplete="new-password" required>
                <button type="submit"><?php echo te('setup_button'); ?></button>
                <p class="auth-hint"><?php echo te('setup_hint', STATS_MIN_PASSWORD); ?></p>
            <?php else: ?>
                <input type="hidden" name="action" value="login">
                <label for="pw"><?php echo te('password'); ?></label>
                <input type="password" id="pw" name="password" autocomplete="current-password" required autofocus<?php echo $lock ? ' disabled' : ''; ?>>
                <button type="submit"<?php echo $lock ? ' disabled' : ''; ?>><?php echo te('login_button'); ?></button>
                <?php if ($lock): ?><p class="auth-hint"><?php echo te('locked', ceil($lock / 60)); ?></p><?php endif; ?>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
    <?php
    exit;
}

// ---- Flow -------------------------------------------------------------

stats_start_session();
$statsConfig = stats_config();
$action = $_POST['action'] ?? '';

// Live demo: no password, always logged in, nothing can be changed
if (STATS_DEMO) {
    $_SESSION['stats_ok'] = time();
    if ($action === 'keep') {
        $_SESSION['stats_flash'] = 'demo_readonly';
        $_SESSION['stats_reopen'] = 'settings-keep';
    } elseif ($action === 'filter_nolang') {
        $_SESSION['stats_flash_filter'] = 'demo_readonly';
        $_SESSION['stats_reopen'] = 'settings-filter';
    }
    if ($action !== '') {
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? './'), true, 303);
        exit;
    }
}

// First call: set the password
if ($statsConfig === null) {
    if (!is_writable(__DIR__)) {
        http_response_code(500);
        stats_render_auth_page('setup', t('err_not_writable'));
    }
    if ($action === 'setup') {
        $pw = (string)($_POST['password'] ?? '');
        if (!stats_csrf_ok()) {
            stats_render_auth_page('setup', t('err_expired'));
        } elseif (strlen($pw) < STATS_MIN_PASSWORD) {
            stats_render_auth_page('setup', t('err_short', STATS_MIN_PASSWORD));
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            stats_render_auth_page('setup', t('err_mismatch'));
        } elseif (!stats_save_config(['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'created' => date('c')])) {
            stats_render_auth_page('setup', t('err_save'));
        }
        stats_prepare_log();
        session_regenerate_id(true);
        $_SESSION['stats_ok'] = time();
        stats_redirect_self();
    }
    stats_render_auth_page('setup');
}

// Logout
if ($action === 'logout') {
    if (stats_csrf_ok()) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    stats_redirect_self();
}

// Login
$loggedIn = !empty($_SESSION['stats_ok']) && $_SESSION['stats_ok'] > time() - STATS_SESSION_HOURS * 3600;
if (!$loggedIn) {
    if ($action === 'login') {
        if (stats_locked_seconds()) {
            stats_render_auth_page('login');
        }
        if (!stats_csrf_ok()) {
            stats_render_auth_page('login', t('err_expired'));
        }
        if (password_verify((string)($_POST['password'] ?? ''), $statsConfig['password_hash'])) {
            stats_attempts_clear();
            session_regenerate_id(true);
            $_SESSION['stats_ok'] = time();
            stats_redirect_self();
        }
        stats_attempt_failed();
        $left = STATS_MAX_ATTEMPTS - count(stats_attempts_load()[stats_visitor_key()] ?? []);
        stats_render_auth_page('login', $left > 0 ? t('err_wrong') : '');
    }
    stats_render_auth_page('login');
}
