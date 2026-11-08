<?php

declare(strict_types=1);

namespace OCA\DigUp\Settings;

use OCA\DigUp\Service\DigupService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Administration settings → DigUp Search: paste the server URL (+ its read token).
 * Values are appconfig rows (the standard mechanism every Nextcloud app uses — no
 * tables, no schema, no file writes). The token is write-only in the form: the form
 * reports only whether one is SET, and saving an empty token keeps the stored one.
 */
class Admin implements ISettings {
	private DigupService $service;
	private IURLGenerator $url;

	public function __construct(DigupService $service, IURLGenerator $url) {
		$this->service = $service;
		$this->url = $url;
	}

	public function getSection(): string {
		return 'digup';
	}

	public function getPriority(): int {
		return 10;
	}

	public function getForm(): TemplateResponse {
		Util::addScript('digup', 'settings');
		return new TemplateResponse('digup', 'settings', [
			'settings' => $this->service->getSettings(),
			'testUrl' => $this->url->linkTo('', 'index.php/apps/digup/api/v1/test'),
			'saveUrl' => $this->url->linkTo('', 'index.php/apps/digup/settings'),
			'statusUrl' => $this->url->linkTo('', 'index.php/apps/digup/api/v1/status'),
			'indexUrl' => $this->url->linkTo('', 'index.php/apps/digup/api/v1/index'),
		]);
	}
}
