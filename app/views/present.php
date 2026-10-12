<div class="stage" id="stage">
  <header class="stage-head">
    <div class="join-pill" id="join-pill"></div>
    <div class="head-right">
      <span class="stage-badge" id="closed-badge" hidden><?= icon('lock') ?>Voting closed</span>
      <div class="count-pill" id="count-pill"><span class="live-dot" id="live-dot"></span><b id="voter-count">0</b><span id="voter-label">votes</span></div>
    </div>
  </header>
  <main class="stage-main">
    <div>
      <div class="stage-kicker" id="stage-kicker" dir="auto" hidden></div>
      <h1 class="stage-title" id="stage-title" dir="auto"></h1>
      <p class="stage-desc" id="stage-desc" dir="auto" hidden></p>
    </div>
    <div class="stage-body" id="stage-body"></div>
  </main>
  <aside class="stage-side" id="stage-side">
    <div class="qr-card">
      <div class="qr" id="qr"></div>
      <div class="qr-card-label">Scan to vote</div>
      <div class="qr-card-code" id="qr-code"></div>
    </div>
  </aside>
  <section class="stage-wall" id="stage-wall" hidden></section>
  <footer class="stage-foot">
    <div class="voters" id="voters" aria-label="Recent voters"></div>
    <span class="brand"><?= brand_mark() ?><span><?= e(app_name()) ?></span></span>
  </footer>
</div>
<script type="application/json" id="gm-data"><?= json_for_html($data) ?></script>
