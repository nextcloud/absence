<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\ValidationException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * The in-app settings page (§12): HR and admins read and change the app
 * settings, only admins change the HR group.
 */
class SettingsService {
	/** Names listed per side of an employees-group change preview */
	private const PREVIEW_NAMES = 3;

	public function __construct(
		private ConfigService $config,
		private PermissionService $permission,
		private HolidayService $holidays,
		private EmployeeDirectory $employees,
		private IGroupManager $groupManager,
		private CalendarService $calendar,
		private IUserManager $userManager,
		private IL10N $l,
		private LoggerInterface $logger,
		private IEventDispatcher $dispatcher,
	) {
	}

	/**
	 * @return array{values: array<string,mixed>, groups: list<array{id: string, displayName: string}>, employeesCount: ?int}
	 * @throws ForbiddenException
	 */
	public function get(string $actorUid): array {
		$this->permission->assertCanManageSettings($actorUid);
		$values = $this->config->getAdminConfig();
		$employeesGroup = $this->config->getEmployeesGroup();
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()];
		}
		usort($groups, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return [
			'values' => $values,
			'groups' => $groups,
			// Counted for a configured group only: "everyone" would walk the whole user backend.
			'employeesCount' => $employeesGroup !== '' ? count($this->employees->listForEmployeesGroup($employeesGroup)) : null,
		];
	}

	/**
	 * @param array<string,mixed> $values setting key => new value
	 * @return array{values: array<string,mixed>, groups: list<array{id: string, displayName: string}>, employeesCount: ?int}
	 * @throws ForbiddenException
	 * @throws ValidationException
	 */
	public function update(string $actorUid, array $values): array {
		$this->permission->assertCanManageSettings($actorUid);
		// HR must not be able to hand the HR role to someone else or lock itself in.
		if (array_key_exists(ConfigLexicon::KEY_HR_GROUP, $values)
			&& $values[ConfigLexicon::KEY_HR_GROUP] !== $this->config->getHrGroup()
			&& !$this->permission->isAdmin($actorUid)) {
			throw new ForbiddenException($this->l->t('Only admins can change the HR group.'));
		}
		$normalized = $this->config->normalizeAdminValues($values);
		$everyoneEditsHolidays = $normalized[ConfigLexicon::KEY_HOLIDAYS_EVERYONE] ?? null;
		unset($normalized[ConfigLexicon::KEY_HOLIDAYS_EVERYONE]);
		$this->config->setAdminValues($normalized);
		// The team calendar follows who it is for (§10)
		if (array_intersect(array_keys($normalized), [ConfigLexicon::KEY_HR_GROUP, ConfigLexicon::KEY_EMPLOYEES_GROUP, ConfigLexicon::KEY_CALDAV_SHARED]) !== []) {
			$this->calendar->shareTeamCalendar();
		}
		if (is_bool($everyoneEditsHolidays)) {
			// Through HolidayService, so the holiday history shows who switched it (§7.2).
			$this->holidays->setEveryoneCanEdit($everyoneEditsHolidays, $actorUid);
		}
		foreach ($values as $key => $value) {
			$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
				'Absence setting %s set to %s by %s', [(string)$key, json_encode($value), $actorUid],
			));
		}
		$this->logger->info('Absence action: admin_config_updated', [
			'app' => ConfigService::APP_ID,
			'action' => 'admin_config_updated',
			'actor' => $actorUid,
			'changedKeys' => array_keys($values),
		]);
		return $this->get($actorUid);
	}

	/**
	 * Creates the HR or the employees group under its usual name, or takes the
	 * one that already has it, and makes it the setting (§12). Admins only:
	 * creating groups is theirs.
	 *
	 * @return array{values: array<string,mixed>, groups: list<array{id: string, displayName: string}>, employeesCount: ?int}
	 * @throws ForbiddenException
	 * @throws ValidationException
	 */
	public function createGroup(string $actorUid, string $kind): array {
		if (!$this->permission->isAdmin($actorUid)) {
			throw new ForbiddenException($this->l->t('Only admins can create groups.'));
		}
		[$gid, $displayName, $key] = match ($kind) {
			'hr' => ['hr', $this->l->t('HR'), ConfigLexicon::KEY_HR_GROUP],
			'employees' => ['employees', $this->l->t('Employees'), ConfigLexicon::KEY_EMPLOYEES_GROUP],
			default => throw new ValidationException($this->l->t('Unknown group: %s', [$kind])),
		};
		if (!$this->groupManager->groupExists($gid)) {
			$group = $this->groupManager->createGroup($gid);
			if ($group === null) {
				throw new ValidationException($this->l->t('The group could not be created.'));
			}
			$group->setDisplayName($displayName);
			$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent('Absence group %s created by %s', [$gid, $actorUid]));
		}
		return $this->update($actorUid, [$key => $gid]);
	}

	/**
	 * Who would stop and who would start counting as an employee if the
	 * employees group became $group.
	 *
	 * @return array{leaving: array{count: int, names: string[]}, joining: array{count: int, names: string[]}}
	 * @throws ForbiddenException
	 * @throws ValidationException
	 */
	public function previewEmployeesGroup(string $actorUid, string $group): array {
		$this->permission->assertCanManageSettings($actorUid);
		if ($group === '' || !$this->groupManager->groupExists($group)) {
			throw new ValidationException($this->l->t('The chosen employees group does not exist.'));
		}
		$current = $this->employees->listAll();
		$next = $this->employees->listForEmployeesGroup($group);
		return [
			'leaving' => $this->summarize(array_diff($current, $next)),
			'joining' => $this->summarize(array_diff($next, $current)),
		];
	}

	/**
	 * @param string[] $uids
	 * @return array{count: int, names: string[]}
	 */
	private function summarize(array $uids): array {
		sort($uids);
		$names = array_map(
			fn (string $uid): string => $this->userManager->getDisplayName($uid) ?? $uid,
			array_slice($uids, 0, self::PREVIEW_NAMES),
		);
		return ['count' => count($uids), 'names' => $names];
	}
}
