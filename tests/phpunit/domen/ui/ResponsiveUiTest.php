<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/BrowserTestCase.php';

final class ResponsiveUiTest extends BrowserTestCase {
	/**
	 * @return iterable<string, array{int}>
	 */
	public static function viewportProvider(): iterable {
		foreach (array(320, 360, 390, 414, 768, 1024, 1440) as $width) {
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
		self::assertSame(0, $result['themeToggleCount'] ?? null);
		self::assertSame($width > 1050, $result['account']['desktopVisible'] ?? null);
		self::assertSame($width <= 1050, $result['account']['mobileVisible'] ?? null);
		self::assertSame('Войти', $result['account']['desktopLabel'] ?? null);
		self::assertSame('Войти', $result['account']['mobileLabel'] ?? null);

		if ($width > 1050) {
			self::assertGreaterThanOrEqual(44, $result['account']['desktopWidth'] ?? 0);
			self::assertGreaterThanOrEqual(44, $result['account']['desktopHeight'] ?? 0);
		} else {
			self::assertGreaterThanOrEqual(44, $result['account']['mobileWidth'] ?? 0);
			self::assertGreaterThanOrEqual(44, $result['account']['mobileHeight'] ?? 0);
		}
		self::assertStringContainsString('Atelier Noto', (string)($result['fonts']['body'] ?? ''));
		self::assertStringContainsString('Atelier Cormorant', (string)($result['fonts']['brand'] ?? ''));
		self::assertStringContainsString('Atelier Cormorant', (string)($result['fonts']['display'] ?? ''));
		self::assertGreaterThanOrEqual(17, $result['sizes']['heroDescription'] ?? 0);
		self::assertLessThanOrEqual(21, $result['sizes']['heroDescription'] ?? PHP_INT_MAX);
		if ($width === 390 || $width === 1440) {
			self::assertSame($width === 390 ? 17 : 21, $result['sizes']['heroDescription'] ?? null);
		}
		self::assertGreaterThanOrEqual(51, $result['sizes']['hero'] ?? 0);
		self::assertLessThanOrEqual(81, $result['sizes']['hero'] ?? PHP_INT_MAX);
		if ($width === 390 || $width === 1440) {
			self::assertSame($width === 390 ? 51 : 81, $result['sizes']['hero'] ?? null);
		}
		self::assertGreaterThanOrEqual(37, $result['sizes']['section'] ?? 0);
		self::assertLessThanOrEqual(49, $result['sizes']['section'] ?? PHP_INT_MAX);
		if ($width === 390 || $width === 1440) {
			self::assertSame($width === 390 ? 37 : 49, $result['sizes']['section'] ?? null);
		}
		self::assertTrue($result['dynamic']['iconOnly'] ?? false);
		self::assertFalse($result['dynamic']['iconWithText'] ?? true);
		$this->assertFixtureIcons($result['fixtures'] ?? array());
		$this->assertVisualContracts($result['visualContracts'] ?? array(), $width);

		foreach ($result['icons'] ?? array() as $icon) {
			$wideQuickView = $width <= 767 && str_starts_with((string)($icon['selector'] ?? ''), 'catalog-card__icon ');
			$this->assertCenteredIcon($icon, null, $wideQuickView);
		}
	}

	/**
	 * @return iterable<string, array{int}>
	 */
	public static function headerViewportProvider(): iterable {
		foreach (array(320, 390, 1200, 1201, 1440) as $width) {
			yield (string)$width => array($width);
		}
	}

	#[PHPUnit\Framework\Attributes\DataProvider('headerViewportProvider')]
	public function testMainMenuLayoutAndControlsAcrossStorefrontPages(int $width): void {
		if (DB_DATABASE !== 'charm_functional' || !str_starts_with(DIR_IMAGE, sys_get_temp_dir() . '/sylora-functional-tests-')) {
			throw new RuntimeException('Сценарию шапки нужна изолированная витрина TestBootstrap.');
		}

		$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
		$email = 'header-' . bin2hex(random_bytes(8)) . '@example.test';
		$password = bin2hex(random_bytes(8));
		$salt = bin2hex(random_bytes(4));
		$passwordHash = sha1($salt . sha1($salt . sha1($password)));
		$customer = $database->prepare(
			"INSERT INTO `" . DB_PREFIX . "customer` (customer_group_id, language_id, firstname, lastname, email, telephone, fax, password, salt, custom_field, ip, status, safe, token, code, date_added) VALUES (1, 1, 'Тест', 'Шапка', ?, '', '', ?, ?, '', '', 1, 0, '', '', NOW())"
		);
		$customer->execute(array($email, $passwordHash, $salt));
		$customerId = $database->insert_id;
		$customer->close();

		$logo = 'catalog/sylora/charm-by-sylora.png';
		$fixtureLogo = DIR_IMAGE . $logo;
		$copiedLogo = false;

		try {
			if (!is_file($fixtureLogo)) {
				$copiedLogo = copy(dirname(__DIR__, 4) . '/upload/image/' . $logo, $fixtureLogo);
				self::assertTrue($copiedLogo);
			}

			$scenario = 'header@' . $width . '@' . rawurlencode($email) . '@' . $password;
			$result = $this->runBrowserScenario(
				__DIR__ . '/support/responsive_ui_browser.mjs',
				$scenario
			);
		} finally {
			if ($copiedLogo) {
				unlink($fixtureLogo);
			}
			foreach (array('customer_ip', 'customer') as $table) {
				$delete = $database->prepare('DELETE FROM `' . DB_PREFIX . $table . '` WHERE customer_id = ?');
				$delete->execute(array($customerId));
				$delete->close();
			}
			$database->close();
		}

		$compact = $width <= 1200;
		$expectedLinks = array('Главная', 'Каталог', 'Обо мне', 'Контакты');

		foreach (array('home', 'login', 'account') as $page) {
			$state = $result[$page] ?? array();
			$accountLabel = $page === 'account' ? 'Личный кабинет' : 'Войти';
			$accountRoute = $page === 'account' ? 'account/account' : 'account/login';
			self::assertSame($width, $state['width'] ?? null, $page);
			self::assertSame($compact, $state['compact'] ?? null, $page);
			self::assertTrue($state['brandLogoLoaded'] ?? false, $page);
			self::assertTrue($state['brandAndActionsAligned'] ?? false, $page);
			self::assertTrue($state['headingBelowMenu'] ?? false, $page);
			self::assertTrue($state['menuContained'] ?? false, $page);
			self::assertSame($accountLabel, $state['accountLabel'] ?? null, $page);
			self::assertSame($accountRoute, $state['accountRoute'] ?? null, $page);

			if ($compact) {
				self::assertSame(
					array_merge($expectedLinks, array('Корзина', $accountLabel)),
					$state['visibleLinks'] ?? null,
					$page
				);
				self::assertTrue($state['opened'] ?? false, $page);
				self::assertTrue($state['menuBelowHeaderRow'] ?? false, $page);
				self::assertTrue($state['closeFocused'] ?? false, $page);
				self::assertTrue($state['closeRestoresFocus'] ?? false, $page);
			} else {
				self::assertSame($expectedLinks, $state['visibleLinks'] ?? null, $page);
				self::assertTrue($state['menuInHeaderRow'] ?? false, $page);
			}
		}

		self::assertTrue($result['cartOpens'] ?? false);

		if ($compact) {
			self::assertTrue($result['escapeRestoresFocus'] ?? false);
			self::assertTrue($result['backdropRestoresFocus'] ?? false);
			self::assertTrue($result['keyboardCyclesThroughMenu'] ?? false);
			self::assertTrue($result['compactCartNavigates'] ?? false);
		} else {
			self::assertTrue($result['desktopSearchOpens'] ?? false);
			self::assertTrue($result['desktopSearchNavigates'] ?? false);
		}

		if ($width === 1200) {
			self::assertTrue($result['desktopResizeClosesMenu'] ?? false);
			self::assertTrue($result['menuRemainsClosedOnNarrowing'] ?? false);
		}

		if ($width === 320) {
			self::assertTrue($result['shortMenuScrollable'] ?? false);
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
		$this->assertVisualContracts($result['visualContracts'] ?? array(), 320);
	}

	public function testResponsiveUiRemainsLightWithSavedAndSystemDarkPreferences(): void {
		$result = $this->runBrowserScenario(
			__DIR__ . '/support/responsive_ui_browser.mjs',
			'320@1@dark'
		);

		$lightResult = $this->runBrowserScenario(
			__DIR__ . '/support/responsive_ui_browser.mjs',
			'320@1@light'
		);

		self::assertSame(320, $result['viewportWidth'] ?? null);
		self::assertNotEmpty($lightResult['pageColors'] ?? array());
		self::assertSame($lightResult['pageColors'], $result['pageColors'] ?? null);
		self::assertSame(0, $result['themeToggleCount'] ?? null);
		self::assertLessThanOrEqual(1, $result['documentOverflow'] ?? null);
		self::assertTrue($result['brand']['fullyVisible'] ?? false);
		self::assertTrue($result['account']['mobileVisible'] ?? false);
		self::assertGreaterThanOrEqual(44, $result['account']['mobileWidth'] ?? 0);
		self::assertGreaterThanOrEqual(44, $result['account']['mobileHeight'] ?? 0);
		$this->assertVisualContracts($result['visualContracts'] ?? array(), 320);
	}

	/**
	 * @param array<string, array<string, mixed>> $fixtures
	 */
	private function assertFixtureIcons(array $fixtures): void {
		$expectedSizes = array(
			'dynamic' => 44,
			'catalog' => 44,
			'quickView' => 44,
			'cartRemove' => 44,
			'miniCartRemove' => 44,
			'productTools' => 44,
			'search' => 44,
			'miniItemRemove' => 44,
			'cartItemRemove' => 44,
		);

		foreach ($expectedSizes as $name => $expectedSize) {
			self::assertArrayHasKey($name, $fixtures);
			$this->assertCenteredIcon($fixtures[$name], $expectedSize);
		}
	}

	/**
	 * @param array<string, mixed> $contracts
	 */
	private function assertVisualContracts(array $contracts, int $width): void {
		$miniSize = $width <= 767 ? 64 : 80;
		$cartWidth = $width <= 767 ? 112 : ($width <= 991 ? 96 : 150);
		$cartHeight = $width <= 767 ? 136 : ($width <= 991 ? 120 : 170);

		$this->assertResponsiveImage($contracts['miniImage'] ?? array(), $miniSize, 'mini cart image');
		$this->assertResponsiveImage($contracts['cartImage'] ?? array(), $cartWidth, 'cart image', $cartHeight);
		$this->assertResponsiveImage($contracts['contactImage'] ?? array(), 160, 'contact image');

		self::assertSame('right', $contracts['wishlist']['float'] ?? null, 'wishlist alignment');
		self::assertTrue($contracts['wishlist']['contained'] ?? false, 'wishlist must remain inside product summary');
		self::assertFalse($contracts['captcha']['hasVisibleTitle'] ?? true);
		self::assertSame('Подтвердите, что вы не робот', $contracts['captcha']['label'] ?? null);
		self::assertSame('fixture-captcha-control', $contracts['captcha']['labelTarget'] ?? null);
		self::assertTrue($contracts['captcha']['hasError'] ?? false);
		self::assertTrue($contracts['captcha']['fieldContained'] ?? false, 'captcha field must remain inside its section');
		self::assertTrue($contracts['captcha']['responsiveOverflowing'] ?? false, 'captcha overflow detector must remain active');
		self::assertGreaterThan($contracts['captcha']['widgetClientWidth'] ?? PHP_INT_MAX, $contracts['captcha']['widgetScrollWidth'] ?? 0);
		self::assertSame('auto', $contracts['captcha']['widgetOverflowX'] ?? null);

		$miniDropdown = $contracts['miniDropdown'] ?? array();
		self::assertTrue($miniDropdown['contained'] ?? false, 'mini cart must remain inside viewport');
		self::assertSame(0, $miniDropdown['overflow'] ?? null, 'mini cart must not overflow horizontally');
		self::assertSame(0, $miniDropdown['itemOverflow'] ?? null, 'mini cart item must shrink inside dropdown');

		if ($width <= 767) {
			$availableWidth = $miniDropdown['availableWidth'] ?? $width;
			self::assertSame($availableWidth - 24, $miniDropdown['width'] ?? null, 'mobile mini cart width');
			self::assertSame(12, $miniDropdown['left'] ?? null, 'mobile mini cart left inset');
			self::assertSame($availableWidth - 12, $miniDropdown['right'] ?? null, 'mobile mini cart right inset');
		} else {
			self::assertSame(420, $miniDropdown['width'] ?? null, 'desktop mini cart width');
		}

		$catalog = $contracts['catalog'] ?? array();
		$expectedColumns = $width <= 350 ? 1 : ($width <= 1199 ? 2 : 3);
		self::assertSame($expectedColumns, $catalog['columns'] ?? null, 'catalog columns');
		self::assertTrue($catalog['equalHeights'] ?? false, 'catalog cards must have equal heights');
		self::assertSame(0, $catalog['overflow'] ?? null, 'catalog grid overflow');
		self::assertSame(44, $catalog['wishlistWidth'] ?? null, 'catalog wishlist width');
		self::assertSame(44, $catalog['wishlistHeight'] ?? null, 'catalog wishlist height');
		self::assertTrue($catalog['wishlistContained'] ?? false, 'catalog wishlist must remain inside the image');
		self::assertTrue($catalog['overlaysSeparate'] ?? false, 'badge and wishlist must not overlap');
		self::assertSame(0, $catalog['toolbarOverflow'] ?? null, 'catalog toolbar overflow');
		self::assertSame(array(44, 44), $catalog['controlHeights'] ?? null, 'catalog toolbar control heights');
	}

	/**
	 * @param array<string, mixed> $image
	 */
	private function assertResponsiveImage(array $image, int $expectedSize, string $message, ?int $expectedHeight = null): void {
		$expectedHeight = $expectedHeight ?? $expectedSize;
		self::assertSame($expectedSize, $image['containerWidth'] ?? null, $message);
		self::assertSame($expectedHeight, $image['containerHeight'] ?? null, $message);
		self::assertLessThanOrEqual($expectedSize, $image['imageWidth'] ?? PHP_INT_MAX, $message);
		self::assertLessThanOrEqual($expectedHeight, $image['imageHeight'] ?? PHP_INT_MAX, $message);
		self::assertGreaterThanOrEqual($image['imageWidth'] ?? PHP_INT_MAX, $image['naturalWidth'] ?? 0, $message);
		self::assertGreaterThanOrEqual($image['imageHeight'] ?? PHP_INT_MAX, $image['naturalHeight'] ?? 0, $message);
		self::assertSame('contain', $image['objectFit'] ?? null, $message);
	}

	/**
	 * @param array<string, mixed> $icon
	 */
	private function assertCenteredIcon(array $icon, ?int $expectedSize, bool $wide = false): void {
		$message = (string)($icon['selector'] ?? 'icon');

		self::assertTrue($icon['hasGlyph'] ?? false, $message);

		if ($wide) {
			self::assertGreaterThanOrEqual(44, $icon['width'] ?? 0, $message);
			self::assertSame(44, $icon['height'] ?? null, $message);
		} elseif ($expectedSize === null) {
			self::assertContains($icon['width'] ?? null, array(40, 44), $message);
		} else {
			self::assertSame($expectedSize, $icon['width'] ?? null, $message);
		}

		if (!$wide) {
			self::assertSame($icon['width'] ?? null, $icon['height'] ?? null, $message);
		}
		self::assertLessThanOrEqual(1, $icon['deltaX'] ?? null, $message);
		self::assertLessThanOrEqual(1, $icon['deltaY'] ?? null, $message);
		self::assertSame(16, $icon['glyphSize'] ?? null, $message);
	}
}
