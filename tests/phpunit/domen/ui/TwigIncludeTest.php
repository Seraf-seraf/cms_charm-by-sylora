<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TwigIncludeTest extends TestCase {
	public function testThemeTemplateCanIncludeSharedPartial(): void {
		$root = dirname(__DIR__, 4);

		if (!defined('DIR_TEMPLATE')) {
			define('DIR_TEMPLATE', $root . '/upload/catalog/view/theme/');
		}

		if (!defined('DIR_CACHE')) {
			define('DIR_CACHE', sys_get_temp_dir() . '/sylora-twig-test/');
		}

		if (!is_dir(DIR_CACHE . 'template')) {
			mkdir(DIR_CACHE . 'template', 0777, true);
		}

		require_once $root . '/upload/system/library/template/twig.php';

		$template = new Template\Twig();
		$template->set('product', array(
			'product_id' => 42,
			'image' => array('src' => '/image/product.webp', 'sources' => array(), 'width' => 320, 'height' => 400),
			'hover_image' => array('src' => '', 'sources' => array(), 'width' => 320, 'height' => 400),
			'name' => 'Тестовое украшение',
			'description' => 'Описание',
			'price' => '2 000 ₽',
			'special' => false,
			'tax' => false,
			'stock' => 'В наличии',
			'stock_class' => 'is-in',
			'badge' => '',
			'badge_class' => '',
			'rating' => false,
			'can_buy' => true,
			'minimum' => 1,
			'href' => '/product-42'
		));
		$template->set('button_cart', 'В корзину');
		$template->set('button_wishlist', 'В избранное');
		$template->set('button_details', 'Подробнее');
		$template->set('button_quick_view', 'Быстрый просмотр');
		$template->set('button_close', 'Закрыть');
		$template->set('text_out_of_stock', 'Нет в наличии');
		$template->set('text_additional_image', '%s — дополнительное фото');
		$template->set('text_tax', 'Без налога:');
		$output = $template->render(
			'charm_by_sylora/template/product/include_contract',
			"{% set heading_level = 'h2' %}{% set enable_quick_view = false %}{% include 'product/product_card.twig' %}"
		);

		self::assertStringContainsString('<article class="product-layout product-grid catalog-card">', $output);
		self::assertStringContainsString('Тестовое украшение', $output);
		self::assertStringContainsString('href="/product-42"', $output);
		self::assertStringNotContainsString('js-quick-view-open', $output);
	}
}
