<?php
/**
 * The DigUp page: search box top-left, results-count selector beside it, status line,
 * preview grid (docs/implementation/stage-12-integrations.md §11.4, Files-style).
 * The only data here is the SAME-ORIGIN route the JS calls; the DigUp server's URL
 * and token never reach the browser. (The controller adds the script and the style;
 * the template adds nothing.)
 */
?>
<div id="digup-app" data-search-url="<?php p($_['searchUrl']); ?>" data-progress-url="<?php p($_['progressUrl'] ?? ''); ?>">
  <header class="digup-bar">
    <input id="digup-q" type="search" autocomplete="off" spellcheck="false"
           placeholder="<?php p($l->t('Search your media by meaning — photos, video, documents…')); ?>"
           aria-label="<?php p($l->t('Search')); ?>" aria-describedby="digup-status">
    <label class="digup-count" title="<?php p($l->t('Results per search — the engine’s own cap is 100')); ?>">
      <select id="digup-limit" aria-label="<?php p($l->t('Number of results')); ?>">
        <option value="10">10</option>
        <option value="20" selected>20</option>
        <option value="40">40</option>
        <option value="100">100</option>
      </select>
      <?php p($l->t('results')); ?>
    </label>
    <span id="digup-activity" class="digup-activity" aria-hidden="true"></span>
    <span id="digup-progress" class="digup-progress" aria-live="polite"></span>
  </header>
  <main class="digup-main">
    <p id="digup-status" role="status"><?php p($l->t('Say what you’d see, hear, or read — the meaning, not only the words.')); ?></p>
    <ul id="digup-results" class="digup-grid"></ul>
  </main>
</div>
