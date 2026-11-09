/**
 * DigUp Search page script (js/digup-app.js — loaded by Util::addScript('digup','app')).
 *
 * Talks ONLY to Nextcloud: POST digup.api.search {q, limit} (same origin, the user's session).
 * The DigUp server URL and its token are server-side config — never in this script,
 * never in a browser URL (stage-12 §11.1). The engine's numbers (hits, z, strong,
 * ms, meaning, warning) arrive from the server verbatim, filtered to this user.
 *
 * Two panes (UX ask 2026-11-08): the results grid, and a preview pane on its right.
 * A plain click on a tile opens the pane on that hit (Ctrl/⌘-click still jumps
 * straight to Files); the pane walks the hit list with ‹ › / arrow keys, shows a big
 * core/preview image, a video that starts AT the matched segment (the engine's loc),
 * an audio player, a first-page preview for PDFs, and an Open-in-Files link. All
 * pane media rides core surfaces the user's own session is allowed to
 * (core/preview, the Files webdav stream) — no new endpoints, no token in any URL.
 * A click on a pane image enlarges it (lightbox), which also offers Open-in-Files.
 */
(function () {
	'use strict';

	var $ = function (id) { return document.getElementById(id); };
	var root = $('digup-app');
	if (!root) {
		return;
	}
	var searchUrl = root.getAttribute('data-search-url');
	var progressUrl = root.getAttribute('data-progress-url');
	var debounce = null;
	var lastQuery = '';
	var generation = 0;
	var progressTimer = null;
	var lastHits = [];   // the preview pane walks these
	var selected = -1;
	var KINDS = ['screenshot', 'image', 'pdf', 'doc', 'audio', 'video'];
	var jobRunning = false;      // the server's own job state (from /progress)
	var searchInFlight = false;  // the activity icon belongs to BOTH sources:
	var searchIn = null;         // a search in flight AND a running index job

	function fmt(n) {
		return n === null || n === undefined ? '?' : Number(n).toLocaleString();
	}

	function progressLine(p) {
		if (!p || !p.ok) {
			return '';
		}
		// While the job walk has not published its total (the record's files
		// arrives at sync end), the engine's own count (index_files) is the
		// denominator — measured 2026-11-08: a running job answers files:null
		// with index_files 131,973, and the line must ride beside the spinner
		// as N / total, never N / ? (UX ask).
		if (p.state === 'running') {
			var total = p.files || p.index_files;
			if (total && p.files_done !== null && p.files_done < total) {
				var pct = (100 * p.files_done / total).toFixed(1);
				return 'indexing ' + fmt(p.files_done) + ' / ' + fmt(total) + ' files (' + pct + ' %)';
			}
		}
		if (p.index_files !== null && p.files && p.index_files < p.files) {
			return 'index ' + fmt(p.index_files) + ' / ' + fmt(p.files)
				+ ' files — run “Index now” to pick up changes';
		}
		return '';
	}

	function showProgress(p) {
		var el = $('digup-progress');
		var line = progressLine(p);
		if (el) {
			el.textContent = line;
		}
		return line;
	}

	function setActivity() {
		$('digup-activity').hidden = !(searchInFlight || jobRunning);
	}

	function refreshProgress() {
		if (!progressUrl) {
			return;
		}
		fetch(progressUrl, {
			headers: { requesttoken: OC.requestToken },
			credentials: 'same-origin'
		})
			.then(function (a) { return a.json(); })
			.then(function (p) {
				showProgress(p);
				// The live counts ride beside the spinner the WHOLE time the job
				// walks (user brief 2026-11-08): while the server answers state
				// 'running', the poll keeps itself armed — the spinner stays
				// visible, and progressLine shows files_done / total from there.
				jobRunning = Boolean(p && p.ok && p.state === 'running');
				setActivity();
				if (jobRunning && progressTimer === null) {
					progressTimer = setInterval(refreshProgress, 2000);
				}
			})
			.catch(function () { /* the progress line is optional; the search is not */ });
	}

	function startProgressPolling() {
		refreshProgress();
		if (progressTimer === null && progressUrl) {
			progressTimer = setInterval(refreshProgress, 2000);
		}
	}

	function stopProgressPolling() {
		if (progressTimer !== null) {
			clearInterval(progressTimer);
			progressTimer = null;
		}
		refreshProgress();   // re-arms itself when the job is still running
	}

	refreshProgress();     // the page opens with the index state visible

	function render(reply) {
		var list = $('digup-results');
		list.replaceChildren();
		var hits = reply.hits || [];
		lastHits = hits;                  // the preview pane walks THIS list
		selected = -1;
		$('digup-detail').hidden = true;  // a new answer is a new context
		closeLightbox();
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

	/* ── preview pane ─────────────────────────────────────────────────────────────
	 * Every media URL here is core's own surface, fetched with the browser's own
	 * session: previews via core/preview (bigger x/y), streams via the Files webdav
	 * path (/remote.php/dav/files/<user>/<home-relative path> — the same URL the
	 * Files app's own player streams). Videos start at the engine's segment loc,
	 * which is the moment the search hit was found. */

	function webdavSrc(hit) {
		return OC.webroot + '/remote.php/dav/files/' + encodeURIComponent(OC.currentUser)
			+ '/' + hit.viewPath.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/');
	}

	function bigPreview(hit) {
		var u = new URL(hit.previewUrl, window.location.href);
		u.searchParams.set('x', '1920');
		u.searchParams.set('y', '1920');
		return u.href;
	}

	/* Core answers 404 when it cannot generate a preview that large (instance
	 * preview_max limits; measured 2026-11-08 on the pilot: a 1920 request 404s
	 * where the 512 tile preview exists). Fall back to the tile's own preview
	 * size — the tile URL is already proven for this file by the grid render. */
	function attachPreview(img, hit, enlarge) {
		img.src = bigPreview(hit);
		img.addEventListener('error', function fallback() {
			if (img.src.indexOf(hit.previewUrl) === -1) {
				img.src = hit.previewUrl;
			} else {
				img.removeEventListener('error', fallback);
				img.dispatchEvent(new Event('digup-preview-failed'));
			}
		});
		if (enlarge) {
			img.addEventListener('digup-preview-failed', function () {
				renderPanelNoPreview(hit);
			});
		}
	}

	function closeLightbox() {
		$('digup-lightbox').hidden = true;
		$('digup-lightbox-media').replaceChildren();
	}

	function openLightbox(hit) {
		var img = document.createElement('img');
		img.alt = '';
		attachPreview(img, hit, false);        // same 1920→tile-size fallback
		$('digup-lightbox-media').replaceChildren(img);
		$('digup-lightbox-open').href = hit.openUrl;
		$('digup-lightbox').hidden = false;
	}

	function renderPanelNoPreview(hit) {
		$('digup-detail-media').replaceChildren(kindLabel(hit.kind));
		$('digup-detail-meta').textContent = metaLine(hit, 'preview unavailable — open in Files');
	}

	function renderPanel(hit) {
		var box = $('digup-detail-media');
		box.replaceChildren();
		var media = null;
		var note = '';
		if (hit.kind === 'video' || hit.kind === 'audio') {
			media = document.createElement(hit.kind === 'video' ? 'video' : 'audio');
			media.controls = true;
			media.preload = 'metadata';
			if (hit.kind === 'video') {
				media.playsInline = true;
			}
			media.src = webdavSrc(hit);
			if (hit.loc !== null && hit.loc !== undefined) {
				note = 'starting at ' + Math.round(hit.loc) + 's';
				media.addEventListener('loadedmetadata', function () {
					try {
						media.currentTime = Math.max(0, hit.loc);   // the matched moment
						if (hit.kind === 'video') {
							media.muted = true;                     // autoplay policy
							media.play().catch(function () {});     // user hits play if blocked
						}
					} catch (e) { /* streams without seeking are fine — play from 0 */ }
				});
			}
		} else if (hit.kind === 'image' || hit.kind === 'screenshot' || hit.kind === 'pdf') {
			media = document.createElement('img');
			media.alt = '';
			media.loading = 'lazy';
			media.decoding = 'async';
			var enlargeable = hit.kind !== 'pdf';
			if (enlargeable) {
				media.classList.add('digup-enlargeable');
				media.addEventListener('click', function () { openLightbox(hit); });
			} else {
				note = 'first page — open in Files to read';
			}
			attachPreview(media, hit, enlargeable);
		}
		if (media) {
			if (media.tagName === 'VIDEO' || media.tagName === 'AUDIO') {
				media.addEventListener('error', function () {
					renderPanelNoPreview(hit);
				});
			}
			box.append(media);
		} else {
			box.append(kindLabel(hit.kind));
			note = 'open in Files to view';
		}
		$('digup-detail-name').textContent = hit.name;
		$('digup-detail-meta').textContent = metaLine(hit, note);
		$('digup-detail-open').href = hit.openUrl;
		$('digup-detail').hidden = false;
	}

	function metaLine(hit, note) {
		var bits = [hit.kind];
		if (hit.z !== null && hit.z !== undefined) {
			bits.push('z ' + hit.z.toFixed(2));
		}
		if (note) {
			bits.push(note);
		}
		return bits.join(' · ');
	}

	function selectHit(i) {
		if (!lastHits.length) {
			return;
		}
		var clamped = Math.max(0, Math.min(lastHits.length - 1, i));
		selected = clamped;
		renderPanel(lastHits[clamped]);
		var tiles = $('digup-results').children;
		for (var t = 0; t < tiles.length; t++) {
			tiles[t].classList.toggle('digup-selected', t === clamped);
		}
	}

	function checkedKinds() {
		return Array.prototype.slice.call(document.querySelectorAll('#digup-kinds input:checked'))
			.map(function (box) { return box.value; });
	}

	function search(query) {
		searchInFlight = true;
		setActivity();
		startProgressPolling();         // the wheel shows files_done / files while it spins
		var my = ++generation;          // newest query wins: stale replies are dropped
		fetch(searchUrl, {
			method: 'POST',
			headers: {
				'content-type': 'application/json',
				requesttoken: OC.requestToken
			},
			credentials: 'same-origin',
			body: JSON.stringify({ q: query, limit: Number($('digup-limit').value),
				kinds: checkedKinds() })
		})
			.then(function (answer) { return answer.json(); })
			.then(function (reply) {
				if (my !== generation) {
					return;
				}
				searchInFlight = false;
				stopProgressPolling();
				setActivity();
				render(reply);
			})
			.catch(function (error) {
				if (my !== generation) {
					return;
				}
				searchInFlight = false;
				stopProgressPolling();
				setActivity();
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
	function syncAllPressed() {
		var on = checkedKinds().length === KINDS.length;
		var all = $('digup-kinds-all');
		all.setAttribute('aria-pressed', String(on));
		all.title = on ? 'All media types on — click to clear them all' : 'Turn all media types on';
	}
	function research() {
		var query = $('digup-q').value.trim();
		if (query.length >= 2) {
			search(query);
		}
	}
	document.getElementById('digup-kinds').addEventListener('change', function () {
		syncAllPressed();
		research();
	});
	$('digup-kinds-all').addEventListener('click', function () {
		var on = checkedKinds().length !== KINDS.length;   // all on → clear; else fill
		Array.prototype.slice.call(document.querySelectorAll('#digup-kinds input'))
			.forEach(function (box) { box.checked = on; });
		syncAllPressed();
		research();
	});
	/* Plain click on a tile opens the preview pane on that hit; Ctrl/⌘-click keeps
	 * the tile's own meaning (jump to the file in Files, new tab). */
	$('digup-results').addEventListener('click', function (event) {
		var link = event.target.closest('a.digup-open');
		if (!link || event.ctrlKey || event.metaKey) {
			return;
		}
		event.preventDefault();
		selectHit(Array.prototype.indexOf.call($('digup-results').children, link.closest('li')));
	});
	$('digup-detail-prev').onclick = function () { selectHit(selected - 1); };
	$('digup-detail-next').onclick = function () { selectHit(selected + 1); };
	$('digup-detail-close').onclick = function () {
		$('digup-detail').hidden = true;
		closeLightbox();
	};
	$('digup-lightbox-close').onclick = closeLightbox;
	$('digup-lightbox').addEventListener('click', function (event) {
		if (event.target === $('digup-lightbox')) {
			closeLightbox();
		}
	});
	document.addEventListener('keydown', function (event) {
		if (event.target.matches('input, select, textarea') || event.target.isContentEditable) {
			return;                                          // typing is typing
		}
		if (!$('digup-lightbox').hidden) {
			if (event.key === 'Escape') {
				closeLightbox();
			}
			return;
		}
		if ($('digup-detail').hidden) {
			return;
		}
		if (event.key === 'ArrowRight') {
			event.preventDefault();
			selectHit(selected + 1);
		} else if (event.key === 'ArrowLeft') {
			event.preventDefault();
			selectHit(selected - 1);
		} else if (event.key === 'Escape') {
			$('digup-detail').hidden = true;
		}
	});
	$('digup-q').focus();
})();
