<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Standalone unit-test bootstrap: OCP classes come from the nextcloud/ocp
// dev dependency, so no running server is needed.
require_once __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp declares no composer autoload (it targets static analysis);
// map the OCP namespace onto the package for runtime use in unit tests.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'OCP\\')) {
		$path = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (file_exists($path)) {
			require_once $path;
		}
	}
});
