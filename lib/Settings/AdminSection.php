<?php

declare(strict_types=1);

namespace OCA\DigUp\Settings;

use OCP\IURLGenerator;
// Nextcloud 28's public section interface is IIconSection (its own docs point here; the
// plain section interface is not public API at this version).
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	private IURLGenerator $url;

	public function __construct(IURLGenerator $url) {
		$this->url = $url;
	}

	public function getIcon(): string {
		return $this->url->imagePath('digup', 'app.svg');
	}

	public function getName(): string {
		return 'DigUp Search';
	}

	public function getPriority(): int {
		return 100;
	}

	public function getId(): string {
		return 'digup';
	}
}
