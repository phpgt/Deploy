<?php
namespace GT\Deploy\Test\Integration;

use GT\Deploy\ProjectConfiguration;
use GT\Deploy\DeploymentEnvironment;
use GT\Deploy\Transport;
use GT\Deploy\ProcessRunner;
use PHPUnit\Framework\TestCase;

class ProjectConfigurationTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/deploy-config-" . bin2hex(random_bytes(8));
		mkdir($this->directory);
		(new ProcessRunner())->run(["git", "init", "--quiet"], $this->directory);
	}

	protected function tearDown():void {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($files as $file) {
			if($file->isDir()) {
				rmdir($file->getPathname());
			}
			else {
				unlink($file->getPathname());
			}
		}
		rmdir($this->directory);
	}

	public function testSaveEnvironment_keepsDestinationCommittableAndTokensPrivate():void {
		file_put_contents("$this->directory/config.ini", "[deploy]\nserver=https://unused.example\n");
		$sut = new ProjectConfiguration();
		$sut->saveEnvironment($this->directory, DeploymentEnvironment::Production, "http://localhost:9090", Transport::TarHttp);
		$sut->saveToken($this->directory, "default-token", "https://unused.example");
		$sut->saveToken($this->directory, "stage-token", "http://localhost:9090", DeploymentEnvironment::Staging);
		$sut->saveToken($this->directory, "prod-token", "http://localhost:9090", DeploymentEnvironment::Production);
		$settings = $sut->loadSettings($this->directory, DeploymentEnvironment::Production);
		self::assertSame("prod-token", $settings->token);
		self::assertSame("http://localhost:9090", $settings->server);
		$private = parse_ini_file("$this->directory/config.dev.ini", true);
		self::assertSame("default-token", $private["deploy"]["token"]);
		self::assertSame("stage-token", $private["deployment"]["staging_token"]);
		self::assertSame("prod-token", $private["deployment"]["production_token"]);
		self::assertStringNotContainsString("token", file_get_contents("$this->directory/deploy.production.ini"));
		self::assertSame([], $sut->getOverrideKeys($this->directory));
	}

	public function testLoadSettings_missingEnvironmentDoesNotFallBackToDefault():void {
		file_put_contents("$this->directory/config.ini", "[deploy]\nserver=deploy.php.gt\n");
		$sut = new ProjectConfiguration();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("gt deploy --staging");
		$sut->loadSettings($this->directory, DeploymentEnvironment::Staging);
	}

	public function testGetOverrideValues_selectsKeySectionAndAllWithoutManagedValues():void {
		file_put_contents("$this->directory/config.dev.ini", "[deploy]\ntoken=not-for-upload\n[deployment]\nproduction_token=also-not-for-upload\n[app]\nproduction=false\n[email]\napi_key=development-key\nenabled=false\nport=0\n[other]\nname=example\n");
		file_put_contents("$this->directory/config.remote.ini", "[email]\napi_key=production-key\n");
		$sut = new ProjectConfiguration();
		self::assertSame(["email.api_key" => "development-key"], $sut->getOverrideValues($this->directory, "email.api_key"));
		self::assertSame(["email.api_key" => "development-key", "email.enabled" => "0", "email.port" => "0"], $sut->getOverrideValues($this->directory, "email"));
		self::assertSame(["email.api_key" => "development-key", "email.enabled" => "0", "email.port" => "0", "other.name" => "example"], $sut->getOverrideValues($this->directory, "*"));
	}

	public function testGetOverrideValues_rejectsUnknownSelector():void {
		$sut = new ProjectConfiguration();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("No local application configuration matches");
		$sut->getOverrideValues($this->directory, "unknown");
	}

	public function testGetOverrideKeys_listsSortedUniqueKeysWithoutDeploymentCredentials():void {
		file_put_contents("$this->directory/config.dev.ini", "[deploy]\ntoken=private-token\nserver=http://localhost\n[email]\napi_key=private-value\nenabled=false\n");
		file_put_contents("$this->directory/config.remote.ini", "[email]\napi_key=another-private-value\n[other]\nmissing=\n");
		$sut = new ProjectConfiguration();
		self::assertSame(["email.api_key", "email.enabled", "other.missing"], $sut->getOverrideKeys($this->directory));
	}

	public function testGetOverrideKeys_doesNotIncludeBaseConfig():void {
		file_put_contents("$this->directory/config.ini", "[public]\nname=already-uploaded\n");
		$sut = new ProjectConfiguration();
		self::assertSame([], $sut->getOverrideKeys($this->directory));
	}

	public function testGetOverrideKeys_invalidConfigDoesNotExposeItsContents():void {
		file_put_contents("$this->directory/config.remote.ini", "[invalid\npassword=secret-value\n");
		$sut = new ProjectConfiguration();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Unable to parse local configuration.");
		$sut->getOverrideKeys($this->directory);
	}

	public function testSaveToken_preservesExistingSettingsAndRestrictsPermissions():void {
		file_put_contents("$this->directory/config.dev.ini", "[deploy]\nserver=http://localhost:9090\n[example]\nname=preserved\n");
		$sut = new ProjectConfiguration();

		$sut->saveToken($this->directory, "test-token", "http://localhost:9090");

		$config = parse_ini_file("$this->directory/config.dev.ini", true);
		self::assertSame("test-token", $config["deploy"]["token"]);
		self::assertSame("http://localhost:9090", $config["deploy"]["server"]);
		self::assertSame("preserved", $config["example"]["name"]);
		self::assertSame(0600, fileperms("$this->directory/config.dev.ini") & 0777);
	}

	public function testIsIgnoredByGit_checksEffectiveRulesAndTrackedFiles():void {
		file_put_contents("$this->directory/config.dev.ini", "");
		file_put_contents("$this->directory/.gitignore", "config.*.ini\n");
		$sut = new ProjectConfiguration();

		self::assertTrue($sut->isIgnoredByGit($this->directory));
		(new ProcessRunner())->run(["git", "add", "--force", "config.dev.ini"], $this->directory);
		self::assertFalse($sut->isIgnoredByGit($this->directory));
	}

	public function testIsIgnoredByGit_configIsNotIgnored():void {
		$sut = new ProjectConfiguration();

		self::assertFalse($sut->isIgnoredByGit($this->directory));
	}
}
