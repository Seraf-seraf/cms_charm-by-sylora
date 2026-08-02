<?php

declare(strict_types=1);

final class Catalog_schema {
	/**
	 * @param array<int, array<string, mixed>> $breadcrumbs
	 * @param array<int, array<string, mixed>> $products
	 */
	public function build(string $name, string $url, array $breadcrumbs, array $products, int $page, int $limit): string {
		$url = $this->normalizeUrl($url);
		$list_items = array();
		$position = (max(1, $page) - 1) * max(1, $limit);

		foreach ($products as $product) {
			if (empty($product['name']) || empty($product['href'])) {
				continue;
			}

			$item = array(
				'@type' => 'Product',
				'name' => html_entity_decode((string)$product['name'], ENT_QUOTES, 'UTF-8'),
				'url' => $this->normalizeUrl((string)$product['href'])
			);

			if (isset($product['image']) && is_array($product['image']) && !empty($product['image']['src'])) {
				$item['image'] = $this->normalizeUrl((string)$product['image']['src']);
			} elseif (!empty($product['thumb'])) {
				$item['image'] = $this->normalizeUrl((string)$product['thumb']);
			}

			$list_items[] = array(
				'@type' => 'ListItem',
				'position' => ++$position,
				'url' => $item['url'],
				'item' => $item
			);
		}

		$item_list_id = $url . '#item-list';
		$graph = array(
			array(
				'@type' => 'CollectionPage',
				'@id' => $url . '#collection',
				'url' => $url,
				'name' => html_entity_decode($name, ENT_QUOTES, 'UTF-8'),
				'mainEntity' => array('@id' => $item_list_id)
			),
			array(
				'@type' => 'ItemList',
				'@id' => $item_list_id,
				'numberOfItems' => count($list_items),
				'itemListElement' => $list_items
			)
		);

		$breadcrumb_items = $this->buildBreadcrumbs($breadcrumbs);

		if ($breadcrumb_items) {
			$breadcrumb_id = $url . '#breadcrumb';
			$graph[0]['breadcrumb'] = array('@id' => $breadcrumb_id);
			$graph[] = array(
				'@type' => 'BreadcrumbList',
				'@id' => $breadcrumb_id,
				'itemListElement' => $breadcrumb_items
			);
		}

		$schema = json_encode(array(
			'@context' => 'https://schema.org',
			'@graph' => $graph
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($schema) ? $schema : '';
	}

	/**
	 * @param array<int, array<string, mixed>> $breadcrumbs
	 * @return array<int, array<string, mixed>>
	 */
	private function buildBreadcrumbs(array $breadcrumbs): array {
		$items = array();

		foreach ($breadcrumbs as $breadcrumb) {
			if (empty($breadcrumb['text']) || empty($breadcrumb['href'])) {
				continue;
			}

			$items[] = array(
				'@type' => 'ListItem',
				'position' => count($items) + 1,
				'name' => trim(strip_tags(html_entity_decode((string)$breadcrumb['text'], ENT_QUOTES, 'UTF-8'))),
				'item' => $this->normalizeUrl((string)$breadcrumb['href'])
			);
		}

		return $items;
	}

	private function normalizeUrl(string $url): string {
		return html_entity_decode($url, ENT_QUOTES, 'UTF-8');
	}
}
