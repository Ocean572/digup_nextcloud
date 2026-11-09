<?php

declare(strict_types=1);

namespace OCA\DigUp\Service;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * The app's whole job, in one class (docs/implementation/stage-12-integrations.md §11.1):
 *
 *  1. ask the DigUp server one question (server-to-server, bearer read token — the token
 *     never leaves this process, let alone a browser);
 *  2. turn every hit's path into a NEXTCLOUD file the asking user can see — the engine's
 *     paths live under a configured root prefix, in ONE OF TWO LAYOUTS (the `mapping_mode`
 *     setting, §11.7): `data` — the raw data dir mounted read-only on the server, rows
 *     shaped `<prefix>/<owner>/files/<rel>`; `webdav` — per-user WebDAV (davfs2) mounts on
 *     the server, rows shaped `<prefix>/<owner>/<rel>`, each mount exposing exactly one
 *     user's OWN view (own files + everything shared to them). Own files resolve in the
 *     asking user's own view root in both modes; foreign files are kept ONLY when an
 *     incoming user/group share covers them (the share's target path is the user's own
 *     view path — the file opens exactly where the share put it). Anything outside this
 *     instance's prefix, anything the user
 *     cannot access, anything no longer on disk: dropped, silently and completely.
 *  3. hand the browser Nextcloud's own surfaces: file ids, the core preview URL (the
 *     browser fetches the preview with its own session — no DigUp token in any URL), and
 *     the Files-app open link.
 *
 * Read-only, end to end: Node lookups, the share manager's own item list, the preview
 * API. Nothing in this file writes: no Filesystem calls, no DB, no app tables (config
 * lives in appconfig, the standard mechanism every Nextcloud app uses).
 */
class DigupService {
	private IClientService $http;
	private IConfig $config;
	private IRootFolder $roots;
	private IShareManager $shares;
	private IGroupManager $groups;
	private IUserManager $users;
	private IURLGenerator $url;

	/** per-request caches: user view roots and each share owner's resolved roots */
	private array $rootCache = [];
	private array $shareCache = [];

	public function __construct(IClientService $http, IConfig $config, IRootFolder $roots,
		IShareManager $shares, IGroupManager $groups, IUserManager $users, IURLGenerator $url) {
		$this->http = $http;
		$this->config = $config;
		$this->roots = $roots;
		$this->shares = $shares;
		$this->groups = $groups;
		$this->users = $users;
		$this->url = $url;
	}

	/* ── settings (appconfig rows only — no tables, no schema) ─────────────────────── */

	public function getSettings(): array {
		return [
			'base_url' => $this->config->getAppValue('digup', 'base_url', ''),
			'root_prefix' => $this->config->getAppValue('digup', 'root_prefix', '/media/photos'),
			'mapping_mode' => $this->config->getAppValue('digup', 'mapping_mode', 'data'),
			'read_token_set' => $this->config->getAppValue('digup', 'read_token', '') !== '',
			'verify_tls' => $this->config->getAppValue('digup', 'verify_tls', 'yes') === 'yes',
		];
	}

	public function saveSettings(string $baseUrl, string $rootPrefix, string $readToken, bool $verifyTls,
		string $mappingMode = 'data'): void {
		$this->config->setAppValue('digup', 'base_url', rtrim($baseUrl, '/'));
		$this->config->setAppValue('digup', 'root_prefix', '/' . trim($rootPrefix, '/'));
		// The layout is a closed choice: anything else lands on 'data' (the default).
		$this->config->setAppValue('digup', 'mapping_mode', $mappingMode === 'webdav' ? 'webdav' : 'data');
		if ($readToken !== '') {
			$this->config->setAppValue('digup', 'read_token', $readToken);
		}
		$this->config->setAppValue('digup', 'verify_tls', $verifyTls ? 'yes' : 'no');
	}

	/** The admin settings "Test connection" probe: the server's open health endpoint. */
	public function probe(?string $baseUrl = null): array {
		$base = rtrim($baseUrl ?? $this->getSettings()['base_url'], '/');
		if ($base === '') {
			return ['ok' => false, 'detail' => 'No server URL is set yet.'];
		}
		try {
			$client = $this->http->newClient(['timeout' => 15, 'verify' => $this->getSettings()['verify_tls']]);
			$answer = $client->get($base . '/api/v1/health', $this->ssrfOptions());
			$status = $answer->getStatusCode();
			$body = json_decode((string)$answer->getBody(), true) ?: [];
			if ($status !== 200) {
				return ['ok' => false, 'detail' => "The server answered $status."];
			}
			$live = $body['live'] ?? [];
			$reply = [
				'ok' => true,
				'files' => $live['files'] ?? null,
				'searchable' => $live['searchable'] ?? null,
				'vectors' => $live['vectors'] ?? null,
				'build_tag' => $body['engine']['build_tag'] ?? null,
			];
			// 0 files under the roots is the mount-down signature (WebDAV shape, §11.7):
			// the engine is healthy, it just sees an empty tree. Name the fix, measured.
			if (isset($reply['files']) && (int)$reply['files'] === 0) {
				$reply['hint'] = 'The server sees 0 files under its roots: on a WebDAV '
					. 'deployment the davfs2 mounts are down — bring up the dav service '
					. '(`docker compose --profile dav up -d` in the server stack; mounts '
					. 'propagate live, no server restart), or, on a host-mount deployment, '
					. 'run deploy/nextcloud-webdav.sh and restart the server container. If '
					. 'the mounts are up, the roots point one level short of them — '
					. 'DIGUP_SERVER_ROOTS lists one entry per mounted user.';
			}
			return $reply;
		} catch (\Throwable $e) {
			// The message the operator needs is WHY: private-range block (SSRF firewall),
			// DNS, TLS — Nextcloud's own firewall words are the useful ones, and for the
			// firewall case the fix to name (measured, §11.5): config.php allow_local_servers.
			$detail = $e->getMessage();
			if (str_contains($detail, 'local access rules')) {
				$detail = 'Nextcloud blocks private addresses (SSRF guard). The server URL is '
					. 'admin-configured by design: set allow_local_servers => true in config.php '
					. 'to let this instance reach a server on your LAN. (' . $detail . ')';
			}
			return ['ok' => false, 'detail' => $detail];
		}
	}

	/**
	 * Server facts for the admin settings page (stage 13): the counts of the index this
	 * app's token answers — with named indexes (docs/API.md) the app's token is bound to
	 * the application's OWN index, so these are the bridge's own numbers. The call rides
	 * the same server-to-server path as the search: the token never reaches the browser,
	 * every number is the server's verbatim.
	 */
	public function serverStatus(): array {
		$settings = $this->getSettings();
		$base = rtrim($settings['base_url'], '/');
		$token = $this->config->getAppValue('digup', 'read_token', '');
		if ($base === '' || $token === '') {
			return ['ok' => false, 'detail' => 'Set the server URL and its access key first.'];
		}
		try {
			$client = $this->http->newClient(['timeout' => 15, 'verify' => $settings['verify_tls']]);
			$answer = $client->get($base . '/api/v1/status', [
				'headers' => ['authorization' => 'Bearer ' . $token],
			] + $this->ssrfOptions());
			$status = $answer->getStatusCode();
			$body = json_decode((string)$answer->getBody(), true) ?: [];
			if ($status !== 200) {
				return ['ok' => false, 'detail' => "The server answered $status."];
			}
			return [
				'ok' => true,
				'target' => $body['target'] ?? 'media',
				'files' => $body['files'] ?? null,
				'searchable' => $body['searchable'] ?? null,
				'roots' => $body['roots'] ?? [],
			];
		} catch (\Throwable $e) {
			return ['ok' => false, 'detail' => $this->failureDetail($e)];
		}
	}

	/**
	 * "Index now" for THIS application's index — the application-side half of
	 * "Nextcloud tells DigUp to build the index" (stage 13). An application token may
	 * queue a crawl of its own target only (the server enforces the binding), so this
	 * call cannot touch any other index. The server's own answers pass through: 202
	 * with the job, 409 with the busy words, 403 with the server's reason.
	 */
	public function startIndex(): array {
		$settings = $this->getSettings();
		$base = rtrim($settings['base_url'], '/');
		$token = $this->config->getAppValue('digup', 'read_token', '');
		if ($base === '' || $token === '') {
			return ['ok' => false, 'detail' => 'Set the server URL and its access key first.'];
		}
		try {
			$client = $this->http->newClient(['timeout' => 15, 'verify' => $settings['verify_tls']]);
			$answer = $client->post($base . '/api/v1/index', [
				'headers' => ['authorization' => 'Bearer ' . $token],
				'json' => new \stdClass(),
			] + $this->ssrfOptions());
			$status = $answer->getStatusCode();
			$body = json_decode((string)$answer->getBody(), true) ?: [];
			if ($status === 202) {
				return ['ok' => true, 'job' => $body['job'] ?? null];
			}
			return ['ok' => false, 'detail' => ($body['detail'] ?? "The server answered $status.")];
		} catch (\Throwable $e) {
			return ['ok' => false, 'detail' => $this->failureDetail($e)];
		}
	}

	/**
	 * The server's words out of a failed request. Guzzle raises on 4xx/5xx and embeds
	 * the raw body in its message; the settings line wants the server's `detail`
	 * string alone ("an index job is already running…", "…need the admin token") —
	 * verbatim, in the server's voice. Anything without one (DNS, TLS, timeout) shows
	 * the transport message as-is.
	 */
	private function failureDetail(\Throwable $e): string {
		$server = null;
		if ($e instanceof \GuzzleHttp\Exception\BadResponseException) {
			$decoded = json_decode((string)$e->getResponse()->getBody(), true);
			if (is_array($decoded) && isset($decoded['detail']) && is_string($decoded['detail'])) {
				$server = $decoded['detail'];
			}
		}
		return $server ?? $e->getMessage();
	}

	/**
	 * GET /api/v1/progress — the indexing progress the page shows beside the activity
	 * spinner (§ UX ask, 2026-11-07: users watching a multi-hour run want files_done /
	 * files, straight from the server's own job records — the app invents no numbers).
	 * /status answers the live index counts; /jobs answers the running/last job. The
	 * answer is cached 5 s per FPM worker (apcu), so a polling page is a few requests
	 * a minute, not a flood. Any failure returns ok:false with the server's words.
	 */
	public function indexProgress(): array {
		$cacheKey = 'digup_index_progress';
		if (\function_exists('apcu_fetch')) {
			$hit = false;
			$cached = apcu_fetch($cacheKey, $hit);
			if ($hit) {
				return $cached;
			}
		}
		$settings = $this->getSettings();
		$base = rtrim($settings['base_url'], '/');
		$token = $this->config->getAppValue('digup', 'read_token', '');
		if ($base === '' || $token === '') {
			return ['ok' => false, 'detail' => 'not configured'];
		}
		try {
			$client = $this->http->newClient(['timeout' => 8, 'verify' => $settings['verify_tls']]);
			$auth = ['headers' => ['authorization' => 'Bearer ' . $token]] + $this->ssrfOptions();
			$status = json_decode((string)$client->get($base . '/api/v1/status', $auth)->getBody(), true) ?: [];
			$jobs = json_decode((string)$client->get($base . '/api/v1/jobs', $auth)->getBody(), true) ?: [];
			$list = $jobs['jobs'] ?? [];
			$running = null;
			foreach ($list as $job) {
				if (($job['state'] ?? '') === 'running') {
					$running = $job;
				}
			}
			$last = $running ?? ($list !== [] ? $list[\count($list) - 1] : null);
			$reply = [
				'ok' => true,
				'state' => $running !== null ? 'running' : ($last['state'] ?? null),
				'files_done' => $last['files_done'] ?? null,
				'files' => $last['files'] ?? null,
				'index_files' => $status['files'] ?? null,
				'searchable' => $status['searchable'] ?? null,
			];
			if (\function_exists('apcu_store')) {
				apcu_store($cacheKey, $reply, 5);
			}
			return $reply;
		} catch (\Throwable $e) {
			return ['ok' => false, 'detail' => $e->getMessage()];
		}
	}

	/**
	 * Requests to the admin-configured server URL carry core's sanctioned local-address
	 * flag. Measured on 28.0.14: core's local-address check consults it, and an operator
	 * who puts the DigUp server on a private address also needs `allow_local_servers`
	 * (true) in config.php — on this core version the per-request flag alone is not
	 * enough for app requests. The probe reports both words: the flag rides core's public
	 * mechanism, the config knob is the documented deployment shape (README, §11.5).
	 */
	private function ssrfOptions(): array {
		return ['nextcloud' => ['allow_local_address' => true]];
	}

	/* ── the search: server answer → user-visible files ────────────────────────────── */

	/* ── the search: server answer → user-visible files ────────────────────────────── */

	public function search(string $query, int $displayLimit, string $userId, array $kinds = []): array {
		$settings = $this->getSettings();
		$base = rtrim($settings['base_url'], '/');
		$token = $this->config->getAppValue('digup', 'read_token', '');
		if ($base === '' || $token === '') {
			return ['hits' => [], 'strong' => 0, 'meaning' => true, 'ms' => [],
				'warning' => 'DigUp Search is not configured yet — set the server URL and its read token in Administration settings.'];
		}
		// Ask the server for headroom: filtering drops hits the user cannot see, so the
		// page-size the user picked is filled from a deeper answer (the engine caps at 100).
		$askLimit = min(100, max($displayLimit, $displayLimit * 3));
		try {
			$client = $this->http->newClient(['timeout' => 25, 'verify' => $settings['verify_tls']]);
			$answer = $client->post($base . '/api/v1/search', [
				'headers' => [
					'authorization' => 'Bearer ' . $token,
					'content-type' => 'application/json',
				],
				// The kind filter rides the engine's own SearchRequest: the list is
				// whitelisted to its Kind literals (anything else would be a 422 from
				// the server); all six = every kind, an empty list = the engine's
				// answer over no kinds — the standalone UI's semantics, verbatim.
				'json' => ['q' => $query, 'limit' => $askLimit,
					'kinds' => array_values(array_intersect(
						array_map('strval', $kinds),
						['screenshot', 'image', 'pdf', 'doc', 'audio', 'video']))],
			] + $this->ssrfOptions());
		} catch (\Throwable $e) {
			return ['hits' => [], 'strong' => 0, 'meaning' => true, 'ms' => [],
				'warning' => 'The DigUp server refused the search: ' . $e->getMessage()];
		}
		$status = $answer->getStatusCode();
		$body = json_decode((string)$answer->getBody(), true) ?: [];
		if ($status !== 200) {
			return ['hits' => [], 'strong' => 0, 'meaning' => true, 'ms' => [],
				'warning' => 'The DigUp server answered ' . $status
					. ($body['detail'] ?? null ? ': ' . $body['detail'] : '')];
		}

		$engineStrong = (int)($body['strong'] ?? 0);
		$hits = [];
		$seenFileIds = [];
		foreach ($body['hits'] ?? [] as $position => $hit) {
			$mapped = $this->map($hit, $userId, $settings['root_prefix'], $settings['mapping_mode']);
			if ($mapped === null) {
				continue;                       // out of this instance's prefix, gone from
			}                                   // disk, shared with nobody here: dropped
			$id = (int)$mapped['fileId'];
			if (isset($seenFileIds[$id])) {
				continue;                       // a file shared twice answers once (best z)
			}
			$seenFileIds[$id] = true;
			$hits[] = [
				'fileId' => $id,
				'viewPath' => $mapped['viewPath'],
				'name' => $mapped['name'],
				'kind' => $hit['kind'] ?? 'file',
				'z' => isset($hit['z']) ? round((float)$hit['z'], 2) : null,
				'loc' => $hit['loc'] ?? null,
				'size' => $hit['size'] ?? null,
				'modified' => $hit['modified'] ?? null,
				'strong' => $position < $engineStrong,        // the engine sorts strong-first
				// Core's own surfaces, built the way core's routes.php declares them
				// (NC 28: preview by file id → /core/preview; the Files app's index →
				// /apps/files). The browser fetches previews with its own session, so the
				// DigUp token never appears in any URL the browser sees (§11.1).
				'previewUrl' => $this->url->linkTo('', 'core/preview', [
					'fileId' => $id, 'x' => 512, 'y' => 384, 'a' => 1]),
				'openUrl' => $this->url->linkTo('', 'apps/files/index.php', [
					'dir' => '/' . trim(dirname($mapped['viewPath']), '/'), 'fileid' => $id]),
			];
			if (count($hits) >= $displayLimit) {
				break;
			}
		}
		return [
			'hits' => $hits,
			'strong' => count(array_filter($hits, static fn (array $h): bool => $h['strong'])),
			'meaning' => (bool)($body['meaning'] ?? true),
			'ms' => $body['ms'] ?? [],
			'warning' => $body['warning'] ?? null,
			'asked' => $askLimit,
		];
	}

	/* ── path → file, with the ACL ─────────────────────────────────────────────────── */

	/**
	 * Map an engine hit to a file the asking user can access — or null.
	 *
	 * `data` mode: engine paths are `<root_prefix>/<owner>/files/<rel>` (the data dir as
	 * the server mounted it). Own files resolve in the user's own view root. Foreign files
	 * count only through incoming user/group shares — decided in the owner's view: the
	 * share's node, resolved in the owner's Files view, is a path in the SAME coordinate
	 * space as the engine's relPath, so coverage is a plain prefix test there; the asking
	 * user's view root then confirms access by answering for the shared node (it returns
	 * shared nodes at their mounted location and NOTHING for files the user cannot see —
	 * the same lookup the Files app's sharing panel uses, per request, per user). A share's
	 * stored target is never used as a path: IShare::getTarget() is what Nextcloud offered
	 * the recipient (the source path), not where the recipient's Files app mounted it.
	 *
	 * `webdav` mode: engine paths are `<root_prefix>/<owner>/<rel>` — each root folder is
	 * one user's WebDAV view mounted on the server (stage-12 §11.7), so relPath IS a path
	 * in the OWNER's Files view. Own files: resolved in the asking user's own view root.
	 * Foreign files: resolved in the OWNER's view root (the mount mirrors it 1:1), then
	 * confirmed by the asking user's own view answering for that file id — the user-view
	 * getById is the ACL boundary here too: it answers shared nodes at the location the
	 * recipient's mount shows (the WebDAV view is the Files view) and NOTHING for files
	 * the user cannot see. No share-list prefix test is needed in this mode: the mount
	 * itself already carries only what the owner sees, and the recipient's view decides.
	 */
	private function map(array $hit, string $userId, string $prefix, string $mode): ?array {
		$path = (string)($hit['path'] ?? '');
		if ($path === '' || !str_starts_with($path, $prefix . '/')) {
			return null;                                    // another instance's tree (§11.5)
		}
		$rest = explode('/', substr($path, strlen($prefix) + 1));
		if ($mode === 'webdav') {
			if (count($rest) < 2) {
				return null;                                // a mount root, not a file
			}
			$owner = $rest[0];
			$relPath = '/' . implode('/', array_slice($rest, 1));
			if ($owner === $userId) {
				$node = $this->node($this->viewRoot($userId), $relPath);
			} else {
				$ownerNode = $this->node($this->viewRoot($owner), $relPath);
				if ($ownerNode === null) {
					return null;                            // not in the owner's view: no ACL
				}
				$root = $this->viewRoot($userId);
				if ($root === null) {
					return null;
				}
				try {
					$nodes = $root->getById($ownerNode->getId());
				} catch (\Throwable $e) {
					return null;                            // the owner's storage errors: drop
				}
				if ($nodes === []) {
					return null;                            // not visible to this user: drop
				}
				$node = $nodes[0];
			}
			return $node === null ? null : [
				'fileId' => $node->getId(),
				'viewPath' => $this->viewPath($this->viewRoot($userId), $node),
				'name' => $node->getName(),
			];
		}

		if (count($rest) < 3 || $rest[1] !== 'files') {
			return null;                                    // not a file in anyone's library
		}
		$owner = $rest[0];
		$relPath = '/' . implode('/', array_slice($rest, 2));

		if ($owner === $userId) {
			$node = $this->node($this->viewRoot($userId), $relPath);
			return $node === null ? null : [
				'fileId' => $node->getId(),
				'viewPath' => $relPath,
				'name' => $node->getName(),
			];
		}

		foreach ($this->incomingShares($userId) as $share) {
			if ($share['owner'] !== $owner) {
				continue;
			}
			$source = rtrim($share['sourcePath'], '/');
			if ($relPath !== $source && !str_starts_with($relPath, $source . '/')) {
				continue;                                   // this share covers another file
			}
			$root = $this->viewRoot($userId);
			if ($root === null) {
				continue;
			}
			try {
				$nodes = $root->getById((int)$share['nodeId']);
			} catch (\Throwable $e) {
				continue;                                   // shared storage gone: drop
			}
			if ($nodes === []) {
				continue;                                   // not in this user's view: drop
			}
			$node = $nodes[0];
			return [
				'fileId' => $node->getId(),
				'viewPath' => $this->viewPath($root, $node),
				'name' => $node->getName(),
			];
		}
		return null;
	}

	/** A node's path inside a view root — the user's own Files coordinates. */
	private function viewPath($root, $node): string {
		$base = $root->getPath();
		$path = $node->getPath();
		if (str_starts_with($path, $base . '/')) {
			return '/' . substr($path, strlen($base) + 1);
		}
		return $path === $base ? '/' : $path;
	}

	private function viewRoot(string $userId) {
		if (!isset($this->rootCache[$userId])) {
			try {
				// The user's own home view root: paths inside are relative to the user's
				// Files root (shares, own files, external mounts — the Files app's view).
				$this->rootCache[$userId] = $this->roots->getUserFolder($userId);
			} catch (\Throwable $e) {
				return null;
			}
		}
		return $this->rootCache[$userId];
	}

	private function node($root, string $path) {
		if ($root === null) {
			return null;
		}
		try {
			return $root->get($path);
		} catch (NotFoundException $e) {
			return null;
		} catch (\Throwable $e) {
			return null;          // a storage that errors is not a file the user can see
		}
	}

	/**
	 * The asking user's incoming user + group shares, resolved ONCE per request: owner,
	 * target (the user's own view path), and the source path in the owner's view —
	 * resolved through the owner's view root, read-only.
	 *
	 * `getSharedWith($user, TYPE_USER)` is the recipient-scoped public API (shares sent
	 * TO this user). Group shares are stored against the GROUP id, so the user's own
	 * groups are looped (the same list the Files Sharing UI builds its "Incoming" tab
	 * from) — a group share is an incoming share for every member.
	 */
	private function incomingShares(string $userId): array {
		if (isset($this->shareCache[$userId])) {
			return $this->shareCache[$userId];
		}
		$recipients = [$userId];
		try {
			// getUserGroups() wants an IUser (its signature is the group list the Files
			// Sharing UI's "Incoming" tab builds from); a missing user answers [] anyway.
			$user = $this->users->get($userId);
			if ($user !== null) {
				foreach ($this->groups->getUserGroups($user) as $group) {
					$recipients[] = $group->getGID();
				}
			}
		} catch (\Throwable $e) {
			// group lookup failed: user shares alone are still a correct (narrower) boundary
		}
		$resolved = [];
		foreach ($recipients as $recipient) {
			$type = $recipient === $userId ? IShare::TYPE_USER : IShare::TYPE_GROUP;
			try {
				// (node = null, limit = -1: every incoming share, the shape the Sharing app
				// itself uses; a non-null node filters by file, a 0 limit answers nothing)
				$items = $this->shares->getSharedWith($recipient, $type, null, -1, 0);
			} catch (\Throwable $e) {
				continue;
			}
			foreach ($items as $item) {
				$owner = $item->getShareOwner();
				$sourcePath = $this->shareSourcePath($owner, (int)$item->getNodeId());
				if ($sourcePath === null) {
					continue;
				}
				$resolved[] = [
					'owner' => $owner,
					'nodeId' => (int)$item->getNodeId(),
					'target' => '/' . ltrim($item->getTarget(), '/'),
					'sourcePath' => $sourcePath,
				];
			}
		}
		$this->shareCache[$userId] = $resolved;
		return $resolved;
	}

	private function shareSourcePath(string $owner, int $nodeId): ?string {
		$root = $this->viewRoot($owner);
		if ($root === null) {
			return null;
		}
		try {
			$nodes = $root->getById($nodeId);
			if ($nodes === []) {
				return null;                                // deleted, or outside the owner's
			}                                               // view (a recycler case): no ACL
			// The owner's own Files-view path — the same coordinate space as a mapped
			// hit's relPath (Node::getRelativePath() is not part of the Node API; the
			// view path is the node's path minus the view root's path).
			return $this->viewPath($root, $nodes[0]);
		} catch (\Throwable $e) {
			return null;
		}
	}
}
