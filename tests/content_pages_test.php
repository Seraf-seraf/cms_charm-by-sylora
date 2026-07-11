<?php
$migration = file_get_contents(__DIR__ . '/../database/migrations/2026_07_12_000007_content_pages.php');
$required_slugs = array('delivery-payment', 'returns', 'jewelry-care', 'sizes-materials', 'gift-packaging', 'privacy-policy', 'offer');

foreach ($required_slugs as $slug) {
	if (strpos($migration, "'slug' => '" . $slug . "'") === false) {
		fwrite(STDERR, 'Missing content page: ' . $slug . PHP_EOL);
		exit(1);
	}
}

if (strpos($migration, "status = 0") === false || strpos($migration, "description = ''") === false) {
	fwrite(STDERR, 'New empty CMS pages must stay unpublished.' . PHP_EOL);
	exit(1);
}

if (strpos($migration, 'ON DUPLICATE KEY UPDATE') !== false || strpos($migration, 'UPDATE information_description') !== false) {
	fwrite(STDERR, 'Migration must not overwrite CMS-managed content.' . PHP_EOL);
	exit(1);
}

$footer = file_get_contents(__DIR__ . '/../upload/catalog/controller/common/footer.php');

if (strpos($footer, "getInformationUrl('delivery-payment')") === false || strpos($footer, "information_id=6") !== false) {
	fwrite(STDERR, 'Footer content URLs must be resolved by SEO keyword.' . PHP_EOL);
	exit(1);
}

$template = file_get_contents(__DIR__ . '/../upload/catalog/view/theme/charm_by_sylora/template/information/information.twig');

if (strpos($template, 'class="content-page"') === false || strpos($template, '{{ description }}') === false) {
	fwrite(STDERR, 'Modern content page template is missing.' . PHP_EOL);
	exit(1);
}

if (strpos($template, 'Остались вопросы?') !== false || strpos($template, 'Помощь покупателю') !== false) {
	fwrite(STDERR, 'Content template must not inject editable page copy.' . PHP_EOL);
	exit(1);
}

echo 'Content page tests passed.' . PHP_EOL;
