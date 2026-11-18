<?php
namespace GT\Deploy\Test\Integration\Cli;

use PHPUnit\Framework\TestCase;

class RunCommandTest extends TestCase {
	private string $directory;
	private string $server;
	/** @var resource */
	private $process;
	/** @var resource */
	private $worker;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/deploy-cli-" . bin2hex(random_bytes(8));
		mkdir("$this->directory/project", 0700, true);
		file_put_contents("$this->directory/project/page.html", "hello");
		$socket = stream_socket_server("tcp://127.0.0.1:0");
		self::assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$this->server = "http://$address";
		$serverRoot = "$this->directory/server";
		mkdir("$serverRoot/www", 0700, true);
		$source = dirname(__DIR__, 3) . "/server";
		mkdir("$serverRoot/api");
		foreach(glob("$source/api/*.php") as $api) {
			copy($api, "$serverRoot/api/" . basename($api));
		}
		symlink("$source/vendor", "$serverRoot/vendor");
		file_put_contents("$serverRoot/config.ini", "[app]\nnamespace=App\n[session]\nuse_cookies=false\n");
		file_put_contents("$serverRoot/www/index.php", '<?php require(__DIR__ . "/../vendor/phpgt/webengine/go.php");');
		file_put_contents("$this->directory/project/.gitignore", "config.*.ini\n");
		$this->git("init", "--quiet");
		$this->git("add", "page.html");
		$this->process = proc_open(
			[PHP_BINARY, "-S", $address, "-t", "$serverRoot/www", "$serverRoot/vendor/phpgt/webengine/go.php"],
			[0 => ["file", "/dev/null", "r"], 1 => ["file", "$this->directory/server.log", "a"], 2 => ["file", "$this->directory/server.log", "a"]],
			$pipes,
		);
		self::assertIsResource($this->process);
		$this->worker = proc_open(
			[PHP_BINARY, __DIR__ . "/build-worker.php", $serverRoot],
			[0 => ["file", "/dev/null", "r"], 1 => ["file", "$this->directory/worker.log", "a"], 2 => ["file", "$this->directory/worker.log", "a"]],
			$pipes,
		);
		self::assertIsResource($this->worker);
		for($attempt = 0; $attempt < 50; $attempt++) {
			if(str_contains(file_get_contents("$this->directory/server.log"), "started")) {
				return;
			}
			usleep(20_000);
		}
		self::fail("The HTTP fixture did not start.");
	}

	protected function tearDown():void {
		proc_terminate($this->worker);
		proc_close($this->worker);
		proc_terminate($this->process);
		proc_close($this->process);
		unlink("$this->directory/server/vendor");
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($files as $file) {
			if($file->isDir() && !$file->isLink()) {
				rmdir($file->getPathname());
			}
			else {
				unlink($file->getPathname());
			}
		}
		rmdir($this->directory);
	}

	public function testRun_listsOnlyUnsetOverrideKeysAfterDeployWithoutTheirValues():void {
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\n[mail]\nconfigured=production-value\nblank=\n[flags]\nzero=0\ndisabled=false\n");
		$this->git("add", "config.ini");
		file_put_contents("$this->directory/project/config.dev.ini", "[mail]\nconfigured=local-value\nblank=private-blank-value\nsecret=private-secret-value\n[flags]\nzero=1\ndisabled=true\n");
		file_put_contents("$this->directory/project/config.remote.ini", "[mail]\nsecret=another-private-value\n");
		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);
		self::assertSame(0, $exitCode, $error);
		self::assertStringContainsString("gt deploy config-set 'mail.blank'", $output);
		self::assertSame(1, substr_count($output, "gt deploy config-set 'mail.secret'"));
		self::assertStringNotContainsString("gt deploy config-set 'mail.configured'", $output);
		self::assertStringNotContainsString("gt deploy config-set 'flags.", $output);
		self::assertStringNotContainsString("gt deploy config-set 'deploy.", $output);
		self::assertStringNotContainsString("private-", $output . $error);
		self::assertStringNotContainsString("local-value", $output . $error);
		self::assertLessThan(strpos($output, "gt deploy config-set"), strpos($output, "Deployed:"));
	}

	public function testRun_configSetSendsSelectedLocalValuesWithoutRebuilding():void {
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\n");
		$this->git("add", "config.ini");
		file_put_contents("$this->directory/project/config.dev.ini", "[email]\napi_key=private-config-secret\nhost=smtp.example.test\n[other]\nflag=false\n");
		[$code, $output, $error] = $this->runCli();
		$this->removeArchive($output);
		self::assertSame(0, $code, $error);
		$record = glob("$this->directory/server/data/deployment/*/reservation.json")[0];
		$path = dirname($record);
		foreach(["email.api_key", "email", "*"] as $selector) {
			[$code, $output, $error] = $this->runCli("config-set", $selector);
			self::assertSame(0, $code, $error);
			self::assertStringContainsString("configuration", $output);
			self::assertStringNotContainsString("private-config-secret", $output . $error);
			self::assertStringNotContainsString("Packaging", $output);
			$applied = json_decode(file_get_contents("$path/fixture-config.json"), true);
			self::assertSame("private-config-secret", $applied["email.api_key"]);
			self::assertSame($selector !== "email.api_key", isset($applied["email.host"]));
			self::assertSame($selector === "*", isset($applied["other.flag"]));
			self::assertArrayNotHasKey("deploy.token", $applied);
		}
		foreach(glob("$path/configuration/*.json") as $job) {
			self::assertArrayNotHasKey("values", json_decode(file_get_contents($job), true));
		}
	}

	public function testRun_configSetRequiresAnExistingDeployment():void {
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\n");
		[$code, $output, $error] = $this->runCli("config-set", "email");
		self::assertSame(1, $code);
		self::assertStringContainsString("Run gt deploy before", $error);
		self::assertSame([], glob("$this->directory/server/data/deployment/*"));
	}

	public function testRun_savesTokenAndUpdatesTheSameDeployment():void {
		mkdir("$this->directory/project/asset");
		mkdir("$this->directory/project/www");
		file_put_contents("$this->directory/project/asset/example.txt", "source asset");
		symlink("$this->directory/project/asset", "$this->directory/project/www/asset");
		$this->git("add", "asset");
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=https://invalid.example\n");
		file_put_contents("$this->directory/project/config.dev.ini", "[deploy]\nserver=$this->server/\n[example]\nname=preserved\n");

		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);
		self::assertSame(0, $exitCode, $error);
		self::assertSame("", $error);
		$config = parse_ini_file("$this->directory/project/config.dev.ini", true);
		$token = $config["deploy"]["token"];
		self::assertSame(42, strlen($token));
		self::assertSame($this->server, $config["deploy"]["token_server"]);
		self::assertSame("preserved", $config["example"]["name"]);
		self::assertStringNotContainsString($token, $output);
		self::assertStringContainsString("Uploaded", $output);
		preg_match('~https://[a-f0-9]+\.deploy\.php\.gt~', $output, $matches);
		$url = $matches[0];
		self::assertLessThan(strpos($output, "Packaging"), strpos($output, $url));
		$records = glob("$this->directory/server/data/deployment/*/reservation.json");
		self::assertCount(1, $records);
		$record = json_decode(file_get_contents($records[0]), true, flags: JSON_THROW_ON_ERROR);
		$path = dirname($records[0]);
		self::assertSame("deployed", $record["status"]);
		self::assertSame("hello", file_get_contents("$path/$record[source]/page.html"));
		self::assertSame("source asset", file_get_contents("$path/$record[source]/asset/example.txt"));
		self::assertFileDoesNotExist("$path/$record[source]/www/asset");
		self::assertSame(filesize("$path/$record[archive]"), $record["uploaded_bytes"]);
		$contents = (new \GT\Deploy\ProcessRunner())->run(["tar", "-tzf", "$path/$record[archive]"], $this->directory);
		self::assertStringNotContainsString("config.dev.ini", $contents);

		file_put_contents("$this->directory/project/page.html", "updated source");
		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);

		self::assertSame(0, $exitCode, $error);
		self::assertStringContainsString($url, $output);
		self::assertCount(1, glob("$this->directory/server/data/deployment/*/reservation.json"));
		self::assertCount(1, glob("$path/source-*.tar.gz"));
		$config = parse_ini_file("$this->directory/project/config.dev.ini", true);
		self::assertSame($token, $config["deploy"]["token"]);
		$current = json_decode(file_get_contents($records[0]), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame("updated source", file_get_contents("$path/$current[source]/page.html"));
	}

	public function testRun_loadsWebEngineDefaults():void {
		$vendor = "$this->directory/project/vendor/phpgt/webengine";
		mkdir($vendor, 0700, true);
		file_put_contents("$vendor/config.default.ini", "[deploy]\nserver=$this->server\n");

		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);

		self::assertSame(0, $exitCode, $error);
		self::assertStringContainsString("Uploaded", $output);
	}

	public function testRun_rejectsUnsafeSourceWithoutPublishingIt():void {
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\n");
		file_put_contents("$this->directory/outside.txt", "outside source");
		symlink("$this->directory/outside.txt", "$this->directory/project/link");
		$this->git("add", "link");

		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);

		self::assertSame(1, $exitCode);
		self::assertStringContainsString("must not contain symlinks", $error);
		self::assertStringContainsString("links", $error);
		self::assertStringNotContainsString("Source extracted.", $output);
		$records = glob("$this->directory/server/data/deployment/*/reservation.json");
		$record = json_decode(file_get_contents($records[0]), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame("reserved", $record["status"]);
		self::assertNull($record["source"]);
		self::assertSame([], glob(dirname($records[0]) . "/.extract-*"));
	}

	public function testRun_buildFailureReturnsFailure():void {
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\n");
		file_put_contents("$this->directory/project/fail-build", "fail");
		$this->git("add", "fail-build");
		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);
		self::assertSame(1, $exitCode);
		self::assertStringContainsString("Deployment build failed", $error);
		self::assertStringNotContainsString("Deployed:", $output);
	}
	public function testRun_expiredDeploymentIsReplacedWithoutManualConfigChanges():void {
		$token = str_repeat("0", 10) . str_repeat("A", 32);
		$id = substr(hash("sha256", $token), 0, 16);
		$path = "$this->directory/server/data/deployment/$id";
		mkdir($path, 0700, true);
		file_put_contents("$path/reservation.json", json_encode([
			"id" => $id, "url" => "https://$id.deploy.php.gt", "status" => "reserved",
			"token_hash" => hash("sha256", $token), "created_at" => gmdate("c", 0),
			"expires_at" => gmdate("c", 86400), "uploaded_bytes" => 0,
			"archive" => null, "source" => null,
		], JSON_THROW_ON_ERROR));
		file_put_contents("$this->directory/project/config.dev.ini", "[deploy]\nserver=$this->server\ntoken=$token\ntoken_server=$this->server\n[example]\nname=preserved\n");

		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);

		self::assertSame(0, $exitCode, $error);
		$plainOutput = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', "", $output);
		self::assertMatchesRegularExpression('/^https:\/\/[a-f0-9]{16}\.deploy\.php\.gt\nPrevious anonymous deployment expired\. Reserved a new URL\./', $plainOutput);
		self::assertStringContainsString("Deployed:", $output);
		self::assertStringNotContainsString($token, $output . $error);
		$config = parse_ini_file("$this->directory/project/config.dev.ini", true);
		self::assertNotSame($token, $config["deploy"]["token"]);
		self::assertSame($this->server, $config["deploy"]["token_server"]);
		self::assertSame("preserved", $config["example"]["name"]);
		$newId = substr(hash("sha256", $config["deploy"]["token"]), 0, 16);
		$record = json_decode(file_get_contents("$this->directory/server/data/deployment/$newId/reservation.json"), true);
		self::assertSame("deployed", $record["status"]);
		self::assertCount(2, glob("$this->directory/server/data/deployment/*/reservation.json"));
	}

	public function testRun_invalidTokenDoesNotCreateAnotherDeployment():void {
		$token = str_repeat("A", 42);
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\ntoken=$token\ntoken_server=$this->server\n");

		[$exitCode, $output, $error] = $this->runCli();

		self::assertSame(1, $exitCode);
		self::assertSame("", $output);
		self::assertStringContainsString("HTTP 401", $error);
		self::assertStringNotContainsString($token, $error);
		self::assertSame([], glob("$this->directory/server/data/deployment/*"));
	}

	public function testRun_failedUploadKeepsTokenAndCanBeRetried():void {
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\n");
		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);
		self::assertSame(0, $exitCode, $error);
		$config = file_get_contents("$this->directory/project/config.dev.ini");
		$records = glob("$this->directory/server/data/deployment/*/reservation.json");
		$record = file_get_contents($records[0]);
		$lock = fopen(dirname($records[0]) . "/upload.lock", "c");
		flock($lock, LOCK_EX);
		try {
			[$exitCode, $output, $error] = $this->runCli();
			$this->removeArchive($output);
			self::assertSame(1, $exitCode);
			self::assertStringContainsString("HTTP 409", $error);
			self::assertSame($config, file_get_contents("$this->directory/project/config.dev.ini"));
			self::assertSame($record, file_get_contents($records[0]));
		}
		finally {
			fclose($lock);
		}

		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);
		self::assertSame(0, $exitCode, $error);
		self::assertCount(1, glob("$this->directory/server/data/deployment/*/reservation.json"));
	}

	public function testRun_warnsWhenConfigIsNotIgnored():void {
		unlink("$this->directory/project/.gitignore");
		file_put_contents("$this->directory/project/config.ini", "[deploy]\nserver=$this->server\n");

		[$exitCode, $output, $error] = $this->runCli();
		$this->removeArchive($output);

		self::assertSame(0, $exitCode);
		self::assertStringContainsString("config.dev.ini is not ignored by Git", $error);
		$config = parse_ini_file("$this->directory/project/config.dev.ini", true);
		self::assertStringNotContainsString($config["deploy"]["token"], $error);
	}

	public function testRun_helpDoesNotReserve():void {
		[$exitCode, $output, $error] = $this->runCli("--help");

		self::assertSame(0, $exitCode);
		self::assertStringContainsString("Package a PHP application", $output);
		self::assertSame("", $error);
	}

	private function removeArchive(string $output):void {
		if(preg_match('/Archive: (.+)/', $output, $matches)) {
			$path = trim($matches[1]);
			if(is_file($path)) {
				unlink($path);
			}
		}
	}

	private function git(string ...$arguments):void {
		(new \GT\Deploy\ProcessRunner())->run(["git", ...$arguments], "$this->directory/project");
	}

	/** @return array{int, string, string} */
	private function runCli(string ...$arguments):array {
		$process = proc_open(
			[PHP_BINARY, __DIR__ . "/../../../bin/deploy", ...$arguments],
			[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
			$pipes,
			"$this->directory/project",
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output, $error];
	}
}
