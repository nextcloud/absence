<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\SessionService;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialState,
		private SessionService $sessionService,
		private LeaveTypeMapper $leaveTypeMapper,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Render the single-page app.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('session', $this->sessionService->getSessionInfo());
		$this->initialState->provideInitialState('leaveTypes', array_map(
			static fn ($t) => $t->jsonSerialize(),
			$this->leaveTypeMapper->findAll(),
		));

		Util::addScript($this->appName, 'absence-main');

		$response = new TemplateResponse($this->appName, 'main');
		$csp = new ContentSecurityPolicy();
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
