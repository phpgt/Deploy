<?php
// Routing fields come from the worker, never from application-controlled output.
$keys = json_decode((string)file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if(!is_array($keys) || !array_is_list($keys) || count(array_filter($keys, "is_string")) !== count($keys)) {
	throw new RuntimeException("Invalid configuration key metadata.");
}
echo json_encode([
	"port" => (int)$argv[2], "container" => $argv[3], "release" => $argv[4],
	"configuration_keys" => $keys,
], JSON_THROW_ON_ERROR), "\n";
