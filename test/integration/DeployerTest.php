<?php
namespace GT\Deploy\Test\Integration;

use GT\Deploy\Deployer;
use GT\Deploy\ProcessRunner;
use PharData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DeployerTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/deploy test " . bin2hex(random_bytes(4));
		mkdir($this->directory);
	}

	protected function tearDown():void {
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

	public function testDeploy_excludesLocalFilesAndReportsActualContents():void {
		file_put_contents("$this->directory/page.html", "hello");
		file_put_contents("$this->directory/config.production.ini", "secret");
		file_put_contents("$this->directory/deploy.production.ini", "[deploy]\nserver=deploy.php.gt\ntransport=tar-http\n");
		file_put_contents("$this->directory/.env", "secret");
		file_put_contents("$this->directory/local.sqlite-wal", "database contents");
		file_put_contents("$this->directory/private.key", "credentials");
		file_put_contents("$this->directory/private.txt", "ignored");
		file_put_contents("$this->directory/.deployignore", "private.txt\n");
		$sut = new Deployer();
		$package = $sut->deploy($this->directory);
		try {
			self::assertSame(2, $package["files"]);
			self::assertSame(filesize($package["archive"]), $package["bytes"]);
			self::assertGreaterThan(0, $package["bytes"]);
			self::assertSame(sys_get_temp_dir(), dirname($package["archive"]));
			self::assertStringEndsWith(".tar.gz", $package["archive"]);
			$archive = new PharData($package["archive"]);
			self::assertSame("hello", $archive["page.html"]->getContent());
			foreach(["config.production.ini", "deploy.production.ini", ".env", "local.sqlite-wal", "private.key", "private.txt"] as $excluded) {
				self::assertFalse(isset($archive[$excluded]));
			}
		}
		finally {
			unlink($package["archive"]);
		}
	}

	public function testDeploy_longAndUnusualFilenames():void {
		$path = "data/cache/articles";
		mkdir("$this->directory/$path", 0700, true);
		$name = "2023-10-18-setting-up-authwave-for-the-first-time-" . str_repeat("a", 64) . ".md";
		file_put_contents("$this->directory/$path/$name", "article");
		file_put_contents("$this->directory/line\nbreak.txt", "newline");
		file_put_contents("$this->directory/space and \\slash.txt", "backslash");
		$sut = new Deployer();
		$package = $sut->deploy($this->directory);
		try {
			self::assertSame(3, $package["files"]);
			mkdir("$this->directory/extracted");
			(new ProcessRunner())->run(["tar", "-xzf", $package["archive"], "-C", "extracted"], $this->directory);
			self::assertSame("article", file_get_contents("$this->directory/extracted/$path/$name"));
			self::assertSame("newline", file_get_contents("$this->directory/extracted/line\nbreak.txt"));
			self::assertSame("backslash", file_get_contents("$this->directory/extracted/space and \\slash.txt"));
		}
		finally {
			unlink($package["archive"]);
		}
	}

	public function testDeploy_gitIncludesOnlyTrackedWorkingFiles():void {
		mkdir("$this->directory/asset");
		mkdir("$this->directory/www");
		file_put_contents("$this->directory/asset/example.txt", "source asset");
		symlink("$this->directory/asset", "$this->directory/www/asset");
		(new ProcessRunner())->run(["git", "init", "--quiet"], $this->directory);
		file_put_contents("$this->directory/line\nbreak.txt", "unusual name");
		file_put_contents("$this->directory/deleted.txt", "deleted");
		file_put_contents("$this->directory/config.dev.ini", "secret");
		(new ProcessRunner())->run(["git", "add", "asset", "line\nbreak.txt", "deleted.txt", "config.dev.ini"], $this->directory);
		unlink("$this->directory/deleted.txt");
		file_put_contents("$this->directory/asset/example.txt", "edited asset");
		file_put_contents("$this->directory/untracked.txt", "untracked");
		$sut = new Deployer();

		$package = $sut->deploy($this->directory);
		try {
			$archive = new PharData($package["archive"]);
			self::assertSame("edited asset", $archive["asset/example.txt"]->getContent());
			self::assertFalse(isset($archive["www/asset"]));
			self::assertFalse(isset($archive["untracked.txt"]));
			self::assertFalse(isset($archive["deleted.txt"]));
			self::assertFalse(isset($archive["config.dev.ini"]));
			mkdir("$this->directory/extracted");
			(new ProcessRunner())->run(["tar", "-xzf", $package["archive"], "-C", "extracted"], $this->directory);
			self::assertSame("unusual name", file_get_contents("$this->directory/extracted/line\nbreak.txt"));
		}
		finally {
			unlink($package["archive"]);
		}
	}

	public function testDeploy_keepsRealPublicAssetDirectory():void {
		mkdir("$this->directory/www/asset", 0700, true);
		file_put_contents("$this->directory/www/asset/example.txt", "public asset");
		$sut = new Deployer();

		$package = $sut->deploy($this->directory);
		try {
			$archive = new PharData($package["archive"]);
			self::assertSame("public asset", $archive["www/asset/example.txt"]->getContent());
		}
		finally {
			unlink($package["archive"]);
		}
	}

	public function testDeploy_gitRejectsSymlinkedParent():void {
		mkdir("$this->directory/source");
		file_put_contents("$this->directory/source/file.txt", "source");
		$runner = new ProcessRunner();
		$runner->run(["git", "init", "--quiet"], $this->directory);
		$runner->run(["git", "add", "source"], $this->directory);
		rename("$this->directory/source", "$this->directory/outside");
		symlink("outside", "$this->directory/source");
		$sut = new Deployer();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("must not contain symlinks");
		$sut->deploy($this->directory);
	}
	public function testDeploy_symlinksFailWithoutGit():void {
		file_put_contents("$this->directory/source.txt", "source");
		symlink("source.txt", "$this->directory/link");
		$sut = new Deployer();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("must not contain symlinks");
		$sut->deploy($this->directory);
	}
	public function testDeploy_packagingFailureRemovesPartialArchive():void {
		$archive = "";
		$runner = $this->createMock(ProcessRunner::class);
		$runner->expects(self::once())->method("run")->willReturnCallback(function(array $command) use (&$archive):void {
			$archive = $command[2];
			throw new RuntimeException("Packaging failed");
		});
		$sut = new Deployer($runner);
		try {
			$sut->deploy($this->directory);
			self::fail("Expected packaging failure");
		}
		catch(RuntimeException $exception) {
			self::assertSame("Packaging failed", $exception->getMessage());
		}
		self::assertFileDoesNotExist($archive);
	}
}
