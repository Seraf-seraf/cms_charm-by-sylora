<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SeoMetadataContractTest extends TestCase {
	public function testDocumentSupportsExplicitRobotsDirective(): void {
		$document = file_get_contents(dirname(__DIR__, 4) . '/upload/system/library/document.php');
		$header = file_get_contents(dirname(__DIR__, 4) . '/upload/catalog/controller/common/header.php');

		self::assertIsString($document);
		self::assertIsString($header);
		self::assertStringContainsString('public function setRobots($robots)', $document);
		self::assertStringContainsString('$this->document->getRobots() ?: $this->getRobotsDirective()', $header);
	}

	public function testNotFoundControllersSetNoindex(): void {
		$root = dirname(__DIR__, 4) . '/upload/catalog/controller/';
		$controllers = array(
			'error/not_found.php',
			'information/information.php',
			'product/category.php',
			'product/manufacturer.php',
			'product/product.php'
		);

		foreach ($controllers as $path) {
			$controller = file_get_contents($root . $path);

			self::assertIsString($controller);
			self::assertStringContainsString("setRobots('noindex, nofollow')", $controller, $path);
		}
	}

	public function testEmptyCategoriesUseSharedProductVisibilityCheck(): void {
		$categoryController = file_get_contents(dirname(__DIR__, 4) . '/upload/catalog/controller/product/category.php');
		$categoryModel = file_get_contents(dirname(__DIR__, 4) . '/upload/catalog/model/catalog/category.php');

		self::assertIsString($categoryController);
		self::assertIsString($categoryModel);
		self::assertStringContainsString('public function hasActiveProducts($category_id)', $categoryModel);
		self::assertStringContainsString("if (!\$this->model_catalog_category->hasActiveProducts(\$category_id))", $categoryController);
		self::assertStringContainsString("setRobots('noindex, follow')", $categoryController);
	}

	public function testOnlineStoreSchemaContainsVerifiedReturnPolicy(): void {
		$header = file_get_contents(dirname(__DIR__, 4) . '/upload/catalog/controller/common/header.php');
		$product = file_get_contents(dirname(__DIR__, 4) . '/upload/catalog/controller/product/product.php');
		$contact = file_get_contents(dirname(__DIR__, 4) . '/upload/catalog/controller/information/contact.php');

		self::assertIsString($header);
		self::assertIsString($product);
		self::assertIsString($contact);
		self::assertStringContainsString("'@type' => 'OnlineStore'", $header);
		self::assertStringContainsString("'merchantReturnDays' => 7", $header);
		self::assertStringContainsString("'customerRemorseReturnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility'", $header);
		self::assertStringContainsString("'itemDefectReturnFees' => 'https://schema.org/FreeReturn'", $header);
		self::assertStringContainsString("'/returns#policy'", $product);
		self::assertStringNotContainsString("'hasShippingService'", $header);
		self::assertStringNotContainsString("'@type' => 'Organization'", $contact);
		self::assertStringContainsString("'/#organization'", $contact);
	}
}
