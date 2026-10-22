<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// SPA entry (hash-based routing on the client, so a single entry suffices)
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Requests
		['name' => 'request#index', 'url' => '/api/requests', 'verb' => 'GET'],
		['name' => 'request#show', 'url' => '/api/requests/{id}', 'verb' => 'GET'],
		['name' => 'request#create', 'url' => '/api/requests', 'verb' => 'POST'],
		['name' => 'request#update', 'url' => '/api/requests/{id}', 'verb' => 'PUT'],
		['name' => 'request#cancel', 'url' => '/api/requests/{id}/cancel', 'verb' => 'POST'],
		['name' => 'request#approve', 'url' => '/api/requests/{id}/approve', 'verb' => 'POST'],
		['name' => 'request#reject', 'url' => '/api/requests/{id}/reject', 'verb' => 'POST'],
		['name' => 'request#addComment', 'url' => '/api/requests/{id}/comments', 'verb' => 'POST'],

		// Attachments (doctor's notes etc., §3.8) — HR and the employee only
		['name' => 'attachment#index', 'url' => '/api/requests/{id}/attachments', 'verb' => 'GET'],
		['name' => 'attachment#create', 'url' => '/api/requests/{id}/attachments', 'verb' => 'POST'],
		['name' => 'attachment#download', 'url' => '/api/attachments/{id}', 'verb' => 'GET'],
		['name' => 'attachment#destroy', 'url' => '/api/attachments/{id}', 'verb' => 'DELETE'],

		// People: HR's employees list, and the autocomplete for the employee / replacement pickers
		['name' => 'employee#index', 'url' => '/api/employees', 'verb' => 'GET'],
		['name' => 'employee#search', 'url' => '/api/employees/search', 'verb' => 'GET'],
		['name' => 'employee#exportJson', 'url' => '/api/employees/json', 'verb' => 'GET'],
		['name' => 'employee#importJson', 'url' => '/api/employees/json', 'verb' => 'POST'],
		['name' => 'employee#groups', 'url' => '/api/groups', 'verb' => 'GET'],

		// Balances & entitlements
		['name' => 'balance#mine', 'url' => '/api/balance', 'verb' => 'GET'],
		['name' => 'balance#team', 'url' => '/api/team/balances', 'verb' => 'GET'],
		['name' => 'balance#forEmployee', 'url' => '/api/employees/{uid}/balance', 'verb' => 'GET'],
		['name' => 'entitlement#index', 'url' => '/api/entitlements', 'verb' => 'GET'],
		['name' => 'entitlement#create', 'url' => '/api/entitlements', 'verb' => 'POST'],
		['name' => 'entitlement#update', 'url' => '/api/entitlements/{id}', 'verb' => 'PUT'],
		['name' => 'entitlement#bulk', 'url' => '/api/entitlements/bulk', 'verb' => 'POST'],
		['name' => 'entitlement#history', 'url' => '/api/entitlements/{id}/history', 'verb' => 'GET'],

		// Coverage & calendar
		['name' => 'coverage#index', 'url' => '/api/coverage', 'verb' => 'GET'],
		['name' => 'calendar#index', 'url' => '/api/calendar', 'verb' => 'GET'],

		// Reference data — leave types
		['name' => 'leaveType#index', 'url' => '/api/leave-types', 'verb' => 'GET'],
		['name' => 'leaveType#create', 'url' => '/api/leave-types', 'verb' => 'POST'],
		['name' => 'leaveType#update', 'url' => '/api/leave-types/{id}', 'verb' => 'PUT'],
		['name' => 'leaveType#destroy', 'url' => '/api/leave-types/{id}', 'verb' => 'DELETE'],

		// Public holidays (§7.2): everyone reads, HR maintains
		['name' => 'holiday#index', 'url' => '/api/holidays', 'verb' => 'GET'],
		['name' => 'holiday#applying', 'url' => '/api/holidays/applying', 'verb' => 'GET'],
		['name' => 'holiday#regions', 'url' => '/api/holidays/regions', 'verb' => 'GET'],
		['name' => 'holiday#create', 'url' => '/api/holidays', 'verb' => 'POST'],
		['name' => 'holiday#import', 'url' => '/api/holidays/import', 'verb' => 'POST'],
		['name' => 'holiday#editing', 'url' => '/api/holidays/editing', 'verb' => 'PUT'],
		['name' => 'holiday#history', 'url' => '/api/holidays/history', 'verb' => 'GET'],
		['name' => 'holiday#update', 'url' => '/api/holidays/{id}', 'verb' => 'PUT'],
		['name' => 'holiday#destroy', 'url' => '/api/holidays/{id}', 'verb' => 'DELETE'],

		// HR reporting & export
		['name' => 'report#balances', 'url' => '/api/reports/balances', 'verb' => 'GET'],
		['name' => 'report#trends', 'url' => '/api/reports/trends', 'verb' => 'GET'],
		['name' => 'report#sickLeave', 'url' => '/api/reports/sick-leave', 'verb' => 'GET'],
		['name' => 'report#insights', 'url' => '/api/reports/insights', 'verb' => 'GET'],
		['name' => 'export#requests', 'url' => '/api/export/requests', 'verb' => 'GET'],
		['name' => 'export#balances', 'url' => '/api/export/balances', 'verb' => 'GET'],

		// Bootstrap: who am I / config for the SPA
		['name' => 'config#session', 'url' => '/api/session', 'verb' => 'GET'],
		['name' => 'config#personal', 'url' => '/api/personal/config', 'verb' => 'GET'],
		['name' => 'config#updatePersonal', 'url' => '/api/personal/config', 'verb' => 'PUT'],

		// Profiles (§6.4): the person, their manager and HR read, HR edits the contract
		['name' => 'profile#show', 'url' => '/api/profiles/{uid}', 'verb' => 'GET'],
		['name' => 'profile#updateContract', 'url' => '/api/profiles/{uid}/contract', 'verb' => 'PUT'],
	],
];
