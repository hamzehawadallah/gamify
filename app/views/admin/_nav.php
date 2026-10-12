<nav class="nav">
  <div class="wrap nav-inner">
    <a class="brand" href="<?= e(url('admin')) ?>"><?= brand_mark() ?><span><?= e(app_name()) ?></span></a>
    <div class="nav-actions">
      <?php if (empty($hideNewButton)): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('admin/new')) ?>"><?= icon('plus') ?><span class="label">New activity</span></a>
      <?php endif; ?>
      <form method="post" action="<?= e(url('admin/logout')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-icon btn-sm" type="submit" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></button>
      </form>
    </div>
  </div>
</nav>
