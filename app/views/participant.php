<div class="p-shell" id="app">
  <main class="p-main">
    <div class="p-state">
      <div class="spinner" style="margin:0 auto;color:var(--label-3)"></div>
    </div>
  </main>
  <footer class="p-foot">
    <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e(app_name()) ?> home"><?= brand_logo() ?></a>
  </footer>
</div>
<noscript><p style="text-align:center;padding:20px">Please turn on JavaScript to take part.</p></noscript>
<script type="application/json" id="gm-data"><?= json_for_html($data) ?></script>
