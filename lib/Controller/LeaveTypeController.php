<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class LeaveTypeController extends Controller {
	use ApiControllerTrait;

	// Must stay within the column lengths in Version1000Date20260710000000.
	private const MAX_KEY_LENGTH = 32;
	private const MAX_LABEL_LENGTH = 128;
	private const MAX_ICON_LENGTH = 16;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private LeaveTypeMapper $mapper,
		private PermissionService $permission,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(bool $onlyEnabled = false): DataResponse {
		return $this->handle(fn () => array_map(
			static fn ($t) => $t->jsonSerialize(),
			$this->mapper->findAll($onlyEnabled),
		));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(string $key, string $label, string $color = '#0082c9', string $icon = '🌴', bool $countsAgainstBalance = false, bool $requiresApproval = true, bool $requiresNote = false, bool $requiresReplacement = false, bool $employeeRequestable = true, int $sortOrder = 0): DataResponse {
		return $this->handle(function () use ($key, $label, $color, $icon, $countsAgainstBalance, $requiresApproval, $requiresNote, $requiresReplacement, $employeeRequestable, $sortOrder) {
			$this->permission->assertHr((string)$this->userId);
			$this->assertValidColor($color);
			$this->assertValidText('key', $key, self::MAX_KEY_LENGTH, required: true);
			$this->assertValidText('label', $label, self::MAX_LABEL_LENGTH, required: true);
			$this->assertValidText('icon', $icon, self::MAX_ICON_LENGTH, required: false);
			// The key is unique in the schema; check first so a duplicate is a clean
			// 422 instead of a database exception surfacing as a 500.
			foreach ($this->mapper->findAll() as $existing) {
				if ($existing->getKey() === $key) {
					throw new ValidationException('A leave type with this key already exists.');
				}
			}
			$type = new LeaveType();
			$type->setKey($key);
			$type->setLabel($label);
			$type->setColor($color);
			$type->setIcon($icon);
			$type->setCountsAgainstBalance($countsAgainstBalance);
			$type->setRequiresApproval($requiresApproval);
			$type->setRequiresNote($requiresNote);
			$type->setRequiresReplacement($requiresReplacement);
			$type->setEmployeeRequestable($employeeRequestable);
			$type->setEnabled(true);
			$type->setSortOrder($sortOrder);
			$type = $this->mapper->insert($type);
			$this->logger->info('Absence action: leave_type_created', ['app' => 'absence', 'action' => 'leave_type_created', 'actor' => $this->userId, 'typeId' => $type->getId(), 'key' => $type->getKey()]);
			return $type->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(int $id, ?string $label = null, ?string $color = null, ?string $icon = null, ?bool $countsAgainstBalance = null, ?bool $requiresApproval = null, ?bool $requiresNote = null, ?bool $requiresReplacement = null, ?bool $employeeRequestable = null, ?bool $enabled = null, ?int $sortOrder = null): DataResponse {
		return $this->handle(function () use ($id, $label, $color, $icon, $countsAgainstBalance, $requiresApproval, $requiresNote, $requiresReplacement, $employeeRequestable, $enabled, $sortOrder) {
			$this->permission->assertHr((string)$this->userId);
			try {
				$type = $this->mapper->find($id);
			} catch (DoesNotExistException) {
				throw new NotFoundException('Leave type not found');
			}
			if ($label !== null) {
				$this->assertValidText('label', $label, self::MAX_LABEL_LENGTH, required: true);
				$type->setLabel($label);
			}
			if ($color !== null) {
				$this->assertValidColor($color);
				$type->setColor($color);
			}
			if ($icon !== null) {
				$this->assertValidText('icon', $icon, self::MAX_ICON_LENGTH, required: false);
				$type->setIcon($icon);
			}
			if ($countsAgainstBalance !== null) {
				$type->setCountsAgainstBalance($countsAgainstBalance);
			}
			if ($requiresApproval !== null) {
				$type->setRequiresApproval($requiresApproval);
			}
			if ($requiresNote !== null) {
				$type->setRequiresNote($requiresNote);
			}
			if ($requiresReplacement !== null) {
				$type->setRequiresReplacement($requiresReplacement);
			}
			if ($employeeRequestable !== null) {
				$type->setEmployeeRequestable($employeeRequestable);
			}
			if ($enabled !== null) {
				$type->setEnabled($enabled);
			}
			if ($sortOrder !== null) {
				$type->setSortOrder($sortOrder);
			}
			$type = $this->mapper->update($type);
			$this->logger->info('Absence action: leave_type_updated', ['app' => 'absence', 'action' => 'leave_type_updated', 'actor' => $this->userId, 'typeId' => $type->getId(), 'key' => $type->getKey(), 'enabled' => $type->getEnabled()]);
			return $type->jsonSerialize();
		});
	}

	/**
	 * The color is rendered into CSS on the client (custom properties and a
	 * `color-mix(...)` background). Restrict it to a plain hex color so no other CSS
	 * tokens (e.g. an attacker-hosted `url(...)`) can be smuggled through.
	 *
	 * @throws ValidationException
	 */
	private function assertValidColor(string $color): void {
		if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
			throw new ValidationException('The color must be a hex value like #0082c9.');
		}
	}

	/**
	 * Reject text that is empty (when required) or longer than its database column,
	 * so an over-long value is a clean 422 rather than a database exception.
	 *
	 * @throws ValidationException
	 */
	private function assertValidText(string $field, string $value, int $maxLength, bool $required): void {
		if ($required && trim($value) === '') {
			throw new ValidationException("The $field cannot be empty.");
		}
		if (mb_strlen($value) > $maxLength) {
			throw new ValidationException("The $field is too long (max $maxLength characters).");
		}
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->permission->assertHr((string)$this->userId);
			try {
				$type = $this->mapper->find($id);
			} catch (DoesNotExistException) {
				throw new NotFoundException('Leave type not found');
			}
			// Soft-disable rather than hard delete to preserve historical requests (§3.2).
			$type->setEnabled(false);
			$type = $this->mapper->update($type);
			$this->logger->info('Absence action: leave_type_disabled', ['app' => 'absence', 'action' => 'leave_type_disabled', 'actor' => $this->userId, 'typeId' => $type->getId(), 'key' => $type->getKey()]);
			return $type->jsonSerialize();
		});
	}
}
