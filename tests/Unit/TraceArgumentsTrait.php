<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit;

use PHPUnit\Framework\Attributes\After;

/**
 * What an exception's trace tells the log about the arguments of each call.
 */
trait TraceArgumentsTrait {
	private string|false $ignoreArgs = false;

	/** Traces keep the arguments, as on a server with zend.exception_ignore_args off. */
	private function keepTraceArguments(): void {
		$this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
	}

	#[After]
	protected function restoreTraceArguments(): void {
		if ($this->ignoreArgs !== false) {
			ini_set('zend.exception_ignore_args', $this->ignoreArgs);
			$this->ignoreArgs = false;
		}
	}

	/**
	 * Every value in the arguments of the trace, walked like the server's
	 * exception serializer: arrays and the public properties of objects, but
	 * not what a sensitive parameter replaced.
	 *
	 * @return list<scalar|null>
	 */
	private function traceArguments(\Throwable $e): array {
		// Outside any class, so that only public properties are read
		$publicProperties = \Closure::bind(static fn (object $object): array => get_object_vars($object), null, null);
		$values = [];
		$walk = static function (mixed $value, int $depth) use (&$walk, &$values, $publicProperties): void {
			if ($value instanceof \SensitiveParameterValue || $depth === 0) {
				return;
			}
			if (is_object($value)) {
				$value = $publicProperties($value);
			}
			if (is_array($value)) {
				foreach ($value as $item) {
					$walk($item, $depth - 1);
				}
				return;
			}
			$values[] = $value;
		};
		foreach ($e->getTrace() as $frame) {
			foreach ($frame['args'] ?? [] as $arg) {
				$walk($arg, 5);
			}
		}
		return $values;
	}
}
