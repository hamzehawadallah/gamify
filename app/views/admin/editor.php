<?php $hideNewButton = true; require __DIR__ . '/_nav.php'; ?>
<main class="wrap">
  <header class="page-head">
    <div>
      <a class="back-link" href="<?= e($editor ? url('admin/activity/' . $editor['id']) : url('admin')) ?>"><?= icon('chevron-left') ?><?= $editor ? 'Back to poll' : 'Activities' ?></a>
      <h1><?= $editor ? 'Edit poll' : 'New poll' ?></h1>
    </div>
  </header>

  <div class="editor">
    <form class="editor-form" id="editor" novalidate>
      <section>
        <h2 class="section-label">Question</h2>
        <div class="card q-card">
          <label class="sr-only" for="q-title">Question</label>
          <textarea class="q-title" id="q-title" rows="1" maxlength="500" placeholder="What would you like to ask?" dir="auto"></textarea>
          <div class="q-sep"></div>
          <label class="sr-only" for="q-desc">Description (optional)</label>
          <textarea class="q-desc" id="q-desc" rows="1" maxlength="1000" placeholder="Add a description (optional)" dir="auto"></textarea>
        </div>
      </section>

      <section>
        <h2 class="section-label">Options</h2>
        <div class="card" style="overflow:hidden">
          <div class="opt-editor" id="opt-list"></div>
          <button type="button" class="opt-add" id="opt-add"><?= icon('plus') ?>Add option</button>
        </div>
        <p class="field-hint" style="margin:10px 16px 0">Tap the image square to add a picture. Drag the handle to reorder.</p>
      </section>

      <section>
        <h2 class="section-label">Voting</h2>
        <div class="list">
          <label class="list-row">
            <span class="list-row-text"><span class="list-row-title">Allow multiple answers</span><span class="list-row-sub">Participants can pick more than one option.</span></span>
            <input class="switch" type="checkbox" data-setting="multiple">
          </label>
          <div class="list-row is-sub" id="max-row" hidden>
            <span class="list-row-text"><span class="list-row-title">Maximum choices</span></span>
            <span class="stepper"><button type="button" data-step="-1" aria-label="Fewer">−</button><output id="max-out">No limit</output><button type="button" data-step="1" aria-label="More">+</button></span>
          </div>
          <label class="list-row">
            <span class="list-row-text"><span class="list-row-title">Let people change their vote</span><span class="list-row-sub">While voting is open.</span></span>
            <input class="switch" type="checkbox" data-setting="allow_change">
          </label>
          <label class="list-row">
            <span class="list-row-text"><span class="list-row-title">Show results on phones</span><span class="list-row-sub">After voting, participants see live results too.</span></span>
            <input class="switch" type="checkbox" data-setting="show_results">
          </label>
        </div>
      </section>

      <section>
        <h2 class="section-label">Presenter screen</h2>
        <div class="list">
          <label class="list-row">
            <span class="list-row-text"><span class="list-row-title">Show QR code</span><span class="list-row-sub">So people can join straight from the slide.</span></span>
            <input class="switch" type="checkbox" data-setting="show_qr">
          </label>
          <label class="list-row">
            <span class="list-row-text"><span class="list-row-title">Show voters’ initials</span><span class="list-row-sub">A row of avatars that grows as people vote.</span></span>
            <input class="switch" type="checkbox" data-setting="show_voters">
          </label>
        </div>
      </section>
    </form>

    <aside class="preview" aria-label="Phone preview">
      <span class="preview-label">Live preview</span>
      <div class="phone"><div class="phone-screen" id="preview"></div></div>
    </aside>
  </div>
</main>

<div class="editor-bar">
  <div class="wrap">
    <span class="note" id="editor-note"></span>
    <a class="btn" href="<?= e($editor ? url('admin/activity/' . $editor['id']) : url('admin')) ?>">Cancel</a>
    <button class="btn btn-primary" type="submit" form="editor" id="save-btn"><?= $editor ? 'Save changes' : 'Create poll' ?></button>
  </div>
</div>
<input type="file" id="file-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
<script type="application/json" id="gm-data"><?= json_for_html(['editor' => $editor, 'defaults' => poll_default_settings()]) ?></script>
