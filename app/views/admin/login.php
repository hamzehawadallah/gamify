<?php $bodyClass = 'center-page'; ?>
<main class="auth">
  <?= brand_logo('join-mark') ?>
  <h1>Sign in</h1>
  <form method="post" action="<?= e(url('admin/login')) ?>">
    <?php if ($error): ?>
      <div class="alert" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>
    <div class="input-group">
      <label class="sr-only" for="username">Username</label>
      <input class="input" id="username" name="username" placeholder="Username" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($username) ?>" required<?= $username === '' ? ' autofocus' : '' ?>>
      <label class="sr-only" for="password">Password</label>
      <input class="input" id="password" name="password" type="password" placeholder="Password" autocomplete="current-password" required<?= $username !== '' ? ' autofocus' : '' ?>>
    </div>
    <button class="btn btn-primary btn-lg" type="submit">Sign in</button>
  </form>
</main>
