<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Settings;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ConfigService;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;
use Psr\Log\LoggerInterface;

/**
 * Declarative admin settings form (§12) — the server renders it, no app
 * frontend code needed. Values are read and written through ConfigService
 * so its validation and the audit log apply to every change.
 *
 * Field defaults must match the config lexicon ({@see ConfigLexicon});
 * ConfigLexiconTest asserts they stay in sync.
 */
class AdminDeclarativeSettings implements IDeclarativeSettingsFormWithHandlers {
	public const FORM_ID = 'absence-admin';

	public function __construct(
		private ConfigService $config,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => self::FORM_ID,
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => ConfigService::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('Absence'),
			'description' => $this->l->t('Configure the vacation approval workflow.'),
			'fields' => [
				[
					'id' => ConfigLexicon::KEY_HR_GROUP,
					'title' => $this->l->t('HR group'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'hr',
					'default' => 'hr',
				],
				[
					'id' => ConfigLexicon::KEY_DEFAULT_ENTITLEMENT,
					'title' => $this->l->t('Default annual entitlement (days)'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 25.0,
				],
				[
					'id' => ConfigLexicon::KEY_ESCALATION_WINDOW,
					'title' => $this->l->t('Escalation window (working days)'),
					'description' => $this->l->t('Pending requests are escalated to HR after this many days without an answer.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 3,
				],
				[
					'id' => ConfigLexicon::KEY_REMINDER_LEAD,
					'title' => $this->l->t('Reminder lead time (days)'),
					'description' => $this->l->t('Managers are reminded of pending requests this many days before escalation.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 1,
				],
				[
					'id' => ConfigLexicon::KEY_CARRYOVER_POLICY,
					'title' => $this->l->t('Carry-over policy'),
					'type' => DeclarativeSettingsTypes::SELECT,
					'default' => ConfigService::CARRYOVER_CAPPED,
					'options' => [
						['name' => $this->l->t('No carry-over'), 'value' => ConfigService::CARRYOVER_NONE],
						['name' => $this->l->t('Capped'), 'value' => ConfigService::CARRYOVER_CAPPED],
						['name' => $this->l->t('Unlimited'), 'value' => ConfigService::CARRYOVER_UNLIMITED],
					],
				],
				[
					'id' => ConfigLexicon::KEY_CARRYOVER_CAP,
					'title' => $this->l->t('Carry-over cap (days)'),
					'description' => $this->l->t('Only applies to the "Capped" policy.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 5.0,
				],
				[
					'id' => ConfigLexicon::KEY_CARRYOVER_EXPIRY,
					'title' => $this->l->t('Carry-over expiry (MM-DD, optional)'),
					'description' => $this->l->t('Day in the new year when carried-over days expire. Leave empty to keep them all year.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '03-31',
					'default' => '',
				],
				[
					'id' => ConfigLexicon::KEY_MAX_CONCURRENT,
					'title' => $this->l->t('Max concurrent team absences'),
					'description' => $this->l->t('A coverage warning is shown above this number of overlapping absences.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 2,
				],
				[
					'id' => ConfigLexicon::KEY_CALDAV_PERSONAL,
					'title' => $this->l->t('Personal calendar'),
					'label' => $this->l->t('Write approved leave to each user\'s personal calendar'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::KEY_CALDAV_SHARED,
					'title' => $this->l->t('Shared team calendar'),
					'label' => $this->l->t('Write approved leave to a shared team calendar'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => ConfigLexicon::KEY_SHARED_VISIBILITY,
					'title' => $this->l->t('Shared calendar visibility'),
					'type' => DeclarativeSettingsTypes::RADIO,
					'default' => ConfigService::VISIBILITY_NEUTRAL,
					'options' => [
						['name' => $this->l->t('Show every absence as "Absent"'), 'value' => ConfigService::VISIBILITY_NEUTRAL],
						['name' => $this->l->t('Reveal the leave type'), 'value' => ConfigService::VISIBILITY_REVEAL],
					],
				],
			],
		];
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		$config = $this->config->getAdminConfig();
		if (!array_key_exists($fieldId, $config)) {
			throw new \InvalidArgumentException('Unknown setting: ' . $fieldId);
		}
		return $config[$fieldId];
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		$this->config->setAdminValue($fieldId, $value);
		$this->logger->info('Absence action: admin_config_updated', [
			'app' => ConfigService::APP_ID,
			'action' => 'admin_config_updated',
			'actor' => $user->getUID(),
			'changedKeys' => [$fieldId],
		]);
	}
}
