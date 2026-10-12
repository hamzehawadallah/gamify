<?php $hideNewButton = true; require __DIR__ . '/_nav.php'; ?>
<main class="wrap">
  <header class="page-head">
    <div>
      <a class="back-link" href="<?= e($editor ? url('admin/activity/' . $editor['id']) : url('admin')) ?>"><?= icon('chevron-left') ?><?= $editor ? 'Back to activity' : 'Activities' ?></a>
      <h1><?= $editor ? 'Edit activity' : 'New activity' ?></h1>
    </div>
  </header>

  <div class="editor">
    <form class="editor-form" id="editor" novalidate>
      <section>
        <h2 class="section-label">Activity</h2>
        <div class="card q-card">
          <label class="sr-only" for="a-title">Activity title</label>
          <textarea class="q-title" id="a-title" rows="1" maxlength="500" placeholder="Activity title" dir="auto"></textarea>
          <div class="q-sep"></div>
          <label class="sr-only" for="a-desc">Instructions (optional)</label>
          <textarea class="q-desc" id="a-desc" rows="1" maxlength="1000" placeholder="Instructions for participants (optional)" dir="auto"></textarea>
        </div>
        <p class="field-hint" style="margin:10px 16px 0">One QR code for the whole activity. Add as many questions as you need below.</p>
      </section>

      <section>
        <h2 class="section-label">Questions</h2>
        <div class="questions" id="questions"></div>
        <button type="button" class="add-question" id="add-question"><?= icon('plus') ?>Add question</button>
      </section>

      <section>
        <h2 class="section-label">Voting</h2>
        <div class="list">
          <label class="list-row">
            <span class="list-row-text"><span class="list-row-title">Let people change their answers</span><span class="list-row-sub">While voting is open.</span></span>
            <input class="switch" type="checkbox" data-setting="allow_change">
          </label>
          <label class="list-row">
            <span class="list-row-text"><span class="list-row-title">Show results on phones</span><span class="list-row-sub">After answering, participants see live results too.</span></span>
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
    <button class="btn btn-primary" type="submit" form="editor" id="save-btn"><?= $editor ? 'Save changes' : 'Create activity' ?></button>
  </div>
</div>
<input type="file" id="file-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
<script type="application/json" id="gm-data"><?= json_for_html(['editor' => $editor, 'defaults' => activity_default_settings(), 'colors' => GM_QUESTION_COLORS, 'maxQuestions' => GM_MAX_QUESTIONS]) ?></script>
