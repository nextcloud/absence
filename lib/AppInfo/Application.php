<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\AppInfo;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Dashboard\AbsenceWidget;
use OCA\Absence\Listener\UserDeletedListener;
use OCA\Absence\Notification\Notifier;
use OCA\Absence\Settings\AdminDeclarativeSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'absence';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerConfigLexicon(ConfigLexicon::class);
		$context->registerDeclarativeSettings(AdminDeclarativeSettings::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerDashboardWidget(AbsenceWidget::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
