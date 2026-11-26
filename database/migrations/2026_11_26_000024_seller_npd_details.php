<?php

declare(strict_types=1);

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');

$table = str_replace('`', '``', DB_PREFIX . 'setting');
$sellerName = 'Кравчук Серафим Сергеевич';
$taxId = '771377623413';
$settings = array(
	'config_sylora_legal_name' => 'Самозанятый ' . $sellerName,
	'config_sylora_tax_id' => $taxId,
	'config_sylora_registration_id' => '',
	'config_sylora_legal_info' => "Самозанятый " . $sellerName . "\nИНН " . $taxId,
);

$db->begin_transaction();

try {
	$update = $db->prepare("UPDATE `" . $table . "` SET value = ?, serialized = 0 WHERE store_id = 0 AND `key` = ?");
	$insert = $db->prepare(
		"INSERT INTO `" . $table . "` (store_id, code, `key`, value, serialized) " .
		"SELECT 0, 'config', ?, ?, 0 FROM DUAL " .
		"WHERE NOT EXISTS (SELECT 1 FROM `" . $table . "` WHERE store_id = 0 AND `key` = ?)"
	);

	foreach ($settings as $key => $value) {
		$update->bind_param('ss', $value, $key);
		$update->execute();
		$insert->bind_param('sss', $key, $value, $key);
		$insert->execute();
	}

	$db->commit();
	echo 'NPD seller details are ready.' . PHP_EOL;
} catch (Throwable $exception) {
	$db->rollback();
	throw $exception;
}
