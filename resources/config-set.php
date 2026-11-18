<?php
use GT\Config\ConfigFactory;
use GT\Config\Environment;

require "/opt/phpgt/vendor/autoload.php";
set_error_handler(static function():never {
	throw new RuntimeException("Invalid configuration.");
});
$temporary = null;
try {
	if(($argv[1] ?? null) !== "--stdin" || count($argv) !== 2) {
		throw new RuntimeException("Use gt config set --stdin with a JSON object of configuration values.");
	}
	$values = json_decode(stream_get_contents(STDIN, 65537), true, flags: JSON_THROW_ON_ERROR);
	if(!is_array($values) || $values === [] || array_is_list($values)) {
		throw new RuntimeException("Expected configuration values.");
	}
	$path = realpath("/app/config.production.ini");
	if($path === false || !is_file($path)) {
		throw new RuntimeException("Production configuration is unavailable.");
	}
	$sections = [];
	foreach($values as $name => $value) {
		if(!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*\.[A-Za-z_][A-Za-z0-9_.-]*$/D', $name)
		|| str_starts_with($name, "deploy.") || $name === "app.production") {
			throw new RuntimeException("Invalid configuration key or value.");
		}
		[$section, $key] = explode(".", $name, 2);
		$sections[$section][$key] = $value;
	}
	$mask = umask(0077);
	$temporary = tempnam(dirname($path), ".config-set-");
	if($temporary === false || !copy($path, $temporary)) {
		throw new RuntimeException("Unable to stage production configuration.");
	}
	$owner = fileowner($path);
	$group = filegroup($path);
	$current = ConfigFactory::createFromPathName($path);
	foreach($current->getSectionNames() as $section) {
		$sections[$section] = array_replace($current->getSection($section)->asArray(), $sections[$section] ?? []);
	}
	$contents = "";
	$expected = [];
	foreach($sections as $section => $settings) {
		$contents .= "[$section]\n";
		foreach($settings as $key => $value) {
			if(!is_scalar($value) && $value !== null) {
				throw new RuntimeException("Unsupported production configuration structure.");
			}
			$value = is_bool($value) ? ($value ? "1" : "0") : (string)$value;
			// FileWriter currently coerces numeric-looking strings; secrets must retain every byte.
			$quoted = '"' . str_replace(["\\", '"', '$'], ["\\\\", '\\"', '\\$'], $value) . '"';
			$contents .= "$key=$quoted\n";
			$expected["$section.$key"] = $value;
		}
		$contents .= "\n";
	}
	if(file_put_contents($temporary, $contents) !== strlen($contents)) {
		throw new RuntimeException("Unable to stage production configuration.");
	}
	$check = ConfigFactory::createFromPathName($temporary);
	$check->withEnvironment(Environment::PRODUCTION);
	foreach($expected as $key => $value) {
		if($check->get($key) !== $value) {
			throw new RuntimeException("Configuration could not be written without changing its value.");
		}
	}
	chmod($temporary, fileperms($path) & 0777);
	chown($temporary, $owner);
	chgrp($temporary, $group);
	if(!rename($temporary, $path)) {
		throw new RuntimeException("Unable to replace production configuration.");
	}
	$temporary = null;
	umask($mask);
	echo "Configuration updated.\n";
}
catch(Throwable) {
	if(is_string($temporary) && is_file($temporary)) {
		unlink($temporary);
	}
	fwrite(STDERR, "Unable to update production configuration. No values were changed.\n");
	exit(1);
}
