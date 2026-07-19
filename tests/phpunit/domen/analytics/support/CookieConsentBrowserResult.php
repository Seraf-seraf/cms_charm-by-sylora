<?php

declare(strict_types=1);

final class CookieConsentBrowserResult {
	public function __construct(
		public readonly bool $bannerHidden,
		public readonly bool $dataLayerExists,
		public readonly string $consent,
		public readonly int $metricaRequestCount,
		public readonly int $ymCookieCount,
		public readonly int $metricaScriptCount,
		public readonly int $metricaRequestAfterRefusalCount
	) {
	}

	/**
	 * @param array<string, mixed> $result
	 */
	public static function fromArray(array $result): self {
		return new self(
			self::readBool($result, 'bannerHidden'),
			self::readBool($result, 'dataLayerExists'),
			self::readString($result, 'consent'),
			self::countStringList($result, 'metricaRequests'),
			self::countStringList($result, 'ymCookies'),
			self::countStringList($result, 'metricaScripts'),
			self::countStringList($result, 'metricaRequestsAfterRefusal')
		);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function readBool(array $result, string $key): bool {
		if (!isset($result[$key]) || !is_bool($result[$key])) {
			throw new RuntimeException('Поле ' . $key . ' отсутствует в результате browser-runner.');
		}

		return $result[$key];
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function readString(array $result, string $key): string {
		if (!isset($result[$key]) || !is_string($result[$key])) {
			throw new RuntimeException('Поле ' . $key . ' отсутствует в результате browser-runner.');
		}

		return $result[$key];
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function countStringList(array $result, string $key): int {
		if (!isset($result[$key]) || !is_array($result[$key])) {
			throw new RuntimeException('Поле ' . $key . ' отсутствует в результате browser-runner.');
		}

		foreach ($result[$key] as $value) {
			if (!is_string($value)) {
				throw new RuntimeException('Поле ' . $key . ' содержит некорректное значение.');
			}
		}

		return count($result[$key]);
	}
}
