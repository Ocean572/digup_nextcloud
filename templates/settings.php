<?php
/**
 * Administration settings form (admin surface): the operator pastes the DigUp server
 * URL, its read token, the mount prefix the server uses, and the path layout (data
 * dir vs per-user WebDAV mounts — §11.7). The token field is write-only: its stored
 * value is never rendered here — only whether one is set.
 */
$s = $_['settings'];
?>
<div id="digup-settings"
     data-test-url="<?php p($_['testUrl']); ?>"
     data-save-url="<?php p($_['saveUrl']); ?>">
  <h2><?php p($l->t('DigUp Search')); ?></h2>
  <p class="settings-hint">
    <?php p($l->t('A self-hosted DigUp server indexes this instance’s data directory read-only and answers semantic media searches. The app filters every answer to the files each user can access. The server may run on another machine (the one with the GPU). Setup guide: the digup_nextcloud repository README.')); ?>
  </p>

  <div class="digup-setting">
    <label for="digup-base-url"><?php p($l->t('DigUp server URL')); ?></label>
    <input id="digup-base-url" type="url" value="<?php p($s['base_url']); ?>"
           placeholder="http://192.0.2.10:8787" style="width: 24em;">
  </div>
  <div class="digup-setting">
    <label for="digup-root-prefix"><?php p($l->t('Path the server mounts the share at')); ?></label>
    <input id="digup-root-prefix" type="text" value="<?php p($s['root_prefix']); ?>"
           placeholder="/media/photos" style="width: 24em;">
  </div>
  <div class="digup-setting">
    <label for="digup-mapping-mode"><?php p($l->t('How the server sees this instance’s files')); ?></label>
    <select id="digup-mapping-mode">
      <option value="data" <?php p($s['mapping_mode'] === 'webdav' ? '' : 'selected'); ?>>
        <?php p($l->t('Data directory (raw <user>/files layout)')); ?>
      </option>
      <option value="webdav" <?php p($s['mapping_mode'] === 'webdav' ? 'selected' : ''); ?>>
        <?php p($l->t('WebDAV mounts (<user> = each user’s own view, davfs2)')); ?>
      </option>
    </select>
  </div>
  <div class="digup-setting">
    <label for="digup-read-token"><?php p($l->t('Read token')); ?></label>
    <input id="digup-read-token" type="password" autocomplete="new-password"
           placeholder="<?php p($s['read_token_set'] ? $l->t('•••••• (saved — type to replace)') : $l->t('paste the server’s read token')); ?>"
           style="width: 24em;">
  </div>
  <div class="digup-setting digup-setting-check">
    <input id="digup-verify-tls" type="checkbox" <?php p($s['verify_tls'] ? 'checked' : ''); ?>>
    <label for="digup-verify-tls"><?php p($l->t('Verify TLS certificates')); ?></label>
  </div>
  <p class="digup-buttons">
    <button id="digup-save" class="primary"><?php p($l->t('Save')); ?></button>
    <button id="digup-test"><?php p($l->t('Test connection')); ?></button>
  </p>
  <p id="digup-settings-status" role="status"></p>
</div>
