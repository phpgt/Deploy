<?php
namespace GT\Deploy;

use GT\Config\Config;
use GT\Config\ConfigFactory;
use GT\Config\ConfigSection;
use GT\Config\FileWriter;
use GT\Config\IniParser;
use RuntimeException;

readonly class ProjectConfiguration {
	/** @return list<string> */
	public function getOverrideKeys(string $directory):array {
		$keys = [];
		foreach(glob("$directory/config.*.ini") ?: [] as $file) {
			$config = $this->parseOverride($file);
			foreach($config->getSectionNames() as $sectionName) {
				if(in_array($sectionName, ["deploy", "deployment"], true)) {
					continue;
				}
				foreach(array_keys($config->getSection($sectionName)?->asArray() ?? []) as $key) {
					$keys []= "$sectionName.$key";
				}
			}
		}
		$keys = array_values(array_unique($keys));
		sort($keys);
		return $keys;
	}

	/** @return array<string, string> */
	public function getOverrideValues(string $directory, string $selector):array {
		$values = [];
		foreach($this->overridePaths($directory) as $file) {
			$config = $this->parseOverride($file);
			foreach($config->getSectionNames() as $section) {
				foreach($config->getSection($section)?->asArray() ?? [] as $key => $value) {
					$name = "$section.$key";
					if(!in_array($section, ["deploy", "deployment"], true) && $name !== "app.production") {
						$values[$name] = $value;
					}
				}
			}
		}
		$selected = $this->selectValues($values, $selector);
		ksort($selected);
		return $selected;
	}

	/** @return list<string> */
	private function overridePaths(string $directory):array {
		$files = glob("$directory/config.*.ini") ?: [];
		$priority = array_flip(array_map(
			fn(string $suffix):string => "$directory/config.$suffix.ini",
			array_merge([ConfigFactory::FILE_DEFAULT], ConfigFactory::FILE_OVERRIDE_ORDER),
		));
		usort($files, static function(string $first, string $second) use ($priority):int {
			return ($priority[$first] ?? -1) <=> ($priority[$second] ?? -1)
				?: strcmp($first, $second);
		});
		return $files;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	private function selectValues(array $values, string $selector):array {
		if(array_key_exists($selector, $values)) {
			$values = [$selector => $values[$selector]];
		}
		elseif($selector !== "*") {
			$values = array_filter($values, static fn(string $key):bool => str_starts_with($key, "$selector."), ARRAY_FILTER_USE_KEY);
		}
		if($values === []) {
			throw new RuntimeException("No local application configuration matches the selector.");
		}
		foreach($values as &$value) {
			if(!is_scalar($value) && $value !== null) {
				throw new RuntimeException("Only scalar configuration values can be sent to the deployment.");
			}
			$value = is_bool($value) ? ($value ? "1" : "0") : (string)$value;
		}
		unset($value);
		return $values;
	}

	private function parseOverride(string $file):Config {
		// Parser diagnostics may include secret text; convert them to a safe error.
		set_error_handler(static function():never {
			throw new RuntimeException("Unable to parse local configuration.");
		});
		try {
			return (new IniParser($file))->parse();
		}
		finally {
			restore_error_handler();
		}
	}

	public function loadSettings(string $directory, ?DeploymentEnvironment $environment = null):Settings {
		$config = (new ConfigFactory())->createForProject($directory, "$directory/vendor/phpgt/webengine/config.default.ini");
		$destination = null;
		if($environment !== null) {
			$path = "$directory/deploy.$environment->value.ini";
			if(!is_file($path)) {
				throw new RuntimeException("Run gt deploy --$environment->value to configure this environment first.");
			}
			$destination = $this->parseOverride($path);
		}
		return new Settings($config, $environment, $destination);
	}

	public function saveEnvironment(string $directory, DeploymentEnvironment $environment, string $server, Transport $transport):void {
		$path = "$directory/deploy.$environment->value.ini";
		if(file_exists($path)) {
			throw new RuntimeException("deploy.$environment->value.ini already exists.");
		}
		$config = new Config(new ConfigSection("deploy", ["server" => $server, "transport" => $transport->value]));
		new Settings($config);
		(new FileWriter($config))->writeIni($path);
	}

	public function saveToken(string $directory, string $token, string $server, ?DeploymentEnvironment $environment = null):void {
		$path = "$directory/config.dev.ini";
		$section = $environment === null ? "deploy" : "deployment";
		$prefix = $environment === null ? "" : "{$environment->value}_";
		$config = new Config(new ConfigSection($section, ["{$prefix}token" => $token, "{$prefix}token_server" => $server]));
		$mask = umask(0077);
		try {
			if(is_file($path) && !chmod($path, 0600)) {
				throw new RuntimeException("Unable to protect config.dev.ini.");
			}

			(new FileWriter($config))->writeIni($path);
		}
		finally {
			umask($mask);
		}
	}

	public function isIgnoredByGit(string $directory):bool {
		$process = proc_open(
			["git", "-C", $directory, "check-ignore", "--quiet", "config.dev.ini"],
			[["file", "/dev/null", "r"], ["file", "/dev/null", "w"], ["file", "/dev/null", "w"]],
			$pipes,
		);
		unset($pipes);
		if(!is_resource($process)) {
			return false;
		}

		return proc_close($process) === 0;
	}
}
