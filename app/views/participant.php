<div class="p-shell" id="app">
  <header class="p-top">
    <a class="brand" href="<?= e(url('/')) ?>"><?= brand_logo() ?></a>
  </header>
  <main class="p-main">
    <div class="p-state">
      <div class="spinner" style="margin:0 auto;color:var(--label-3)"></div>
    </div>
  </main>
</div>
<noscript><p style="text-align:center;padding:20px">Please turn on JavaScript to take part.</p></noscript>
<script type="application/json" id="gm-data"><?= json_for_html($data) ?></script>
