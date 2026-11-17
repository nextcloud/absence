<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A comment one recipient is still to be mailed about (§11).
 *
 * @method int getCommentId()
 * @method void setCommentId(int $commentId)
 * @method int getRequestId()
 * @method void setRequestId(int $requestId)
 * @method string getRecipientUid()
 * @method void setRecipientUid(string $uid)
 */
class CommentMail extends Entity {
	protected int $commentId = 0;
	protected int $requestId = 0;
	protected string $recipientUid = '';

	public function __construct() {
		$this->addType('commentId', 'integer');
		$this->addType('requestId', 'integer');
		$this->addType('recipientUid', 'string');
	}
}
