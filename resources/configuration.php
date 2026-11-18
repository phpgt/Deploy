<?php
use GT\Config\ConfigFactory;

require "/app/vendor/autoload.php";
$defaults = "/app/vendor/phpgt/webengine/config.default.ini";
$factory = new ConfigFactory();
$config = $factory->createForProject("/app", $defaults);
$paths = [$defaults];
foreach(array_merge([ConfigFactory::FILE_DEFAULT, ""], ConfigFactory::FILE_OVERRIDE_ORDER) as $suffix) {
	$paths []= "/app/config" . ($suffix === "" ? "" : ".$suffix") . ".ini";
}
// Inspect the original layers: Config merging can coerce a false value to "".
$configured = [];
foreach($paths as $path) {
	$layer = $factory->createFromPathName($path);
	foreach($layer?->getSectionNames() ?? [] as $sectionName) {
		foreach($layer->getSection($sectionName) as $key => $value) {
			$configured["$sectionName.$key"] = $value !== null && $value !== "";
		}
	}
}
$keys = [];
foreach($configured as $key => $set) {
	$value = $config->get($key);
	if($set || ($value !== null && $value !== "")) {
		$keys []= $key;
	}
}
sort($keys);
echo json_encode($keys, JSON_THROW_ON_ERROR), "\n";
