<?php

declare(strict_types=1);

namespace OCA\DigUp\AppInfo;

use OCA\DigUp\Settings\Admin;
use OCA\DigUp\Settings\AdminSection;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Settings\IManager as SettingsManager;

/**
 * DigUp Search — the Nextcloud half of docs/implementation/stage-12-integrations.md.
 *
 * What the app is: a thin, READ-ONLY client of a self-hosted DigUp server's published API
 * (`POST /api/v1/search`), plus the ACL boundary the server deliberately does not have
 * (§11.1): every answer is filtered against Nextcloud's own permissions (own files +
 * shares), mapped to Nextcloud file ids, and rendered through Nextcloud's own UI surfaces
 * (previews via the core preview API, opening in the Files app). The DigUp bearer token is
 * server-side config; it never reaches a browser.
 *
 * What the app never does: write files, create database tables, touch Nextcloud's schema,
 * or invent a number. Hits, z-scores, `strong`, `meaning` and `ms` are relayed verbatim
 * from the engine through the server.
 *
 * The bootable class's name and place are core's convention (OCA\<namespace>\AppInfo\
 * Application): only that class's register()/boot() ever run.
 */
class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('digup');
	}

	public function register(IRegistrationContext $context): void {
		// Plain constructor injection covers every service this app needs (core's
		// container autowires them); the app registers nothing special.
	}

	public function boot(IBootContext $context): void {
		// The admin settings panel (section + form) through core's settings manager —
		// the standard mechanism, appconfig rows only (no tables, §11.3). The info.xml
		// <settings> entries are NOT how this works at 28: the manager registers them
		// here, at boot.
		$manager = $context->getServerContainer()->get(SettingsManager::class);
		$manager->registerSection('admin', AdminSection::class);
		$manager->registerSetting('admin', Admin::class);
	}
}
