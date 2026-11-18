<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRequestId()
 * @method void setRequestId(int $requestId)
 * @method string getUploaderUid()
 * @method void setUploaderUid(string $uploaderUid)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getMime()
 * @method void setMime(string $mime)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method bool getEncrypted()
 * @method void setEncrypted(bool $encrypted)
 */
class Attachment extends Entity implements \JsonSerializable {
	protected int $requestId = 0;
	protected string $uploaderUid = '';
	protected string $name = '';
	protected string $mime = 'application/octet-stream';
	protected int $size = 0;
	protected ?\DateTime $createdAt = null;
	/** Whether the stored file is encrypted, or plaintext from before (§3.8) */
	protected bool $encrypted = false;

	public function __construct() {
		$this->addType('requestId', 'integer');
		$this->addType('size', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('encrypted', 'boolean');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'requestId' => $this->requestId,
			'uploaderUid' => $this->uploaderUid,
			'name' => $this->name,
			'mime' => $this->mime,
			'size' => $this->size,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
