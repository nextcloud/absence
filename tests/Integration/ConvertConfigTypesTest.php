<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Migration\ConvertConfigTypes;
use OCA\Absence\Service\ConfigService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The repair step against the real app config: a value stored before the
 * lexicon existed gets its type, and the value survives.
 */
class ConvertConfigTypesTest extends TestCase {
	private const KEY = ConfigLexicon::KEY_ESCALATION_WINDOW;

	private IAppConfig $appConfig;
	private int $before;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = Server::get(IAppConfig::class);
		$this->before = $this->appConfig->getValueInt(ConfigService::APP_ID, self::KEY);
	}

	protected function tearDown(): void {
		$this->appConfig->deleteKey(ConfigService::APP_ID, self::KEY);
		$this->appConfig->setValueInt(ConfigService::APP_ID, self::KEY, $this->before);
		parent::tearDown();
	}

	public function testAnUntypedValueGetsItsTypeAndKeepsItsValue(): void {
		$this->appConfig->deleteKey(ConfigService::APP_ID, self::KEY);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->insert('appconfig')->values([
			'appid' => $qb->createNamedParameter(ConfigService::APP_ID),
			'configkey' => $qb->createNamedParameter(self::KEY),
			'configvalue' => $qb->createNamedParameter('9'),
			'type' => $qb->createNamedParameter(IAppConfig::VALUE_MIXED, IQueryBuilder::PARAM_INT),
			'lazy' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$this->appConfig->clearCache();

		Server::get(ConvertConfigTypes::class)->run($this->createMock(IOutput::class));

		$details = $this->appConfig->getDetails(ConfigService::APP_ID, self::KEY);
		self::assertSame([IAppConfig::VALUE_INT, true, '9'], [$details['type'], $details['lazy'], $details['value']]);
		self::assertSame(9, $this->appConfig->getValueInt(ConfigService::APP_ID, self::KEY));
	}
}
