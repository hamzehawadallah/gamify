<?php require __DIR__ . '/_nav.php'; ?>
<main class="wrap">
  <header class="page-head">
    <div>
      <h1>Activities</h1>
    </div>
  </header>

  <?php if (isset($_GET['welcome'])): ?>
    <div class="welcome"><?= icon('check') ?><span><b>You’re all set.</b> Create your first activity, then open it to get its QR code and presenter link.</span></div>
  <?php endif; ?>

  <?php if (!$activities): ?>
    <section class="empty card">
      <h2>No activities yet</h2>
      <p>An activity has one QR code and one or more questions. Participants scan it and vote from their phones.</p>
      <a class="btn btn-primary btn-lg" href="<?= e(url('admin/new')) ?>"><?= icon('plus') ?>Create an activity</a>
    </section>
  <?php else: ?>
    <div class="toolbar">
      <label class="search">
        <?= icon('search') ?>
        <span class="sr-only">Search activities</span>
        <input class="input" type="search" id="search" placeholder="Search" autocomplete="off">
      </label>
      <span class="muted" id="count" style="font-size:14px"></span>
    </div>
    <section class="grid-cards" id="cards"></section>
    <p class="empty" id="no-match" hidden>No activities match your search.</p>
  <?php endif; ?>
</main>
<script type="application/json" id="gm-data"><?= json_for_html(['activities' => $activities]) ?></script>
