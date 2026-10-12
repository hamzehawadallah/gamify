<?php require __DIR__ . '/_nav.php'; ?>
<main class="wrap">
  <header class="act-head">
    <a class="back-link" href="<?= e(url('admin')) ?>"><?= icon('chevron-left') ?>Activities</a>
    <h1 class="act-title" id="act-title" dir="auto"></h1>
    <div class="act-meta" id="act-meta"></div>
  </header>

  <div class="act-controls">
    <div class="ctrl">
      <span>Voting</span>
      <div class="segmented segmented-sm" id="status-seg" role="radiogroup" aria-label="Voting">
        <span class="segmented-thumb"></span>
        <button type="button" role="radio" data-value="open">Open</button>
        <button type="button" role="radio" data-value="closed">Closed</button>
      </div>
    </div>
    <label class="ctrl">
      <span>Hide results on screen</span>
      <input class="switch" type="checkbox" id="hidden-switch">
    </label>
    <span class="spacer"></span>
    <a class="btn btn-sm" href="<?= e(url('admin/activity/' . $data['id'] . '/edit')) ?>"><?= icon('edit') ?>Edit</a>
    <div class="menu-wrap">
      <button class="btn btn-sm btn-icon" type="button" id="more-btn" aria-haspopup="menu" aria-expanded="false" aria-label="More actions"><?= icon('more') ?></button>
      <div class="menu" id="more-menu" role="menu" hidden>
        <a role="menuitem" href="<?= e(url('admin/activity/' . $data['id'] . '/export.csv')) ?>"><?= icon('download') ?>Export results (CSV)</a>
        <button role="menuitem" type="button" data-action="duplicate"><?= icon('copy') ?>Duplicate</button>
        <hr>
        <button role="menuitem" type="button" data-action="reset"><?= icon('reset') ?>Clear all votes</button>
        <button role="menuitem" type="button" data-action="delete" class="danger"><?= icon('trash') ?>Delete poll</button>
      </div>
    </div>
    <a class="btn btn-primary btn-sm" id="present-btn" href="<?= e($data['present_url']) ?>" target="_blank" rel="noopener"><?= icon('present') ?>Present</a>
  </div>

  <div class="act-grid">
    <div class="act-col">
      <section class="card results-card">
        <div class="card-head">
          <div>
            <h2 class="card-title">Live results</h2>
          </div>
          <span class="card-sub" style="display:inline-flex;align-items:center;gap:8px"><span class="live-dot" id="live-dot"></span><span id="voter-count">0 votes</span></span>
        </div>
        <div class="hidden-banner" id="hidden-banner" hidden><?= icon('eye-off') ?><span>Results are hidden on the presenter screen and phones. Only you can see them.</span></div>
        <div id="results"></div>
      </section>

      <section class="card">
        <div class="card-head" style="padding-bottom:6px">
          <h2 class="card-title">Responses</h2>
          <span class="card-sub" id="resp-count"></span>
        </div>
        <div class="resp-list" id="resp-list"></div>
      </section>
    </div>

    <div class="act-col">
      <section class="card share-card">
        <div class="qr" id="qr" role="img" aria-label="QR code to join"></div>
        <div>
          <div class="share-code" id="share-code"></div>
          <p class="muted" style="font-size:14px;margin-top:6px">Scan or enter the code to join</p>
        </div>
        <div class="copy-field"><span id="join-url"></span><button class="btn btn-sm btn-plain" type="button" data-copy="join"><?= icon('copy') ?>Copy</button></div>
        <div class="share-actions">
          <button class="btn btn-sm btn-tinted" type="button" id="dl-png"><?= icon('download') ?>QR as PNG</button>
          <button class="btn btn-sm btn-tinted" type="button" id="dl-svg"><?= icon('download') ?>QR as SVG</button>
        </div>
      </section>

      <section class="card present-card">
        <div class="row">
          <h2 class="card-title">Present</h2>
          <div class="segmented segmented-sm" id="theme-seg" role="radiogroup" aria-label="Presenter theme">
            <span class="segmented-thumb"></span>
            <button type="button" role="radio" data-value="light">Light</button>
            <button type="button" role="radio" data-value="dark">Dark</button>
          </div>
        </div>
        <p class="muted" style="font-size:14px">Use this link in PowerPoint, or open it on the projector. Results update live.</p>
        <div class="copy-field"><span id="present-url"></span><button class="btn btn-sm btn-plain" type="button" data-copy="present"><?= icon('copy') ?>Copy</button></div>
        <details class="disclosure">
          <summary>Add to PowerPoint <?= icon('chevron-right') ?></summary>
          <ol class="steps">
            <li><span>In PowerPoint, go to <b>Insert → Get Add-ins</b> (or <b>Add-ins</b>) and add <b>Web Viewer</b>.</span></li>
            <li><span>Insert Web Viewer on a slide and paste the presenter link above.</span></li>
            <li><span>Start the slideshow. Votes appear instantly — no refresh needed.</span></li>
            <li><span>The link must start with <b>https://</b>. Turn on SSL for your domain in hPanel if it doesn’t.</span></li>
          </ol>
        </details>
      </section>
    </div>
  </div>
</main>
<script type="application/json" id="gm-data"><?= json_for_html($data) ?></script>
