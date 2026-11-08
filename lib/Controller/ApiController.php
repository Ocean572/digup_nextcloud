<?php

declare(strict_types=1);

namespace OCA\DigUp\Controller;

use OCA\DigUp\Service\DigupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * The app's JSON surface (routes digup.api.search / digup.api.test / digup.api.save).
 *
 * Every request is the asking user's own (framework-authenticated session): the search
 * filters against THAT user's permissions inside DigupService. The admin endpoints
 * (test, save) check isAdmin on top — the settings page is an admin surface, and the
 * read token is written only by it.
 *
 * CSRF: these are state-changing routes, so the framework's CSRF middleware applies —
 * the app's JS sends the `requesttoken` header, exactly like every other Nextcloud
 * script; no route is CSRF-exempt.
 */
class ApiController extends Controller {
	private DigupService $service;
	private IGroupManager $groups;
	private string $userId;

	public function __construct(string $appName, IRequest $request, string $userId,
		DigupService $service, IGroupManager $groups) {
		parent::__construct($appName, $request);
		$this->userId = $userId;
		$this->service = $service;
		$this->groups = $groups;
	}

	/**
	 * POST /api/v1/search {q, limit} — answers for the asking user only.
	 *
	 * @NoAdminRequired
	 */
	public function search(string $q = '', int $limit = 20): JSONResponse {
		$limit = max(1, min(100, $limit));      // the engine's own cap
		return new JSONResponse($this->service->search(trim($q), $limit, $this->userId));
	}

	/**
	 * GET /api/v1/progress — indexing progress for the search page's activity area.
	 * Any logged-in user may read it (it is the server's own public status shape).
	 *
	 * @NoAdminRequired
	 */
	public function progress(): JSONResponse {
		return new JSONResponse($this->service->indexProgress());
	}

	/**
	 * POST /api/v1/test — the settings page's "Test connection" probe (admin only).
	 * A posted base_url is probed as typed (testing unsaved edits before saving);
	 * empty falls back to the stored one.
	 */
	public function test(string $base_url = ''): JSONResponse {
		if (!$this->groups->isAdmin($this->userId)) {
			return new JSONResponse(['ok' => false, 'detail' => 'Only administrators can test the server connection.'], 403);
		}
		$typed = trim($base_url);
		return new JSONResponse($this->service->probe($typed !== '' ? $typed : null));
	}

	/**
	 * GET /api/v1/status — the admin settings page's index facts (stage 13): the counts
	 * of the index THIS app's token answers — with the server's named indexes that is the
	 * bridge's own index, and the numbers are the server's verbatim.
	 */
	public function status(): JSONResponse {
		if (!$this->groups->isAdmin($this->userId)) {
			return new JSONResponse(['ok' => false, 'detail' => 'Only administrators can see the server status.'], 403);
		}
		return new JSONResponse($this->service->serverStatus());
	}

	/**
	 * POST /api/v1/index — "Index now" for this application's own index (stage 13).
	 * The server binds an application token to its own target, so this can queue the
	 * bridge's crawl and nothing else; 202/409/403 all pass through with the server's
	 * own words.
	 */
	public function indexnow(): JSONResponse {
		if (!$this->groups->isAdmin($this->userId)) {
			return new JSONResponse(['ok' => false, 'detail' => 'Only administrators can start an index job.'], 403);
		}
		return new JSONResponse($this->service->startIndex());
	}

	/** POST /settings (the admin settings form's save) — base_url / root_prefix / mapping_mode / read_token. */
	public function save(string $base_url = '', string $root_prefix = '', string $read_token = '',
		bool $verify_tls = true, string $mapping_mode = 'data'): JSONResponse {
		if (!$this->groups->isAdmin($this->userId)) {
			return new JSONResponse(['ok' => false, 'detail' => 'Only administrators can configure DigUp Search.'], 403);
		}
		$this->service->saveSettings($base_url, $root_prefix, $read_token, $verify_tls, $mapping_mode);
		$settings = $this->service->getSettings();
		return new JSONResponse(['ok' => true] + $settings);
	}
}
