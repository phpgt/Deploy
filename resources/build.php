<?php
// Use the same build engine as gt build, checking its reported errors explicitly.
require '/app/vendor/autoload.php';
if(!is_file('/app/vendor/phpgt/webengine/go.php')) {
	throw new RuntimeException('The application must require phpgt/webengine as a production dependency.');
}
foreach(['build.ini', 'build.json', 'vendor/phpgt/webengine/build.default.ini', 'vendor/phpgt/webengine/build.default.json'] as $path) {
	if(!is_file($path)) {
		continue;
	}
	$modePath = preg_replace('/\.(ini|json)$/', '.production.$1', $path);
	$mode = is_file($modePath) ? 'production' : null;
	$build = new GT\Build\Build($path, '/app', $mode);
	$errors = [];
	$build->check($errors);
	if(!$errors) {
		$build->build($errors);
	}
	if($errors) {
		throw new RuntimeException(implode(PHP_EOL, $errors));
	}
	break;
}
if(!is_dir('/app/www')) {
	mkdir('/app/www', 0755, true);
}
