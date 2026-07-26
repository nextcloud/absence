<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Exception;

use OCP\AppFramework\Http;

class NotFoundException extends AbsenceException {
	#[\Override]
	public function getHttpStatus(): int {
		return Http::STATUS_NOT_FOUND;
	}
}
