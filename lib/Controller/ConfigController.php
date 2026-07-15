<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCA\Absence\Service\SessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ConfigService $config,
		private PersonalDefaultsService $personalDefaults,
		private SessionService $sessionService,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/** Who-am-I payload for the SPA. */
	#[NoAdminRequired]
	public function session(): DataResponse {
		return $this->handle(fn () => $this->sessionService->getSessionInfo());
	}

	/** Read the current user's resolved personal settings (detected + overrides). */
	#[NoAdminRequired]
	public function personal(): DataResponse {
		return $this->handle(fn () => $this->personalDefaults->resolve((string)$this->userId));
	}

	/**
	 * Save the current user's personal overrides (working weekdays, holiday
	 * country/region). Empty values clear an override and fall back to detection.
	 *
	 * @param array<string,mixed> $values
	 */
	#[NoAdminRequired]
	public function updatePersonal(array $values): DataResponse {
		return $this->handle(function () use ($values) {
			$uid = (string)$this->userId;
			$allowed = array_keys($this->config->getPersonalConfig($uid));
			foreach ($values as $key => $value) {
				if (in_array($key, $allowed, true)) {
					$this->config->setPersonalValue($uid, $key, (string)$value);
				}
			}
			return $this->personalDefaults->resolve($uid);
		});
	}

	/** Read the admin configuration (admin only). */
	public function admin(): DataResponse {
		return $this->handle(fn () => $this->config->getAdminConfig());
	}

	/**
	 * Update admin configuration (admin only).
	 *
	 * @param array<string,mixed> $values
	 */
	public function updateAdmin(array $values): DataResponse {
		return $this->handle(function () use ($values) {
			$allowed = array_keys($this->config->getAdminConfig());
			$changed = [];
			foreach ($values as $key => $value) {
				if (in_array($key, $allowed, true)) {
					$this->config->setAdminValue($key, $value);
					$changed[] = $key;
				}
			}
			$this->logger->info('Absence action: admin_config_updated', [
				'app' => 'absence',
				'action' => 'admin_config_updated',
				'actor' => $this->userId,
				'changedKeys' => $changed,
			]);
			return $this->config->getAdminConfig();
		});
	}
}
