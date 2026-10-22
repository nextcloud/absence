<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCA\Absence\Service\SessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class ConfigController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ConfigService $config,
		private PersonalDefaultsService $personalDefaults,
		private EmployeeService $employees,
		private SessionService $sessionService,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/** Who-am-I payload for the SPA. */
	#[NoAdminRequired]
	public function session(): DataResponse {
		return $this->handle(fn () => $this->sessionService->getSessionInfo());
	}

	/** The current user's working weekdays and region code for the prefill. */
	#[NoAdminRequired]
	public function personal(): DataResponse {
		return $this->handle(fn () => $this->personalDefaults->resolve((string)$this->userId));
	}

	/**
	 * Save the current user's holiday region code (country and region). The
	 * change goes to their history.
	 *
	 * @param array<string,mixed> $values
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function updatePersonal(array $values): DataResponse {
		return $this->handle(function () use ($values) {
			$uid = (string)$this->userId;
			$before = $this->config->getPersonalConfig($uid);
			foreach ($values as $key => $value) {
				if (array_key_exists($key, $before)) {
					$this->config->setPersonalValue($uid, $key, (string)$value);
				}
			}
			$code = static fn (array $c): string => ConfigService::regionCode($c[ConfigLexicon::KEY_HOLIDAY_COUNTRY], $c[ConfigLexicon::KEY_HOLIDAY_REGION]);
			$this->employees->recordRegionChange($uid, $code($before), $code($this->config->getPersonalConfig($uid)), $uid);
			return $this->personalDefaults->resolve($uid);
		});
	}
}
