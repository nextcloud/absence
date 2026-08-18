<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\App\IAppManager;
use OCP\Server;

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// __DIR__ resolves symlinks, so an app checked out beside the server and
// symlinked into apps/ cannot find the server relatively — NEXTCLOUD_ROOT
// overrides the guess in that case.
$serverRoot = getenv('NEXTCLOUD_ROOT') ?: __DIR__ . '/../../..';
require_once $serverRoot . '/lib/base.php';
// The server's test autoloader only exists in a git checkout (CI), not in a
// packaged server — and these tests do not need it to run.
if (is_file($serverRoot . '/tests/autoload.php')) {
	require_once $serverRoot . '/tests/autoload.php';
}

Server::get(IAppManager::class)->loadApp('absence');
