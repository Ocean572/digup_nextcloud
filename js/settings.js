/**
 * Admin settings script (js/digup-settings.js — loaded by the settings form).
 * Talks to the app's own admin endpoints with the CSRF header (requesttoken),
 * like every Nextcloud settings script. The stored token is never read back into
 * the page — the password field starts empty, empty means "keep the saved one".
 */
(function () {
	'use strict';

	var root = document.getElementById('digup-settings');
	if (!root) {
		return;
	}
	var status = document.getElementById('digup-settings-status');

	function post(url, body) {
		return fetch(url, {
			method: 'POST',
			headers: {
				'content-type': 'application/json',
				requesttoken: OC.requestToken
			},
			credentials: 'same-origin',
			body: JSON.stringify(body)
		}).then(function (answer) { return answer.json(); });
	}

	function show(answer, kind) {
		if (answer.ok) {
			if (kind === 'test') {
				status.textContent = 'Connected'
					+ (answer.searchable !== null && answer.searchable !== undefined
						? ' — ' + answer.searchable + ' searchable files' : '')
					+ (answer.build_tag ? ' (engine ' + answer.build_tag + ')' : '')
					+ (answer.hint ? ' — ' + answer.hint : '');
			} else {
				status.textContent = 'Saved.';
			}
		} else {
			status.textContent = answer.detail || 'The server refused.';
		}
	}

	function payload() {
		return {
			base_url: document.getElementById('digup-base-url').value.trim(),
			root_prefix: document.getElementById('digup-root-prefix').value.trim(),
			mapping_mode: document.getElementById('digup-mapping-mode').value,
			read_token: document.getElementById('digup-read-token').value.trim(),
			verify_tls: document.getElementById('digup-verify-tls').checked
		};
	}

	document.getElementById('digup-save').addEventListener('click', function () {
		status.textContent = '…';
		post(root.getAttribute('data-save-url'), payload())
			.then(function (answer) { show(answer, 'save'); })
			.catch(function (e) { status.textContent = String(e); });
	});
	document.getElementById('digup-test').addEventListener('click', function () {
		status.textContent = '…';
		post(root.getAttribute('data-test-url'), payload())
			.then(function (answer) { show(answer, 'test'); })
			.catch(function (e) { status.textContent = String(e); });
	});
})();
