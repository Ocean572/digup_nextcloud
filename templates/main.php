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
    <span class="digup-spin-group">
      <span id="digup-activity" class="digup-activity" aria-hidden="true"></span>
      <span id="digup-progress" class="digup-progress" aria-live="polite"></span>
    </span>
  </header>
  <!-- Kind filter (user brief 2026-11-08): the same chips as the standalone app, under
       the search box. Semantics verbatim from the standalone: all ticked = the engine's
       "all kinds", a partial set = filter, none ticked = the engine's own answer (0 hits).
       The all button is a toggle (all on ↔ all off), aria-pressed carries the state. -->
  <div id="digup-kinds" class="digup-kinds" role="group"
       aria-label="<?php p($l->t('Filter by media type')); ?>">
    <?php foreach ([
      'screenshot' => $l->t('Screenshots'),
      'image' => $l->t('Images'),
      'pdf' => $l->t('PDFs'),
      'doc' => $l->t('Documents'),
      'video' => $l->t('Video'),
      'audio' => $l->t('Audio'),
    ] as $digupKind => $digupLabel): ?>
      <label class="digup-kindchip" data-kind="<?= p($digupKind) ?>">
        <input type="checkbox" value="<?= p($digupKind) ?>" checked> <?= p($digupLabel) ?>
      </label>
    <?php endforeach; ?>
    <button type="button" id="digup-kinds-all" class="digup-kinds-all" aria-pressed="true"
            title="<?php p($l->t('All media types on — click to clear them all')); ?>">all</button>
  </div>
  <main class="digup-main">
    <p id="digup-status" role="status"><?php p($l->t('Say what you’d see, hear, or read — the meaning, not only the words.')); ?></p>
    <div class="digup-panes">
      <ul id="digup-results" class="digup-grid"></ul>
      <aside id="digup-detail" hidden aria-label="<?php p($l->t('Preview')); ?>">
        <div class="digup-detail-bar">
          <button type="button" id="digup-detail-prev" aria-label="<?php p($l->t('Previous hit')); ?>">‹</button>
          <span id="digup-detail-name" class="digup-detail-name"></span>
          <button type="button" id="digup-detail-next" aria-label="<?php p($l->t('Next hit')); ?>">›</button>
          <button type="button" id="digup-detail-close" aria-label="<?php p($l->t('Close preview')); ?>">✕</button>
        </div>
        <div id="digup-detail-media" class="digup-detail-media"></div>
        <p id="digup-detail-meta" class="digup-meta"></p>
        <a id="digup-detail-open" class="button" target="_blank" rel="noopener noreferrer"><?php p($l->t('Open in Files')); ?></a>
      </aside>
    </div>
  </main>
  <div id="digup-lightbox" hidden role="dialog" aria-label="<?php p($l->t('Enlarged preview')); ?>">
    <div class="digup-lightbox-bar">
      <a id="digup-lightbox-open" class="button" target="_blank" rel="noopener noreferrer"><?php p($l->t('Open in Files')); ?></a>
      <button type="button" id="digup-lightbox-close" aria-label="<?php p($l->t('Close')); ?>">✕</button>
    </div>
    <div id="digup-lightbox-media" class="digup-detail-media"></div>
  </div>
</div>
