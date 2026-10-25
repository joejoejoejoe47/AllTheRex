<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
$error = '';
$email = '';
$next  = safe_back((string)($_GET['next'] ?? $_POST['next'] ?? ''), 'index.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    $email = (string)($_POST['email'] ?? '');
    if (login_blocked()) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } elseif (($uid = check_credentials($email, (string)($_POST['password'] ?? ''))) !== null) {
        log_in($uid);
        redirect($next);
    } else {
        note_failed_login();
        $error = 'That email and password don\'t match.';
    }
}

page_head('Log in');
?>
<section class="formwrap">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Email
      <input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn">Log in</button>
    <p class="hint">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
  </form>
</section>
<?php page_foot();
