<?php require __DIR__ . '/_nav.php'; ?>
<main class="wrap">
  <header class="page-head">
    <div>
      <h1>Activities</h1>
      <p class="sub">Each activity has its own QR code. Share it, then watch the results come in.</p>
    </div>
  </header>

  <?php if (isset($_GET['welcome'])): ?>
    <div class="welcome"><?= icon('check') ?><span><b>You’re all set.</b> Create your first activity, then open it to get its QR code and presenter link.</span></div>
  <?php endif; ?>

  <?php if (!$activities): ?>
    <section class="empty card">
      <svg class="empty-art" viewBox="0 0 120 120" aria-hidden="true">
        <defs><linearGradient id="eg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3b9bff"/><stop offset="1" stop-color="#0062e0"/></linearGradient></defs>
        <rect x="10" y="10" width="100" height="100" rx="26" fill="url(#eg)" opacity=".12"/>
        <rect x="30" y="58" width="14" height="32" rx="3" fill="url(#eg)" opacity=".55"/>
        <rect x="53" y="32" width="14" height="58" rx="3" fill="url(#eg)"/>
        <rect x="76" y="46" width="14" height="44" rx="3" fill="url(#eg)" opacity=".8"/>
      </svg>
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
