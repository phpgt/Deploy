<?php
namespace GT\Deploy;

use GT\Config\Config;
use GT\Config\ConfigSection;
use GT\Config\FileWriter;
use RuntimeException;

readonly class ProjectConfiguration {
	public function saveToken(string $directory, string $token, string $server):void {
		$path = "$directory/config.dev.ini";
		$config = new Config(new ConfigSection("deploy", ["token" => $token, "token_server" => $server]));
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
