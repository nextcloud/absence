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

		// Balances & entitlements
		['name' => 'balance#mine', 'url' => '/api/balance', 'verb' => 'GET'],
		['name' => 'balance#forEmployee', 'url' => '/api/employees/{uid}/balance', 'verb' => 'GET'],
		['name' => 'entitlement#index', 'url' => '/api/entitlements', 'verb' => 'GET'],
		['name' => 'entitlement#update', 'url' => '/api/entitlements/{id}', 'verb' => 'PUT'],
		['name' => 'entitlement#bulk', 'url' => '/api/entitlements/bulk', 'verb' => 'POST'],

		// Coverage & calendar
		['name' => 'coverage#index', 'url' => '/api/coverage', 'verb' => 'GET'],
		['name' => 'calendar#index', 'url' => '/api/calendar', 'verb' => 'GET'],

		// Reference data — leave types
		['name' => 'leaveType#index', 'url' => '/api/leave-types', 'verb' => 'GET'],
		['name' => 'leaveType#create', 'url' => '/api/leave-types', 'verb' => 'POST'],
		['name' => 'leaveType#update', 'url' => '/api/leave-types/{id}', 'verb' => 'PUT'],
		['name' => 'leaveType#destroy', 'url' => '/api/leave-types/{id}', 'verb' => 'DELETE'],

		// HR reporting & export
		['name' => 'report#balances', 'url' => '/api/reports/balances', 'verb' => 'GET'],
		['name' => 'report#trends', 'url' => '/api/reports/trends', 'verb' => 'GET'],
		['name' => 'export#requests', 'url' => '/api/export/requests', 'verb' => 'GET'],
		['name' => 'export#balances', 'url' => '/api/export/balances', 'verb' => 'GET'],

		// Bootstrap: who am I / config for the SPA
		['name' => 'config#session', 'url' => '/api/session', 'verb' => 'GET'],
		['name' => 'config#admin', 'url' => '/api/admin/config', 'verb' => 'GET'],
		['name' => 'config#updateAdmin', 'url' => '/api/admin/config', 'verb' => 'PUT'],
		['name' => 'config#personal', 'url' => '/api/personal/config', 'verb' => 'GET'],
		['name' => 'config#updatePersonal', 'url' => '/api/personal/config', 'verb' => 'PUT'],
	],
];
