<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/upload/system/helper/request.php';

final class TrustedProxyHttpsTest extends TestCase {
	public function testDirectHttpsDoesNotRequireTrustedProxy(): void {
		self::assertTrue(is_https_request(array(
			'HTTPS'      => 'on',
			'REMOTE_ADDR' => '203.0.113.10',
		), array()));
	}

	public function testHttpsFromTrustedProxyIsAccepted(): void {
		self::assertTrue(is_https_request(array(
			'HTTP_X_FORWARDED_PROTO' => 'https',
			'REMOTE_ADDR'            => '192.0.2.10',
		), array('192.0.2.10')));
	}

	public function testForwardedProtoFromUntrustedClientIsIgnored(): void {
		self::assertFalse(is_https_request(array(
			'HTTP_X_FORWARDED_PROTO' => 'https',
			'REMOTE_ADDR'            => '203.0.113.10',
		), array('192.0.2.10')));
	}

	public function testForwardedSslFromUntrustedClientIsIgnored(): void {
		self::assertFalse(is_https_request(array(
			'HTTP_X_FORWARDED_SSL' => 'on',
			'REMOTE_ADDR'          => '203.0.113.10',
		), array('192.0.2.10')));
	}

	public function testAmbiguousForwardedProtoIsRejected(): void {
		self::assertFalse(is_https_request(array(
			'HTTP_X_FORWARDED_PROTO' => 'https, http',
			'REMOTE_ADDR'            => '192.0.2.10',
		), array('192.0.2.10')));
	}

	public function testControllersUseNormalizedHttpsValueOnly(): void {
		$root = dirname(__DIR__, 4);
		$startup = file_get_contents($root . '/upload/system/startup.php');

		self::assertIsString($startup);
		self::assertStringContainsString("defined('TRUSTED_PROXIES')", $startup);
		self::assertStringContainsString('is_https_request($_SERVER, $trusted_proxies)', $startup);

		foreach (array(
			'upload/catalog/controller/common/header.php',
			'upload/catalog/controller/common/home.php',
		) as $relative_path) {
			$source = file_get_contents($root . '/' . $relative_path);

			self::assertIsString($source);
			self::assertStringNotContainsString('HTTP_X_FORWARDED_PROTO', $source, $relative_path);
		}
	}
}
