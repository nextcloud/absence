<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The in-app settings page (§12), for HR and admins. Role checks live in
 * {@see SettingsService}.
 */
class SettingsController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private SettingsService $settings,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function show(): DataResponse {
		return $this->handle(fn () => $this->settings->get((string)$this->userId));
	}

	/**
	 * @param array<string,mixed> $values setting key => new value
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(array $values): DataResponse {
		return $this->handle(fn () => $this->settings->update((string)$this->userId, $values));
	}

	/** Who leaves and who joins the employees if the employees group became $group. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function employeesPreview(string $group = ''): DataResponse {
		return $this->handle(fn () => $this->settings->previewEmployeesGroup((string)$this->userId, $group));
	}
}
