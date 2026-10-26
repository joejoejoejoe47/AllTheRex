<?php
declare(strict_types=1);
/**
 * One-time installer: creates the tables (and optionally the starter titles).
 * Needs 'setup_token' set in config.php. DELETE THIS FILE FROM THE SERVER WHEN YOU'RE DONE.
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/seed.php';

$cfg    = rex_config();
$token  = (string)$cfg['setup_token'];
$log    = [];
$done   = false;
$denied = false;

page_head('Setup');
echo '<section class="formwrap"><h1>Setup</h1>';

if (strlen($token) < 12) {
    echo '<div class="flash err">Set <code>setup_token</code> in <code>config.php</code> to a random string of at least 12 characters, then reload this page.</div></section>';
    page_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $denied = true;
        usleep(500000);
    } else {
        try {
            rex_install(db());
            $log[] = 'Tables are ready (users, items, ratings, item_sim, login_attempts).';
            if (!empty($_POST['seed'])) {
                $log[] = 'Added ' . rex_seed() . ' starter titles.';
            }
            if (!empty($_POST['rebuild'])) {
                $log[] = 'Rebuilt ' . rec_rebuild_all() . ' similarity pairs.';
            }
            $done = true;
        } catch (Throwable $e) {
            error_log('[rex setup] ' . $e->getMessage());
            $log[] = 'Setup failed: ' . ($cfg['debug'] ? $e->getMessage() : 'see the server error log, or set debug => true in config.php temporarily.');
        }
    }
}

if ($denied) {
    echo '<div class="flash err">That token is not right.</div>';
}
foreach ($log as $line) {
    echo '<p>' . e($line) . '</p>';
}
if ($done) {
    echo '<div class="flash ok"><b>Done. Now delete <code>setup.php</code> from the server</b> (Plesk → Files), then <a href="' . e(url('index.php')) . '">open the site</a>.</div>';
} else {
    ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Setup token <small>(the <code>setup_token</code> value from config.php)</small>
        <input type="password" name="token" required autocomplete="off">
      </label>
      <label class="check"><input type="checkbox" name="seed" value="1" checked> Add ~48 starter titles (movies, albums, books)</label>
      <label class="check"><input type="checkbox" name="rebuild" value="1"> Rebuild similarity table</label>
      <button class="btn">Create tables</button>
    </form>
    <?php
}
echo '</section>';
page_foot();
