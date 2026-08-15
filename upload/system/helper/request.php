<?php
function is_https_request(array $server, array $trusted_proxies): bool {
	$https = $server['HTTPS'] ?? false;

	if ($https === true || $https === 1 || $https === '1' || (is_string($https) && strtolower($https) === 'on')) {
		return true;
	}

	if (($server['SERVER_PORT'] ?? null) === 443 || ($server['SERVER_PORT'] ?? null) === '443') {
		return true;
	}

	$remote_address = $server['REMOTE_ADDR'] ?? null;

	if (!is_string($remote_address) || !in_array($remote_address, $trusted_proxies, true)) {
		return false;
	}

	$forwarded_proto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;

	if (is_string($forwarded_proto) && strtolower(trim($forwarded_proto)) === 'https') {
		return true;
	}

	$forwarded_ssl = $server['HTTP_X_FORWARDED_SSL'] ?? null;

	return is_string($forwarded_ssl) && strtolower(trim($forwarded_ssl)) === 'on';
}
