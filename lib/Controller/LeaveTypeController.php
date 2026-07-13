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
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class LeaveTypeController extends Controller {
	use ApiControllerTrait;

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
	public function create(string $key, string $label, string $color = '#0082c9', string $icon = '🌴', bool $countsAgainstBalance = false, bool $requiresApproval = true, bool $requiresNote = false, bool $requiresReplacement = false, bool $employeeRequestable = true, int $sortOrder = 0): DataResponse {
		return $this->handle(function () use ($key, $label, $color, $icon, $countsAgainstBalance, $requiresApproval, $requiresNote, $requiresReplacement, $employeeRequestable, $sortOrder) {
			$this->permission->assertHr((string)$this->userId);
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
	public function update(int $id, ?string $label = null, ?string $color = null, ?string $icon = null, ?bool $countsAgainstBalance = null, ?bool $requiresApproval = null, ?bool $requiresNote = null, ?bool $requiresReplacement = null, ?bool $employeeRequestable = null, ?bool $enabled = null, ?int $sortOrder = null): DataResponse {
		return $this->handle(function () use ($id, $label, $color, $icon, $countsAgainstBalance, $requiresApproval, $requiresNote, $requiresReplacement, $employeeRequestable, $enabled, $sortOrder) {
			$this->permission->assertHr((string)$this->userId);
			try {
				$type = $this->mapper->find($id);
			} catch (DoesNotExistException) {
				throw new NotFoundException('Leave type not found');
			}
			if ($label !== null) {
				$type->setLabel($label);
			}
			if ($color !== null) {
				$type->setColor($color);
			}
			if ($icon !== null) {
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

	#[NoAdminRequired]
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
