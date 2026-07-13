<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Exception;

use OCP\AppFramework\Http;

/**
 * Base for domain errors that map cleanly onto HTTP status codes.
 */
class AbsenceException extends \RuntimeException {
	public function getHttpStatus(): int {
		return Http::STATUS_BAD_REQUEST;
	}
}
