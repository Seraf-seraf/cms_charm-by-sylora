<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContentSecurityPolicyTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = dirname(__DIR__, 4);
	}

	public function testCatalogUsesEnforcedContentSecurityPolicy(): void {
		$startup = $this->read('upload/catalog/controller/startup/startup.php');

		self::assertStringContainsString("addHeader('Content-Security-Policy: '", $startup);
		self::assertStringNotContainsString('Content-Security-Policy-Report-Only', $startup);
	}

	public function testNonceIsGeneratedAndPassedToEveryView(): void {
		$startup = $this->read('upload/catalog/controller/startup/startup.php');
		$loader = $this->read('upload/system/engine/loader.php');

		self::assertStringContainsString('base64_encode(random_bytes(16))', $startup);
		self::assertStringContainsString("set('csp_nonce', \$csp_nonce)", $startup);
		self::assertStringContainsString("\$data['csp_nonce'] = \$csp_nonce", $loader);
	}

	public function testPolicyContainsRequiredRestrictionsAndNonce(): void {
		$startup = $this->read('upload/catalog/controller/startup/startup.php');

		foreach (array(
			"default-src 'self'",
			"base-uri 'self'",
			"object-src 'none'",
			"frame-ancestors 'none'",
			"form-action 'self'",
			"script-src 'self' 'nonce-",
			"script-src-attr 'none'",
			"connect-src 'self'",
			'frame-src ',
			"img-src 'self'",
			"style-src 'self'",
			"font-src 'self'",
			"worker-src 'self'",
			'upgrade-insecure-requests',
			'report-uri /csp-report.php',
		) as $directive) {
			self::assertStringContainsString($directive, $startup);
		}

		self::assertStringNotContainsString("'unsafe-eval'", $startup);
		self::assertStringNotContainsString("script-src-attr 'unsafe-inline'", $startup);
		self::assertStringNotContainsString('https://widget.pochta.ru', $startup);
		self::assertStringNotContainsString('https://smartcaptcha.cloud.yandex.ru', $startup);
	}

	public function testActiveStorefrontTemplatesContainNoExecutableInlineScriptsOrEventAttributes(): void {
		$templates = array(
			'upload/catalog/view/theme/charm_by_sylora/template/common/header.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/common/cart.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/category.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/product.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/search.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/special.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/information/contact.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/information/information.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/cart.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/checkout.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/register.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/guest.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/payment_address.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/shipping_address.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/guest_shipping.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/confirm.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/extension/module/banner.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/extension/module/slideshow.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/extension/module/featured.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/extension/total/shipping.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/extension/total/voucher.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/extension/payment/cod.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/extension/payment/free_checkout.twig',
			'upload/catalog/view/theme/default/template/extension/payment/payment_service.twig',
			'upload/catalog/view/theme/default/template/extension/analytics/yandex_metrica.twig',
		);

		foreach ($templates as $template) {
			$content = $this->read($template);

			self::assertDoesNotMatchRegularExpression('/\\son[a-z]+\\s*=/i', $content, $template);
			self::assertDoesNotMatchRegularExpression(
				'/<script\\b(?![^>]*\\bsrc=)(?![^>]*\\btype="application\\/(?:ld\\+)?json")[^>]*>/i',
				$content,
				$template
			);
		}
	}

	public function testJsonLdAndAjaxConfigScriptsUseNonce(): void {
		$templates = array(
			'upload/catalog/view/theme/charm_by_sylora/template/common/header.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/category.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/product.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/search.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/product/special.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/information/contact.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/information/information.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/checkout.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/register.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/guest.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/payment_address.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/shipping_address.twig',
			'upload/catalog/view/theme/charm_by_sylora/template/checkout/guest_shipping.twig',
		);

		foreach ($templates as $template) {
			$content = $this->read($template);

			self::assertStringContainsString('nonce="{{ csp_nonce }}"', $content, $template);
		}
	}

	public function testCdekWidgetIsImmutableAndUsesSubresourceIntegrity(): void {
		$config = $this->read('upload/system/library/cdek_official/src/Config.php');
		$storefront = $this->read('upload/catalog/view/javascript/shipping/cdek_official.js');
		$admin = $this->read('upload/admin/view/template/extension/shipping/cdek_official/settings.twig');
		$url = 'https://cdn.jsdelivr.net/npm/@cdek-it/widget@3.10.4/dist/cdek-widget.umd.js';
		$integrity = 'sha384-d3BX/k7LeLv5Ld8LGJ/XhQKQarz+3sxJrRzNRfjTjuSrLNFvz3LFYD4aV610Jg6s';

		self::assertStringContainsString($url, $config);
		self::assertStringContainsString($integrity, $config);
		self::assertStringContainsString($url, $storefront);
		self::assertStringContainsString($integrity, $storefront);
		self::assertStringContainsString("script.crossOrigin = 'anonymous'", $storefront);
		self::assertStringNotContainsString('$.getScript', $storefront);
		self::assertStringContainsString('integrity="{{ cdek_map_script_integrity }}"', $admin);
	}

	public function testCspReportEndpointIsRateLimitedAndDoesNotLogSensitiveInput(): void {
		$endpoint = $this->read('upload/csp-report.php');

		self::assertStringContainsString('CSP_REPORT_LIMIT_PER_MINUTE', $endpoint);
		self::assertStringContainsString('CSP_REPORT_MAX_BODY_BYTES', $endpoint);
		self::assertStringContainsString("in_array(\$contentType, array('application/csp-report', 'application/reports+json'), true)", $endpoint);
		self::assertStringContainsString("http_response_code(429)", $endpoint);
		self::assertStringNotContainsString('HTTP_COOKIE', $endpoint);
		self::assertStringNotContainsString('$_COOKIE', $endpoint);
		self::assertStringNotContainsString('$_POST', $endpoint);
		self::assertStringNotContainsString("file_put_contents(DIR_LOGS . 'csp.log', \$rawBody", $endpoint);
		self::assertStringContainsString("\$report['effectiveDirective']", $endpoint);
		self::assertStringContainsString("parse_url(\$value)", $endpoint);
	}

	public function testIncludeTemplateScriptsGetNonceFromTwigLoader(): void {
		[$baseDir, $route] = $this->prepareTwigFixtures('include');
		$templateName = $baseDir . $route . '/';

		file_put_contents($templateName . 'include.twig', '<script>console.log("from include");</script>');
		file_put_contents($templateName . 'main.twig', '{% include \'' . $route . '/include.twig\' %}<script>console.log("main");</script>');

		require_once $this->root . '/upload/system/library/template/twig.php';

		$template = new Template\Twig();
		$template->set('csp_nonce', 'csp-include-nonce');

		$output = $template->render($route . '/main');

		self::assertStringContainsString('<script nonce="csp-include-nonce">console.log("from include");</script>', $output);
		self::assertStringContainsString('<script nonce="csp-include-nonce">console.log("main");</script>', $output);
	}

	public function testExistingNonceIsNotDuplicatedInCspLoader(): void {
		[$baseDir, $route] = $this->prepareTwigFixtures('existing');
		$templateName = $baseDir . $route . '/';

		file_put_contents($templateName . 'include.twig', '<script nonce="from-template"></script>');
		file_put_contents($templateName . 'main.twig', '{% include \'' . $route . '/include.twig\' %}<script>console.log("main");</script>');

		require_once $this->root . '/upload/system/library/template/twig.php';

		$template = new Template\Twig();
		$template->set('csp_nonce', 'csp-no-dup');

		$output = $template->render($route . '/main');

		self::assertStringContainsString('nonce="from-template"', $output);
		self::assertStringNotContainsString('nonce="from-template" nonce=', $output);
		self::assertStringContainsString('<script nonce="csp-no-dup">console.log("main");</script>', $output);
	}

	public function testNonceRenderedByTwigAndRefreshesPerRender(): void {
		[$baseDir, $route] = $this->prepareTwigFixtures('refresh');
		$templateName = $baseDir . $route . '/';

		file_put_contents($templateName . 'include.twig', '<script>console.log("from include");</script>');
		file_put_contents($templateName . 'main.twig', '{% include \'' . $route . '/include.twig\' %}<script>console.log("main");</script>');

		require_once $this->root . '/upload/system/library/template/twig.php';

		$template = new Template\Twig();
		$template->set('csp_nonce', 'first-nonce');
		$first = $template->render($route . '/main');

		$template->set('csp_nonce', 'second-nonce');
		$second = $template->render($route . '/main');

		self::assertStringContainsString('nonce="first-nonce"', $first);
		self::assertStringNotContainsString('nonce="second-nonce"', $first);
		self::assertStringContainsString('nonce="second-nonce"', $second);
		self::assertStringNotContainsString('nonce="first-nonce"', $second);
	}

	public function testLoaderDoesNotMutateRenderedOutput(): void {
		$loader = $this->read('upload/system/engine/loader.php');
		$twig = $this->read('upload/system/library/template/twig.php');

		self::assertStringNotContainsString("'/<script\\b(?![^>]*\\bnonce=)/i'", $loader);
		self::assertStringContainsString('CspNonceTwigLoader', $twig);
		self::assertStringContainsString('getCacheKey', $twig);
		self::assertStringContainsString('#csp-nonce', $twig);
	}

	private function prepareTwigFixtures(string $mode): array {
		$basePath = defined('DIR_TEMPLATE') ? rtrim(DIR_TEMPLATE, '/\\') . '/' : sys_get_temp_dir() . '/sylora-csp-templates/';
		$fixturePath = $basePath . 'security/csp/' . $mode . '/';
		$route = 'security/csp/' . $mode;

		if (!defined('DIR_TEMPLATE')) {
			define('DIR_TEMPLATE', $basePath);
		}

		if (!is_dir($fixturePath)) {
			mkdir($fixturePath, 0777, true);
		}

		if (!defined('DIR_CACHE')) {
			define('DIR_CACHE', sys_get_temp_dir() . '/sylora-twig-test/');
		}

		if (!is_dir(DIR_CACHE . 'template')) {
			mkdir(DIR_CACHE . 'template', 0777, true);
		}

		return array($basePath, $route);
	}

	private function read(string $relativePath): string {
		$content = file_get_contents($this->root . '/' . $relativePath);

		self::assertIsString($content, $relativePath);

		return $content;
	}
}
