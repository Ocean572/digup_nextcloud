<?php
/**
 * Routes.
 *
 * The app's URLs live under /apps/digup (Nextcloud's app prefix — the app's API
 * included, so the browser talks only to Nextcloud, with its own session: stage-12
 * §11.1). The template hands its JS the generated URL; nothing in the page names
 * the DigUp server. routes.php is the registry (the attribute #[] shapes on the
 * controllers are documentation — core registers these entries at this version),
 * and @NoAdminRequired docblocks guard the user-facing routes; api#test and
 * api#save stay admin-only (core's default for routes.php-era routes).
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'api#save', 'url' => '/settings', 'verb' => 'POST'],
		['name' => 'api#test', 'url' => '/api/v1/test', 'verb' => 'POST'],
		['name' => 'api#search', 'url' => '/api/v1/search', 'verb' => 'POST'],
	],
];
