<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/support/TestBootstrap.php';
require_once __DIR__ . '/support/hero_product_admin_fixture.php';

final class HeroProductFunctionalTest extends TestCase {
	private const KEY = HeroProductAdminFixture::KEY;
	private const LIMIT = 'theme_charm_by_sylora_product_limit';
	private ?HeroProductAdminFixture $fixture = null;

	protected function setUp(): void {
		$this->fixture = new HeroProductAdminFixture(TestBootstrap::shared());
	}

	protected function tearDown(): void {
		$this->fixture?->close();
	}

	public function testAdminSearchSelectionPreservationAndClear(): void {
		$fixture = $this->fixture;
		$editor = $fixture->login();
		$first = $fixture->product('SYLORA-TEST-001');
		$second = $fixture->product('SYLORA-TEST-002');
		$store = HeroProductAdminFixture::SECOND_STORE_ID;
		$fixture->database->execute_query('INSERT INTO product_to_store (product_id, store_id) VALUES (?, ?)', array($second['id'], $store));

		$initial = $this->form($editor->theme()['body']);
		self::assertSame('0', $initial[self::KEY] ?? null);
		self::assertSame(array(), $this->searchResults($editor->search('')));
		self::assertSame(array(), $this->searchResults($editor->search('несуществующее украшение')));
		self::assertSame(array(), $this->searchResults($editor->search('%')));
		self::assertSame(array(), $this->searchResults($editor->search('рассвет', $store)));
		$results = $this->searchResults($editor->search('рассвет'));
		self::assertSame(array($first['id']), array_column($results, 'product_id'));
		self::assertSame($first['name'], $results[0]['name']);
		self::assertStringContainsString('earrings-portrait', $results[0]['thumb']);
		self::assertNotFalse(@getimagesize($results[0]['thumb']));
		self::assertSame(array($second['id']), array_column($this->searchResults($editor->search('Лесная', $store)), 'product_id'));

		self::assertSame(302, $editor->theme(0, array_replace($initial, array(self::KEY => (string)$first['id'])))['status']);
		self::assertSame((string)$first['id'], $fixture->setting(self::KEY));
		$selected = $editor->theme();
		self::assertSame((string)$first['id'], $this->form($selected['body'])[self::KEY]);
		self::assertStringContainsString($first['name'], $selected['body']);

		$secondForm = $this->form($editor->theme($store)['body']);
		self::assertSame('0', $secondForm[self::KEY]);
		self::assertSame(302, $editor->theme($store, array_replace($secondForm, array(self::KEY => (string)$second['id'])))['status']);
		self::assertSame((string)$first['id'], $fixture->setting(self::KEY));
		self::assertSame((string)$second['id'], $fixture->setting(self::KEY, $store));

		self::assertSame(302, $editor->theme(0, array_replace($initial, array(self::KEY => (string)$second['id'])))['status']);
		self::assertSame((string)$second['id'], $fixture->setting(self::KEY));
		unset($initial[self::KEY]);
		self::assertSame(302, $editor->theme(0, array_replace($initial, array(self::LIMIT => '7')))['status']);
		self::assertSame((string)$second['id'], $fixture->setting(self::KEY));
		self::assertSame('7', $fixture->setting(self::LIMIT));

		$fixture->database->execute_query('DELETE FROM product_to_store WHERE product_id = ? AND store_id = 0', array($second['id']));
		try {
			$stale = $editor->theme();
			self::assertStringContainsString('недоступен', $stale['body']);
			$form = $this->form($stale['body']);
			self::assertSame((string)$second['id'], $form[self::KEY]);
			self::assertSame(302, $editor->theme(0, array_replace($form, array(self::LIMIT => '8')))['status']);
			self::assertSame((string)$second['id'], $fixture->setting(self::KEY));
			self::assertSame('8', $fixture->setting(self::LIMIT));
			self::assertSame(302, $editor->theme(0, array_replace($form, array(self::KEY => '0')))['status']);
			self::assertSame('0', $fixture->setting(self::KEY));
			self::assertSame('0', $this->form($editor->theme()['body'])[self::KEY]);
			self::assertSame((string)$second['id'], $fixture->setting(self::KEY, $store));
		} finally {
			$fixture->database->execute_query('INSERT INTO product_to_store (product_id, store_id) VALUES (?, 0)', array($second['id']));
		}
	}

	public function testAdminRejectsInvalidSelectionAndEnforcesPermissions(): void {
		$fixture = $this->fixture;
		$editor = $fixture->login();
		$reader = $fixture->login('hero-reader');
		$denied = $fixture->login('hero-no-access');
		$initial = $this->form($editor->theme()['body']);
		$first = $fixture->product('SYLORA-TEST-001');
		$oldLimit = $fixture->setting(self::LIMIT);
		$post = array_replace($initial, array(self::KEY => (string)$first['id'], self::LIMIT => '9'));

		self::assertSame(array($first['id']), array_column($this->searchResults($reader->search('рассвет')), 'product_id'));
		self::assertStringNotContainsString('application/json', $denied->search('рассвет')['contentType']);
		self::assertStringNotContainsString('application/json', $editor->search('рассвет', 0, false)['contentType']);
		self::assertSame(200, $reader->theme(0, $post)['status']);
		self::assertStringContainsString('alert-danger', $reader->theme(0, $post)['body']);
		self::assertNull($fixture->setting(self::KEY));
		self::assertSame($oldLimit, $fixture->setting(self::LIMIT));
		self::assertSame(400, $editor->theme(999, $post)['status']);
		self::assertSame(400, $editor->search('рассвет', 999)['status']);

		foreach (array($first['id'] . '.5', '-1', '999999', array($first['id'])) as $invalid) {
			$response = $editor->theme(0, array_replace($post, array(self::KEY => $invalid)));
			self::assertSame(200, $response['status']);
			self::assertStringContainsString('text-danger', $response['body']);
			self::assertNull($fixture->setting(self::KEY));
			self::assertSame($oldLimit, $fixture->setting(self::LIMIT));
		}

		$product = $fixture->database->execute_query('SELECT status, date_available, image, quantity FROM product WHERE product_id = ?', array($first['id']))->fetch_assoc();
		try {
			foreach (array(
				array(0, $product['date_available'], $product['image']),
				array(1, '2099-01-01', $product['image']),
				array(1, $product['date_available'], 'catalog/hero-no-such-image.png'),
			) as $fields) {
				$fixture->database->execute_query('UPDATE product SET status = ?, date_available = ?, image = ? WHERE product_id = ?', array_merge($fields, array($first['id'])));
				self::assertSame(array(), $this->searchResults($editor->search('рассвет')));
				self::assertSame(200, $editor->theme(0, $post)['status']);
				self::assertNull($fixture->setting(self::KEY));
			}
			$fixture->database->execute_query('UPDATE product SET image = ?, quantity = 0 WHERE product_id = ?', array($first['image'], $first['id']));
			self::assertSame(302, $editor->theme(0, $post)['status']);
			self::assertSame((string)$first['id'], $fixture->setting(self::KEY));
		} finally {
			$fixture->database->execute_query('UPDATE product SET status = ?, date_available = ?, image = ?, quantity = ? WHERE product_id = ?', array_merge(array_values($product), array($first['id'])));
		}
	}

	/** @return array<string, string> */
	private function form(string $body): array {
		$document = Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR, 'UTF-8');
		$fields = array();
		foreach ($document->querySelectorAll('#form-theme [name]') as $field) {
			$fields[$field->getAttribute('name')] = $field->localName === 'select'
				? $field->querySelector('option[selected]')?->getAttribute('value') ?? $field->querySelector('option')->getAttribute('value')
				: $field->getAttribute('value');
		}
		self::assertArrayHasKey(self::LIMIT, $fields, 'Expected the OpenCart theme form');

		return $fields;
	}

	private function searchResults(array $response): array {
		self::assertSame(200, $response['status']);
		self::assertStringContainsString('application/json', $response['contentType']);

		return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
	}
}
