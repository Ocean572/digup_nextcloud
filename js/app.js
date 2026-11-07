/**
 * DigUp Search page script (js/digup-app.js — loaded by Util::addScript('digup','app')).
 *
 * Talks ONLY to Nextcloud: POST digup.api.search {q, limit} (same origin, the user's session).
 * The DigUp server URL and its token are server-side config — never in this script,
 * never in a browser URL (stage-12 §11.1). The engine's numbers (hits, z, strong,
 * ms, meaning, warning) arrive from the server verbatim, filtered to this user;
 * previews come from Nextcloud's own preview API with this user's session.
 */
(function () {
	'use strict';

	var $ = function (id) { return document.getElementById(id); };
	var root = $('digup-app');
	if (!root) {
		return;
	}
	var searchUrl = root.getAttribute('data-search-url');
	var debounce = null;
	var lastQuery = '';
	var generation = 0;

	function render(reply) {
		var list = $('digup-results');
		list.replaceChildren();
		var hits = reply.hits || [];
		var tiles = hits.map(function (hit) {
			var tile = document.createElement('li');
			tile.className = 'digup-tile' + (hit.strong ? ' digup-strong' : '');
			var link = document.createElement('a');
			link.href = hit.openUrl;
			link.className = 'digup-open';
			if (hit.kind === 'image' || hit.kind === 'screenshot' || hit.kind === 'video' || hit.kind === 'pdf') {
				var img = document.createElement('img');
				img.loading = 'lazy';
				img.decoding = 'async';
				img.alt = '';
				img.src = hit.previewUrl;
				img.addEventListener('error', function () {
					img.remove();                 // no preview: the tile keeps its label
					link.prepend(kindLabel(hit.kind));
				});
				link.append(img);
			} else {
				link.prepend(kindLabel(hit.kind));
			}
			var name = document.createElement('span');
			name.className = 'digup-name';
			name.textContent = hit.name;
			link.append(name);
			tile.append(link);
			var meta = document.createElement('span');
			meta.className = 'digup-meta';
			meta.textContent = (hit.z !== null && hit.z !== undefined ? 'z ' + hit.z.toFixed(2) : '')
				+ (hit.loc !== null && hit.loc !== undefined ? ' · at ' + Math.round(hit.loc) + 's' : '');
			tile.append(meta);
			return tile;
		});
		list.replaceChildren(...tiles);

		var ms = reply.ms || {};
		var bits = [hits.length + ' ' + (hits.length === 1 ? 'hit' : 'hits')
			+ ' · ' + reply.strong + ' strong'];
		if (hits.length && reply.meaning) {
			bits.push('embed ' + (ms.embed || 0) + ' ms · search ' + (ms.search || 0) + ' ms');
		}
		if (reply.warning) {
			bits.push(reply.warning);
		}
		$('digup-status').textContent = bits.join(' · ');
	}

	function kindLabel(kind) {
		var label = document.createElement('span');
		label.className = 'digup-kind';
		label.textContent = kind;
		return label;
	}

	function search(query) {
		$('digup-activity').hidden = false;
		var my = ++generation;          // newest query wins: stale replies are dropped
		fetch(searchUrl, {
			method: 'POST',
			headers: {
				'content-type': 'application/json',
				requesttoken: OC.requestToken
			},
			credentials: 'same-origin',
			body: JSON.stringify({ q: query, limit: Number($('digup-limit').value) })
		})
			.then(function (answer) { return answer.json(); })
			.then(function (reply) {
				if (my !== generation) {
					return;
				}
				$('digup-activity').hidden = true;
				render(reply);
			})
			.catch(function (error) {
				if (my !== generation) {
					return;
				}
				$('digup-activity').hidden = true;
				$('digup-status').textContent = String(error);
			});
	}

	$('digup-q').addEventListener('input', function () {
		var query = this.value.trim();
		clearTimeout(debounce);
		if (query.length < 2) {
			generation++;                 // invalidate any in-flight search's reply
			$('digup-results').replaceChildren();
			$('digup-status').textContent = 'Say what you’d see, hear, or read — the meaning, not only the words.';
			return;
		}
		lastQuery = query;
		debounce = setTimeout(function () { search(lastQuery); }, 250);
	});
	$('digup-limit').addEventListener('change', function () {
		var query = $('digup-q').value.trim();
		if (query.length >= 2) {
			search(query);
		}
	});
	$('digup-q').focus();
})();
