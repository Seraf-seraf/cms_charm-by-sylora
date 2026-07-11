<?php
$stylesheet = file_get_contents(__DIR__ . '/../upload/catalog/view/theme/charm_by_sylora/stylesheet/stylesheet.css');
$layout_containers = array('site-header__inner', 'sylora-hero__grid', 'sylora-benefits__grid', 'sylora-about-teaser');

foreach ($layout_containers as $class) {
	foreach (array('before', 'after') as $pseudo) {
		$selector = '.' . $class . '::' . $pseudo;

		if (strpos($stylesheet, $selector) === false) {
			fwrite(STDERR, 'Bootstrap container pseudo-element is not reset: ' . $selector . PHP_EOL);
			exit(1);
		}
	}
}

$benefits = file_get_contents(__DIR__ . '/../upload/catalog/view/theme/charm_by_sylora/template/common/home.twig');

if (substr_count($benefits, '<div><strong>') < 4) {
	fwrite(STDERR, 'Benefits grid fixture is incomplete.' . PHP_EOL);
	exit(1);
}

echo 'Layout grid tests passed.' . PHP_EOL;
