<?php $bodyClass = 'center-page'; ?>
<main class="setup">
  <header class="setup-head">
    <?= brand_logo('join-mark') ?>
    <h1>Set up <?= e($v['app_name'] ?: 'VCL Gamify') ?></h1>
    <p>Connect your database, then choose your admin login.</p>
  </header>

  <?php if ($manualConfig): ?>
    <section class="card card-pad" style="display:grid;gap:14px">
      <h2 class="card-title">Almost done</h2>
      <p class="muted">The database is ready, but this server didn’t let us save <b>config.php</b>. In hPanel → File Manager, create a file named <b>config.php</b> next to <b>index.php</b>, paste the text below, save it, then reload this page.</p>
      <pre><?= e($manualConfig) ?></pre>
    </section>
  <?php else: ?>
    <form method="post" action="<?= e(url('setup')) ?>" autocomplete="off">
      <?php foreach ($errors as $err): ?>
        <div class="alert" role="alert"><?= icon('alert') ?><span><?= e($err) ?></span></div>
      <?php endforeach; ?>

      <fieldset>
        <legend>1 · Database</legend>
        <p class="field-hint" style="margin-top:-6px">On Hostinger: hPanel → Databases → MySQL Databases.</p>
        <div class="row2">
          <label class="field"><span class="field-label">Host</span><input class="input" name="db_host" value="<?= e($v['db_host']) ?>" required></label>
          <label class="field"><span class="field-label">Port</span><input class="input" name="db_port" value="<?= e($v['db_port']) ?>" inputmode="numeric"></label>
        </div>
        <label class="field"><span class="field-label">Database name</span><input class="input" name="db_name" value="<?= e($v['db_name']) ?>" placeholder="u123456789_gamify" required></label>
        <label class="field"><span class="field-label">Database user</span><input class="input" name="db_user" value="<?= e($v['db_user']) ?>" placeholder="u123456789_gamify" required></label>
        <label class="field"><span class="field-label">Database password</span><input class="input" name="db_pass" type="password" autocomplete="new-password"></label>
      </fieldset>

      <fieldset>
        <legend>2 · Admin login</legend>
        <label class="field"><span class="field-label">App name</span><input class="input" name="app_name" value="<?= e($v['app_name']) ?>"></label>
        <label class="field"><span class="field-label">Username</span><input class="input" name="admin_user" value="<?= e($v['admin_user']) ?>" autocapitalize="none" required></label>
        <label class="field"><span class="field-label">Password</span><input class="input" name="admin_pass" type="password" minlength="8" autocomplete="new-password" required><span class="field-hint">At least 8 characters.</span></label>
      </fieldset>

      <button class="btn btn-primary btn-lg" type="submit">Finish setup</button>
    </form>
  <?php endif; ?>
</main>
