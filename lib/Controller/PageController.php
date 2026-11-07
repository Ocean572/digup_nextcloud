<?php

declare(strict_types=1);

namespace OCA\DigUp\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * The DigUp page (route digup.page.index, the top-bar nav entry): the Files-style
 * search surface inside Nextcloud's own chrome. The page carries only same-origin
 * routes for its JS to call — the DigUp server URL and its token never reach the
 * browser (stage-12 §11.1): the browser talks to Nextcloud, with its own session,
 * and Nextcloud talks to the server server-to-server.
 */
class PageController extends Controller {
	private IURLGenerator $url;

	public function __construct(string $appName, IRequest $request, IURLGenerator $url) {
		parent::__construct($appName, $request);
		$this->url = $url;
	}

	/**
	 * The Files-style search page (nav entry).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse {
		Util::addScript('digup', 'app');     // js/app.js
		Util::addStyle('digup', 'app');      // css/app.css
		$response = new TemplateResponse('digup', 'main', [
			// linkTo() with the front-controller path: core's link builders that take
			// route names resolve only in the request's own scope, so the app composes
			// the same path core's router registers for its routes (works with and
			// without pretty URLs).
			'searchUrl' => $this->url->linkTo('', 'index.php/apps/digup/api/v1/search'),
		]);
		// Previews come from this same origin (core's preview.php) — keep the CSP plain.
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedImageDomain('self');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
