<?php
// Runs database/migrations in filename order and records completed files in the migrations table.

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, 'This script must be run from CLI.' . PHP_EOL);
	exit(1);
}

require_once __DIR__ . '/../upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root_dir = dirname(__DIR__);
$migration_dir = __DIR__ . '/migrations';
$migration_table = 'migrations';
$bootstrap_migration = '0000_00_00_000000_create_migrations_table.sql';

try {
	$mysqli = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
	$mysqli->set_charset('utf8mb4');

	$migrations = discoverMigrations($migration_dir);

	if (!$migrations) {
		echo 'No migration files found.' . PHP_EOL;
		exit(0);
	}

	if (!isset($migrations[$bootstrap_migration])) {
		throw new RuntimeException("Bootstrap migration '" . $bootstrap_migration . "' was not found.");
	}

	if (!tableExists($mysqli, $migration_table)) {
		runSqlMigration($mysqli, $migrations[$bootstrap_migration]);
		recordMigration($mysqli, $migration_table, $bootstrap_migration, 1);
		echo 'Migrated: ' . $bootstrap_migration . PHP_EOL;
	}

	$applied = getAppliedMigrations($mysqli, $migration_table);
	$pending = array_diff_key($migrations, $applied);

	if (!$pending) {
		echo 'Nothing to migrate.' . PHP_EOL;
		exit(0);
	}

	$batch = getNextBatch($mysqli, $migration_table);

	foreach ($pending as $migration => $path) {
		if ($migration === $bootstrap_migration && isset($applied[$migration])) {
			continue;
		}

		runMigration($mysqli, $path, $root_dir);
		recordMigration($mysqli, $migration_table, $migration, $batch);
		echo 'Migrated: ' . $migration . PHP_EOL;
	}

	echo 'Migrations complete.' . PHP_EOL;
} catch (Throwable $exception) {
	fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
	exit(1);
}

function discoverMigrations(string $migration_dir): array {
	$files = glob($migration_dir . '/*.{php,sql}', GLOB_BRACE);
	$migrations = array();

	foreach ($files as $file) {
		if (is_file($file)) {
			$migrations[basename($file)] = $file;
		}
	}

	ksort($migrations, SORT_STRING);

	return $migrations;
}

function tableExists(mysqli $mysqli, string $table): bool {
	$table = $mysqli->real_escape_string($table);
	$result = $mysqli->query("SHOW TABLES LIKE '" . $table . "'");

	return $result->num_rows > 0;
}

function getAppliedMigrations(mysqli $mysqli, string $table): array {
	$escaped_table = escapeIdentifier($table);
	$result = $mysqli->query("SELECT migration FROM `" . $escaped_table . "` ORDER BY id ASC");
	$applied = array();

	while ($row = $result->fetch_assoc()) {
		$applied[$row['migration']] = true;
	}

	return $applied;
}

function getNextBatch(mysqli $mysqli, string $table): int {
	$escaped_table = escapeIdentifier($table);
	$result = $mysqli->query("SELECT MAX(batch) AS batch FROM `" . $escaped_table . "`");
	$row = $result->fetch_assoc();

	return ((int)$row['batch']) + 1;
}

function runMigration(mysqli $mysqli, string $path, string $root_dir): void {
	$extension = pathinfo($path, PATHINFO_EXTENSION);

	if ($extension === 'sql') {
		runSqlMigration($mysqli, $path);
		return;
	}

	if ($extension === 'php') {
		runPhpMigration($path, $root_dir);
		return;
	}

	throw new RuntimeException("Unsupported migration type: " . basename($path));
}

function runSqlMigration(mysqli $mysqli, string $path): void {
	$sql = file_get_contents($path);

	if ($sql === false) {
		throw new RuntimeException("Unable to read SQL migration: " . basename($path));
	}

	if (!$mysqli->multi_query($sql)) {
		throw new RuntimeException($mysqli->error);
	}

	do {
		if ($result = $mysqli->store_result()) {
			$result->free();
		}
	} while ($mysqli->more_results() && $mysqli->next_result());

	if ($mysqli->errno) {
		throw new RuntimeException($mysqli->error);
	}
}

function runPhpMigration(string $path, string $root_dir): void {
	$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
	$output = array();
	$exit_code = 0;

	$previous_cwd = getcwd();
	chdir($root_dir);
	exec($command, $output, $exit_code);
	chdir($previous_cwd);

	if ($exit_code !== 0) {
		throw new RuntimeException(basename($path) . ' failed with exit code ' . $exit_code . PHP_EOL . implode(PHP_EOL, $output));
	}
}

function recordMigration(mysqli $mysqli, string $table, string $migration, int $batch): void {
	$escaped_table = escapeIdentifier($table);
	$escaped_migration = $mysqli->real_escape_string($migration);

	$mysqli->query("INSERT INTO `" . $escaped_table . "` SET migration = '" . $escaped_migration . "', batch = '" . (int)$batch . "'");
}

function escapeIdentifier(string $identifier): string {
	return str_replace('`', '``', $identifier);
}
