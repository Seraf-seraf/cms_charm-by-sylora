<?php

declare(strict_types=1);

require_once __DIR__ . '/../../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');
$table = str_replace('`', '``', DB_PREFIX . 'review');

$columns = array(
	'publication_consent' => "TINYINT(1) NOT NULL DEFAULT '0'",
	'publication_consent_version' => "VARCHAR(32) NOT NULL DEFAULT ''",
	'publication_consent_at' => 'DATETIME NULL DEFAULT NULL',
	'publication_consent_ip' => "VARCHAR(45) NOT NULL DEFAULT ''",
	'publication_consent_fingerprint' => "CHAR(64) NOT NULL DEFAULT ''",
	'publication_consent_withdrawn_at' => 'DATETIME NULL DEFAULT NULL',
);

try {
	foreach ($columns as $column => $definition) {
		$escapedColumn = $db->real_escape_string($column);
		$result = $db->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $escapedColumn . "'");

		if ($result->num_rows === 0) {
			$db->query("ALTER TABLE `" . $table . "` ADD `" . $column . "` " . $definition);
		}
	}

	echo "Review publication consent fields are ready.\n";
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
