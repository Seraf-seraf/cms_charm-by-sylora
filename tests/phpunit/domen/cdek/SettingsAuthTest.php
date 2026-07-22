<?php

declare(strict_types=1);

use CDEK\Models\Settings\SettingsAuth;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/upload/system/library/cdek_official/src/Contracts/ValidatableSettingsContract.php';
require_once dirname(__DIR__, 4) . '/upload/system/library/cdek_official/src/Models/Settings/SettingsAuth.php';

final class SettingsAuthTest extends TestCase {
	public function testRawSecretCanBeStoredInSettings(): void {
		$settings = new SettingsAuth([
			'cdek_official__authId' => 'account',
			'cdek_official__authSecret' => 'secure-password',
			'cdek_official__apiKey' => 'map-api-key',
		]);

		$settings->validate();

		self::assertSame('secure-password', $settings->__serialize()['cdek_official__authSecret']);
	}

	public function testEmptySecretIsRejected(): void {
		$settings = new SettingsAuth([
			'cdek_official__authId' => 'account',
			'cdek_official__authSecret' => '',
			'cdek_official__apiKey' => 'map-api-key',
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cdek_error_auth_secret_empty');

		$settings->validate();
	}
}
