<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\AttachmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class AttachmentController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private AttachmentService $service,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): DataResponse {
		return $this->handle(fn () => array_map(
			static fn ($a) => $a->jsonSerialize(),
			$this->service->listForRequest((string)$this->userId, $id),
		));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function create(int $id): DataResponse {
		return $this->handle(function () use ($id): array {
			$upload = $this->request->getUploadedFile('file');
			if (!is_array($upload)) {
				throw new \OCA\Absence\Exception\ValidationException($this->l->t('No file was uploaded.'));
			}
			return $this->service->upload((string)$this->userId, $id, $upload)->jsonSerialize();
		});
	}

	/**
	 * Served as a plain download link, so no CSRF token travels with it; the
	 * permission check inside the service is the actual gate.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function download(int $id): DataResponse|DataDownloadResponse {
		return $this->handleDownload(function () use ($id): DataDownloadResponse {
			$result = $this->service->download((string)$this->userId, $id);
			$response = new DataDownloadResponse(
				$result['content'],
				$result['attachment']->getName(),
				$result['attachment']->getMime(),
			);
			// Documents are downloaded, never rendered in the app's origin.
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			return $response;
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id): array {
			$this->service->delete((string)$this->userId, $id);
			return ['deleted' => true];
		});
	}

	/**
	 * Like handle(), but passes a download response through untouched.
	 *
	 * @param \Closure():DataDownloadResponse $fn
	 */
	private function handleDownload(\Closure $fn): DataResponse|DataDownloadResponse {
		try {
			return $fn();
		} catch (\OCA\Absence\Exception\AbsenceException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getHttpStatus());
		}
	}
}
