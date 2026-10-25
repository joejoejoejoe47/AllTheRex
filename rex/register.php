<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
$errors = [];
$email = $name = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    $email = (string)($_POST['email'] ?? '');
    $name  = (string)($_POST['name'] ?? '');
    [$uid, $errors] = register_user($email, $name, (string)($_POST['password'] ?? ''));
    if ($uid) {
        log_in($uid);
        flash('Welcome! Rate a few titles you know and your recommendations will appear.');
        redirect('browse.php');
    }
}

page_head('Create account');
?>
<section class="formwrap">
  <h1>Create your account</h1>
  <form method="post" class="form" autocomplete="on" novalidate>
    <?= csrf_field() ?>
    <label>Display name
      <input name="name" value="<?= e($name) ?>" maxlength="60" required autofocus>
      <?php if (isset($errors['name'])): ?><small class="err"><?= e($errors['name']) ?></small><?php endif; ?>
    </label>
    <label>Email
      <input type="email" name="email" value="<?= e($email) ?>" maxlength="190" required autocomplete="email">
      <?php if (isset($errors['email'])): ?><small class="err"><?= e($errors['email']) ?></small><?php endif; ?>
    </label>
    <label>Password
      <input type="password" name="password" minlength="8" required autocomplete="new-password">
      <small>At least 8 characters.</small>
      <?php if (isset($errors['password'])): ?><small class="err"><?= e($errors['password']) ?></small><?php endif; ?>
    </label>
    <button class="btn">Create account</button>
    <p class="hint">Already have an account? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
  </form>
</section>
<?php page_foot();
