<?php

declare(strict_types=1);

final class HeroProductAdminFixture {
	public const ROUTE = 'extension/theme/charm_by_sylora';
	public const CODE = 'theme_charm_by_sylora';
	public const KEY = self::CODE . '_hero_product_id';
	public const SECOND_STORE_ID = 902;
	private const PASSWORD = 'synthetic-hero-fixture';
	private const USERS = array('hero-editor' => 901, 'hero-reader' => 902, 'hero-no-access' => 903);

	public readonly mysqli $database;
	public readonly string $baseUrl;
	private readonly string $adminPath;
	private readonly string $adminUrl;
	/** @var resource */
	private $server;
	private array $originalSettings;

	public function __construct(TestBootstrap $bootstrap) {
		$this->baseUrl = dirname($bootstrap->getRouteUrl('common/home'));
		$temporaryRoot = dirname(rtrim(DIR_STORAGE, '/'));
		$this->adminPath = $temporaryRoot . '/hero-admin';
		$adminSource = $bootstrap->getRoot() . '/upload/admin/';
		if (!mkdir($this->adminPath, 0700) && !is_dir($this->adminPath)) {
			throw new RuntimeException('Не удалось создать временную админку OpenCart.');
		}
		symlink($adminSource . 'view', $this->adminPath . '/view');
		$entry = <<<'ENTRY'
<?php
require dirname(__DIR__) . '/hero-admin-config.php';
require DIR_APPLICATION . 'index.php';
ENTRY;
		file_put_contents($this->adminPath . '/index.php', $entry);
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		if ($socket === false) {
			throw new RuntimeException('Не удалось зарезервировать порт для тестовой админки.');
		}
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$this->adminUrl = 'http://' . $address;
		$defines = array(
			'HTTP_SERVER' => $this->adminUrl . '/',
			'HTTPS_SERVER' => $this->adminUrl . '/',
			'HTTP_CATALOG' => $this->baseUrl . '/',
			'HTTPS_CATALOG' => $this->baseUrl . '/',
			'DIR_APPLICATION' => $adminSource,
			'DIR_CATALOG' => DIR_APPLICATION,
			'DIR_TEMPLATE' => $adminSource . 'view/template/',
			'DIR_LANGUAGE' => $adminSource . 'language/',
		);
		foreach (array('DIR_SYSTEM', 'DIR_IMAGE', 'DIR_STORAGE', 'DIR_CONFIG', 'DIR_CACHE', 'DIR_DOWNLOAD', 'DIR_LOGS', 'DIR_MODIFICATION', 'DIR_SESSION', 'DIR_UPLOAD', 'DB_DRIVER', 'DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PORT', 'DB_PREFIX') as $constant) {
			$defines[$constant] = constant($constant);
		}

		$config = "<?php\n";
		foreach ($defines as $name => $value) {
			$config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
		}
		file_put_contents($temporaryRoot . '/hero-admin-config.php', $config);

		$this->database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
		$this->database->set_charset('utf8mb4');
		$this->originalSettings = $this->database->query("SELECT * FROM setting WHERE store_id = 0 AND code = 'theme_charm_by_sylora'")->fetch_all(MYSQLI_ASSOC);
		$this->database->execute_query('INSERT INTO store (store_id, name, url, `ssl`) VALUES (?, ?, ?, ?)', array(self::SECOND_STORE_ID, 'Второй тестовый магазин', 'http://store-two.example.invalid/', 'https://store-two.example.invalid/'));
		$this->database->execute_query(
			'INSERT INTO setting (store_id, code, `key`, value, serialized) SELECT ?, code, `key`, value, serialized FROM setting WHERE store_id = 0 AND code = ? AND `key` <> ?',
			array(self::SECOND_STORE_ID, self::CODE, self::KEY)
		);
		$this->database->execute_query("INSERT INTO setting (store_id, code, `key`, value, serialized) VALUES (0, 'config', 'developer_sass', '1', 0)");
		foreach (self::USERS as $name => $id) {
			$permissions = array(
				'access' => $name === 'hero-no-access' ? array() : array(self::ROUTE),
				'modify' => $name === 'hero-editor' ? array(self::ROUTE) : array(),
			);
			$this->database->execute_query('INSERT INTO user_group (user_group_id, name, permission) VALUES (?, ?, ?)', array($id, $name, json_encode($permissions, JSON_THROW_ON_ERROR)));
			$this->database->execute_query("INSERT INTO user (user_group_id, username, password, salt, firstname, lastname, email, image, code, ip, status, date_added) VALUES (?, ?, ?, '', 'Тестовый', 'Сотрудник', '', '', '', '', 1, NOW())", array($id, $name, md5(self::PASSWORD)));
		}

		$logFile = $temporaryRoot . '/hero-admin.log';
		$command = array(
			PHP_BINARY,
			'-d', 'allow_url_fopen=0',
			'-d', 'disable_functions=mail,curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect',
			'-S', $address,
			'-t', $this->adminPath,
		);
		$server = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('file', $logFile, 'a'), 2 => array('file', $logFile, 'a')), $pipes, $this->adminPath);
		if (!is_resource($server)) {
			throw new RuntimeException('Не удалось запустить тестовую админку.');
		}
		fclose($pipes[0]);
		$this->server = $server;
		$this->waitForAdmin($address);
	}

	private function waitForAdmin(string $address): void {
		$deadline = microtime(true) + 5;
		do {
			$connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
			if ($connection !== false) {
				fclose($connection);
				return;
			}
			usleep(20_000);
		} while (microtime(true) < $deadline);

		proc_terminate($this->server);
		proc_close($this->server);
		throw new RuntimeException('Тестовый PHP-сервер админки не ответил на loopback.');
	}

	public function close(): void {
		proc_terminate($this->server);
		proc_close($this->server);
		$this->database->query("DELETE FROM setting WHERE store_id IN (0, " . self::SECOND_STORE_ID . ") AND code = 'theme_charm_by_sylora'");
		foreach ($this->originalSettings as $setting) {
			$this->database->execute_query('INSERT INTO setting (setting_id, store_id, code, `key`, value, serialized) VALUES (?, ?, ?, ?, ?, ?)', array_values($setting));
		}
		$this->database->query('DELETE FROM product_to_store WHERE store_id = ' . self::SECOND_STORE_ID);
		$this->database->query('DELETE FROM store WHERE store_id = ' . self::SECOND_STORE_ID);
		$this->database->query("DELETE FROM setting WHERE code = 'config' AND `key` = 'developer_sass' AND store_id = 0");
		$this->database->query('DELETE FROM user WHERE user_group_id IN (901, 902, 903)');
		$this->database->query('DELETE FROM user_group WHERE user_group_id IN (901, 902, 903)');
		$this->database->close();
		unlink($this->adminPath . '/index.php');
		unlink($this->adminPath . '/view');
		rmdir($this->adminPath);
		unlink(dirname($this->adminPath) . '/hero-admin-config.php');
	}

	/** @return array{id: int, name: string, image: string} */
	public function product(string $model): array {
		$row = $this->database->execute_query('SELECT p.product_id, pd.name, p.image FROM product p JOIN product_description pd USING (product_id) WHERE p.model = ? AND pd.language_id = 1', array($model))->fetch_assoc();
		if (!$row) {
			throw new RuntimeException('Синтетический товар не найден: ' . $model);
		}

		return array('id' => (int)$row['product_id'], 'name' => $row['name'], 'image' => $row['image']);
	}

	public function setting(string $key, int $storeId = 0): ?string {
		$row = $this->database->execute_query('SELECT value FROM setting WHERE store_id = ? AND code = ? AND `key` = ?', array($storeId, self::CODE, $key))->fetch_assoc();

		return $row['value'] ?? null;
	}

	public function login(string $username = 'hero-editor'): HeroProductAdminSession {
		return new HeroProductAdminSession($this->adminUrl . '/index.php', $username, self::PASSWORD);
	}
}

final class HeroProductAdminSession {
	private readonly CurlHandle $client;
	private readonly string $token;

	public function __construct(private readonly string $url, string $username, string $password) {
		$this->client = curl_init();
		curl_setopt($this->client, CURLOPT_COOKIEFILE, '');
		$login = $this->request('common/login', array(), array('username' => $username, 'password' => $password));
		parse_str((string)parse_url($login['redirect'], PHP_URL_QUERY), $query);
		if ($login['status'] !== 302 || !isset($query['user_token'])) {
			throw new RuntimeException('Не удалось войти в тестовую админку OpenCart.');
		}

		$this->token = $query['user_token'];
	}

	/** @return array{status: int, redirect: string, contentType: string, body: string} */
	public function theme(int $storeId = 0, ?array $post = null): array {
		return $this->request(HeroProductAdminFixture::ROUTE, array('store_id' => $storeId, 'user_token' => $this->token), $post);
	}

	/** @return array{status: int, redirect: string, contentType: string, body: string} */
	public function search(string $query, int $storeId = 0, bool $withToken = true): array {
		return $this->request(HeroProductAdminFixture::ROUTE . '/heroProductAutocomplete', array(
			'store_id' => $storeId,
			'filter_name' => $query,
			'user_token' => $withToken ? $this->token : '',
		));
	}

	/** @return array{status: int, redirect: string, contentType: string, body: string} */
	private function request(string $route, array $query = array(), ?array $post = null): array {
		curl_setopt_array($this->client, array(
			CURLOPT_URL => $this->url . '?' . http_build_query(array('route' => $route) + $query),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
		));
		if ($post === null) {
			curl_setopt($this->client, CURLOPT_HTTPGET, true);
		} else {
			curl_setopt($this->client, CURLOPT_POSTFIELDS, http_build_query($post));
			curl_setopt($this->client, CURLOPT_POST, true);
		}
		$body = curl_exec($this->client);
		if (!is_string($body)) {
			throw new RuntimeException(curl_error($this->client));
		}

		return array(
			'status' => (int)curl_getinfo($this->client, CURLINFO_RESPONSE_CODE),
			'redirect' => (string)curl_getinfo($this->client, CURLINFO_REDIRECT_URL),
			'contentType' => (string)curl_getinfo($this->client, CURLINFO_CONTENT_TYPE),
			'body' => $body,
		);
	}
}
