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
			"script-src 'self' 'nonce-",
		) as $directive) {
			self::assertStringContainsString($directive, $startup);
		}
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
