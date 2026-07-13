<?php
class SyloraSecret {
	public static function resolve($value) {
		if (!is_string($value)) {
			return '';
		}

		$value = trim($value);

		if (substr($value, 0, 4) !== 'env:') {
			return $value;
		}

		$name = trim(substr($value, 4));

		if (!preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', $name)) {
			return '';
		}

		$secret = getenv($name);

		return is_string($secret) ? $secret : '';
	}

	public static function isReference($value) {
		return is_string($value) && preg_match('/^env:[A-Z][A-Z0-9_]{1,127}$/', trim($value)) === 1;
	}

	public static function isEmptyOrReference($value) {
		return $value === '' || $value === null || self::isReference($value);
	}
}
