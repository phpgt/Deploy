<?php
namespace GT\Deploy\Test\Integration;

use GT\Deploy\ProjectConfiguration;
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
