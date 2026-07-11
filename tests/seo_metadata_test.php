<?php
require_once __DIR__ . '/../upload/system/helper/utf8.php';
require_once __DIR__ . '/../upload/system/library/seo.php';

class SeoTestConfig {
	private $values;

	public function __construct($values) {
		$this->values = $values;
	}

	public function get($key) {
		return isset($this->values[$key]) ? $this->values[$key] : '';
	}
}

class SeoTestRegistry {
	private $config;

	public function __construct($config) {
		$this->config = $config;
	}

	public function get($key) {
		return $key === 'config' ? $this->config : null;
	}
}

function seoAssert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, $message . PHP_EOL);
		exit(1);
	}
}

$config = new SeoTestConfig(array(
	'config_name' => 'Charm by Sylora',
	'config_seo_category_title_template' => '{name} / {store}'
));
$seo = new Seo(new SeoTestRegistry($config));

seoAssert($seo->title('Заполненный SEO Title', 'Серьги', 'category') === 'Заполненный SEO Title', 'Entity title must have priority.');
seoAssert($seo->title('', 'Серьги', 'category') === 'Серьги / Charm by Sylora', 'Configured title template was not applied.');
seoAssert(strpos($seo->description('', '', 'Серьги', 'category'), 'Серьги') !== false, 'Fallback description must contain the entity name.');
seoAssert($seo->description('', '<p>Описание <strong>категории</strong></p>', 'Серьги', 'category') === 'Описание категории', 'Entity description must be cleaned and reused.');

$header = file_get_contents(__DIR__ . '/../upload/catalog/controller/common/header.php');
seoAssert(strpos($header, "'LocalBusiness'") !== false, 'LocalBusiness schema support is missing.');
seoAssert(strpos($header, "'PostalAddress'") !== false, 'PostalAddress schema support is missing.');
seoAssert(strpos($header, "'GeoCoordinates'") !== false, 'GeoCoordinates schema support is missing.');

echo 'SEO metadata tests passed.' . PHP_EOL;
