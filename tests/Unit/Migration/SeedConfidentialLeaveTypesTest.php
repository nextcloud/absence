<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Migration;

use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Migration\SeedConfidentialLeaveTypes;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class SeedConfidentialLeaveTypesTest extends TestCase {
	private function existingType(string $key, int $sortOrder = 0): LeaveType {
		$type = new LeaveType();
		$type->setKey($key);
		$type->setSortOrder($sortOrder);
		return $type;
	}

	public function testSeedsTheConfidentialTypesAsHrOnly(): void {
		$mapper = $this->createMock(LeaveTypeMapper::class);
		$mapper->method('findAll')->willReturn([$this->existingType('annual', 0), $this->existingType('sick', 1)]);
		$inserted = [];
		$mapper->method('insert')->willReturnCallback(static function (LeaveType $t) use (&$inserted): LeaveType {
			$inserted[] = $t;
			return $t;
		});

		(new SeedConfidentialLeaveTypes($mapper))->run($this->createMock(IOutput::class));

		self::assertSame(['maternity', 'work_prohibition', 'doctors_note', 'parental'], array_map(
			static fn (LeaveType $t): string => $t->getKey(),
			$inserted,
		));
		foreach ($inserted as $type) {
			self::assertTrue($type->getHrOnly());
			self::assertFalse($type->getEmployeeRequestable());
			self::assertFalse($type->getRequiresApproval());
			self::assertFalse($type->getCountsAgainstBalance());
		}
		// Appended after the existing types, not interleaved.
		self::assertSame([2, 3, 4, 5], array_map(
			static fn (LeaveType $t): int => $t->getSortOrder(),
			$inserted,
		));
	}

	public function testIsIdempotentPerKey(): void {
		// Re-running (every upgrade does) must not duplicate, and must not
		// recreate a type HR deliberately customised or disabled. A partially
		// seeded install only receives what is missing.
		$mapper = $this->createMock(LeaveTypeMapper::class);
		$mapper->method('findAll')->willReturn([
			$this->existingType('maternity', 0),
			$this->existingType('work_prohibition', 1),
			$this->existingType('doctors_note', 2),
		]);
		$inserted = [];
		$mapper->method('insert')->willReturnCallback(static function (LeaveType $t) use (&$inserted): LeaveType {
			$inserted[] = $t;
			return $t;
		});

		(new SeedConfidentialLeaveTypes($mapper))->run($this->createMock(IOutput::class));

		self::assertSame(['parental'], array_map(
			static fn (LeaveType $t): string => $t->getKey(),
			$inserted,
		));
	}
}
