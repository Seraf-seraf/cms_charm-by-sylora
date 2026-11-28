<?php
class Cookie {
	private function isSecure(): bool {
		return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	}

	public function set(string $name, $value, int $expires, string $path = '/', string $domain = '', bool $http_only = true, string $same_site = 'Lax'): bool {
		$options = array(
			'expires' => $expires,
			'path' => $path,
			'secure' => $this->isSecure(),
			'httponly' => $http_only,
			'samesite' => $same_site
		);

		if ($domain !== '') {
			$options['domain'] = $domain;
		}

		return setcookie($name, $value, $options);
	}

	public function clear(string $name, string $path = '/', string $domain = '', bool $http_only = true, string $same_site = 'Lax'): bool {
		return $this->set($name, '', -1, $path, $domain, $http_only, $same_site);
	}
}
