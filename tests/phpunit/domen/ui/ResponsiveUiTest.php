<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class ResponsiveUiTest extends BrowserTestCase {
	/**
	 * @return iterable<string, array{int}>
	 */
	public static function viewportProvider(): iterable {
		foreach (array(320, 360, 390, 768, 1024, 1440) as $width) {
			yield (string)$width => array($width);
		}
	}

	#[PHPUnit\Framework\Attributes\DataProvider('viewportProvider')]
	public function testResponsiveUiUsesStableSizes(int $width): void {
		$result = $this->runBrowserScenario(
			__DIR__ . '/support/responsive_ui_browser.mjs',
			(string)$width
		);

		self::assertSame($width, $result['viewportWidth'] ?? null);
		self::assertLessThanOrEqual(1, $result['documentOverflow'] ?? null);
		self::assertSame('Charm by Sylora', $result['brand']['text'] ?? null);
		self::assertTrue($result['brand']['fullyVisible'] ?? false);
		self::assertLessThanOrEqual(1, $result['brand']['overflow'] ?? null);
		self::assertStringContainsString('Rubik', (string)($result['fonts']['body'] ?? ''));
		self::assertStringContainsString('Rubik', (string)($result['fonts']['brand'] ?? ''));
		self::assertStringContainsString('Montserrat', (string)($result['fonts']['display'] ?? ''));
		self::assertSame(18, $result['sizes']['body'] ?? null);
		self::assertSame($width <= 767 ? 34 : ($width <= 991 ? 44 : 56), $result['sizes']['hero'] ?? null);
		self::assertSame($width <= 767 ? 28 : ($width <= 991 ? 32 : 36), $result['sizes']['section'] ?? null);
		self::assertTrue($result['dynamic']['iconOnly'] ?? false);
		self::assertFalse($result['dynamic']['iconWithText'] ?? true);
		$this->assertFixtureIcons($result['fixtures'] ?? array());

		foreach ($result['icons'] ?? array() as $icon) {
			$this->assertCenteredIcon($icon, null);
		}
	}

	public function testResponsiveUiAtTwoHundredPercentScale(): void {
		$result = $this->runBrowserScenario(
			__DIR__ . '/support/responsive_ui_browser.mjs',
			'320@2'
		);

		self::assertSame(320, $result['viewportWidth'] ?? null);
		self::assertSame(2, $result['pageScale'] ?? null);
		self::assertLessThanOrEqual(1, $result['documentOverflow'] ?? null);
		self::assertTrue($result['brand']['fullyVisible'] ?? false);
		self::assertLessThanOrEqual(1, $result['brand']['overflow'] ?? null);
		$this->assertFixtureIcons($result['fixtures'] ?? array());
	}

	/**
	 * @param array<string, array<string, mixed>> $fixtures
	 */
	private function assertFixtureIcons(array $fixtures): void {
		$expectedSizes = array(
			'dynamic' => 40,
			'catalog' => 44,
			'quickView' => 44,
			'cartRemove' => 44,
			'miniCartRemove' => 44,
			'productTools' => 44,
			'search' => 40,
		);

		foreach ($expectedSizes as $name => $expectedSize) {
			self::assertArrayHasKey($name, $fixtures);
			$this->assertCenteredIcon($fixtures[$name], $expectedSize);
		}
	}

	/**
	 * @param array<string, mixed> $icon
	 */
	private function assertCenteredIcon(array $icon, ?int $expectedSize): void {
		$message = (string)($icon['selector'] ?? 'icon');

		self::assertTrue($icon['hasGlyph'] ?? false, $message);

		if ($expectedSize === null) {
			self::assertContains($icon['width'] ?? null, array(40, 44), $message);
		} else {
			self::assertSame($expectedSize, $icon['width'] ?? null, $message);
		}

		self::assertSame($icon['width'] ?? null, $icon['height'] ?? null, $message);
		self::assertLessThanOrEqual(1, $icon['deltaX'] ?? null, $message);
		self::assertLessThanOrEqual(1, $icon['deltaY'] ?? null, $message);
		self::assertSame(16, $icon['glyphSize'] ?? null, $message);
	}
}
