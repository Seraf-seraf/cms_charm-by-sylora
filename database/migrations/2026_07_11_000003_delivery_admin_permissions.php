<?php
require_once __DIR__ . '/../../upload/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$db->set_charset('utf8mb4');

$routes = array('extension/shipping/cdek_official', 'extension/shipping/russian_post');
$result = $db->query("SELECT user_group_id, permission FROM `" . DB_PREFIX . "user_group` WHERE name = 'Administrator'");
while ($group = $result->fetch_assoc()) {
	$permissions = json_decode($group['permission'], true);
	if (!is_array($permissions)) $permissions = array();
	foreach (array('access', 'modify') as $type) {
		if (!isset($permissions[$type]) || !is_array($permissions[$type])) $permissions[$type] = array();
		$permissions[$type] = array_values(array_unique(array_merge($permissions[$type], $routes)));
	}
	$value = json_encode($permissions);
	$stmt = $db->prepare("UPDATE `" . DB_PREFIX . "user_group` SET permission = ? WHERE user_group_id = ?");
	$stmt->bind_param('si', $value, $group['user_group_id']);
	$stmt->execute();
}
echo "Delivery extension permissions are registered.\n";
