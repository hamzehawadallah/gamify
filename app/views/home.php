<main class="join">
  <div class="join-inner">
    <?= brand_logo('join-mark') ?>
    <h1>Join an activity</h1>
    <p class="join-sub">Enter the 6-digit code you see on screen.</p>
    <form id="join-form" novalidate autocomplete="off">
      <div class="code-input" id="code-input" role="group" aria-label="Activity code">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <?php if ($i === 3): ?><span class="gap" aria-hidden="true"></span><?php endif; ?>
          <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit <?= $i + 1 ?>"<?= $i === 0 ? ' autocomplete="one-time-code"' : '' ?>>
        <?php endfor; ?>
      </div>
      <p class="join-error" id="join-error" role="alert"></p>
      <button class="btn btn-primary btn-lg" type="submit" id="join-btn" disabled>Join <?= icon('arrow-right') ?></button>
    </form>
  </div>
</main>
<footer class="join-foot">
  <?= e(app_name()) ?> · <a href="<?= e(url('admin')) ?>">Presenter sign in</a>
</footer>
