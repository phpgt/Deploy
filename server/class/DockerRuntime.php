<?php
namespace App;

use RuntimeException;

readonly class DockerRuntime {
	public function __construct(
		private string $resources = __DIR__ . "/../../resources",
		private string $database = "sqlite",
		private string $phpVersion = "8.4",
		private string $healthPath = "/",
	) {
		if(!in_array($database, ["sqlite", "mysql", "external", "none"], true)) {
			throw new RuntimeException("Invalid Docker database mode.");
		}
	}

	public function deploy(string $directory, string $source, string $id):void {
		$directory = realpath($directory) ?: throw new RuntimeException("Deployment directory missing.");
		$release = basename($source);
		$incoming = "$directory/incoming/$release";
		(new SourceArchive())->discard($incoming);
		$this->run(["mkdir", "-p", "$incoming/source"], $directory);
		$this->run(["cp", "-a", "$directory/$source/.", "$incoming/source/"], $directory);
		$this->run(["cp", "-R", $this->resources, "$incoming/tooling"], $directory);
		$this->run([
			"sh", "$incoming/tooling/deploy.sh", $directory, $release, $id,
			"auto", $this->database, $this->phpVersion, $this->healthPath, "auto",
		], $directory);
	}

	/** @param array<string, string> $values */
	public function configure(string $directory, string $id, array $values):void {
		$directory = realpath($directory) ?: throw new RuntimeException("Deployment directory missing.");
		$state = json_decode((string)file_get_contents("$directory/runtime.json"), true, flags: JSON_THROW_ON_ERROR);
		$app = "gt-$id";
		$inspection = trim($this->capture(["docker", "inspect", "--format", '{{.Image}} {{index .Config.Labels "phpgt.application"}} {{index .Config.Labels "phpgt.configuration"}}', $app], $directory));
		[$image, $owner, $supported] = array_pad(explode(" ", $inspection), 3, "");
		if($owner !== $app || $supported !== "1") {
			throw new RuntimeException("Run gt deploy once to update this application's runtime, then retry config-set.", 409);
		}
		$path = "$directory/shared/config/config.production.ini";
		$previousState = $state;
		$previous = file_get_contents($path);
		$mode = fileperms($path) & 0777;
		if($previous === false) {
			throw new RuntimeException("Production configuration is unavailable.");
		}
		try {
			$command = $this->configurationContainer($directory, $id, $image);
			$this->capture([...$command, "gt", "config", "set", "--stdin"], $directory, json_encode($values, JSON_THROW_ON_ERROR));
			$probe = $this->configurationContainer($directory, $id, $image, ",readonly");
			$json = $this->capture([...$probe, "php", "/usr/local/lib/phpgt/configuration.php"], $directory);
			$keys = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
			if(!is_array($keys) || !array_is_list($keys) || count(array_filter($keys, "is_string")) !== count($keys)) {
				throw new RuntimeException("Configuration inspection failed.");
			}
			$state["port"] = $this->restartApplication($app, $directory);
			$this->waitForApplication($state["port"]);
			$state["configuration_keys"] = $keys;
			$this->replaceFile("$directory/runtime.json", json_encode($state, JSON_THROW_ON_ERROR), 0600);
		}
		catch(\Throwable $exception) {
			error_log("Configuration operation failed: " . $exception::class . " at " . basename($exception->getFile()) . ":" . $exception->getLine());
			$this->replaceFile($path, $previous, $mode);
			$previousState["port"] = $this->restartApplication($app, $directory);
			$this->replaceFile("$directory/runtime.json", json_encode($previousState, JSON_THROW_ON_ERROR), 0600);
			throw $exception;
		}
	}

	/** @return list<string> */
	private function configurationContainer(string $directory, string $id, string $image, string $access = ""):array {
		$volume = getenv("DEPLOY_DATA_VOLUME");
		$mount = $volume
			? "type=volume,src=$volume,dst=/deployment-config,volume-subpath=deployment/$id/shared/config$access"
			: "type=bind,src=$directory/shared/config,dst=/deployment-config$access";
		return ["docker", "run", "--rm", "-i", "--network", "gt-$id", "-v", "gt-$id-data:/data",
			"-e", "GT_DEPLOY_CONFIG_DIRECTORY=/deployment-config", "--mount", $mount, $image];
	}

	private function restartApplication(string $app, string $directory):int {
		$this->capture(["docker", "restart", $app], $directory);
		// Docker can allocate a new ephemeral host port when a container restarts.
		$binding = trim($this->capture(["docker", "port", $app, "80/tcp"], $directory));
		if(!preg_match('/^127\.0\.0\.1:([0-9]+)$/D', $binding, $matches)
		|| (int)$matches[1] < 1 || (int)$matches[1] > 65535) {
			throw new RuntimeException("Unable to find the application's HTTP port.");
		}
		return (int)$matches[1];
	}

	private function waitForApplication(int $port):void {
		for($attempt = 0; $attempt < 30; $attempt++) {
			$process = proc_open(["curl", "--silent", "--output", "/dev/null", "--write-out", "%{http_code}", "--max-time", "2", "http://127.0.0.1:$port$this->healthPath"],
				[["file", "/dev/null", "r"], ["pipe", "w"], ["file", "/dev/null", "w"]], $pipes);
			if(is_resource($process)) {
				$status = stream_get_contents($pipes[1]);
				fclose($pipes[1]);
				$code = proc_close($process);
				if($code === 0 && preg_match('/^2[0-9]{2}$/D', $status)) {
					return;
				}
			}
			sleep(1);
		}
		throw new RuntimeException("Application did not become healthy after configuration changed.");
	}

	private function replaceFile(string $path, string $contents, int $mode):void {
		$temporary = tempnam(dirname($path), ".configuration-");
		if($temporary === false) {
			throw new RuntimeException("Unable to stage configuration.");
		}
		try {
			if(file_put_contents($temporary, $contents) !== strlen($contents) || !chmod($temporary, $mode) || !rename($temporary, $path)) {
				throw new RuntimeException("Unable to replace configuration.");
			}
		}
		finally {
			if(is_file($temporary)) {
				unlink($temporary);
			}
		}
	}

	/** @param list<string> $command */
	private function capture(array $command, string $directory, string $input = ""):string {
		$process = proc_open($command, [["pipe", "r"], ["pipe", "w"], ["file", "$directory/configuration.log", "a"]], $pipes, $directory);
		if(!is_resource($process)) {
			throw new RuntimeException("Unable to start configuration command.");
		}
		$closed = false;
		try {
			$offset = 0;
			while($offset < strlen($input)) {
				$written = fwrite($pipes[0], substr($input, $offset));
				if($written === false || $written === 0) {
					throw new RuntimeException("Unable to send configuration values.");
				}
				$offset += $written;
			}
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$code = proc_close($process);
			$closed = true;
			if($code !== 0 || $output === false) {
				throw new RuntimeException("Deployment configuration command failed.");
			}
			return $output;
		}
		finally {
			if(!$closed) {
				proc_terminate($process);
				proc_close($process);
			}
		}
	}

	/** @param list<string> $command */
	private function run(array $command, string $directory):void {
		$process = proc_open($command, [
			["file", "/dev/null", "r"],
			["file", "$directory/build.log", "a"],
			["file", "$directory/build.log", "a"],
		], $pipes, $directory);
		unset($pipes);
		if(!is_resource($process) || proc_close($process) !== 0) {
			throw new RuntimeException("Deployment build failed. See the server's build.log for details.");
		}
	}
}
