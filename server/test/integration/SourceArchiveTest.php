<?php
namespace App\Test\Integration;

use App\SourceArchive;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use RuntimeException;

class SourceArchiveTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/deploy-extract-" . bin2hex(random_bytes(8));
		mkdir("$this->directory/stage", 0700, true);
	}

	protected function tearDown():void {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($files as $file) {
			if($file->isLink() || !$file->isDir()) {
				unlink($file->getPathname());
			}
			else {
				rmdir($file->getPathname());
			}
		}
		rmdir($this->directory);
	}

	public function testExtract_preservesLongAndUnusualFilenamesAndOwnerExecute():void {
		$name = "data/cache/articles/" . str_repeat("long-title-", 15) . ".md";
		$archive = $this->archive([
			["name" => $name, "data" => "article"],
			["name" => "space and \\slash.txt", "data" => "backslash"],
			["name" => "line\nbreak.txt", "data" => "newline"],
			["name" => "build.sh", "data" => "script", "mode" => 07777],
		]);
		$sut = new SourceArchive();

		$sut->extract($archive, "$this->directory/stage");

		self::assertSame("article", file_get_contents("$this->directory/stage/$name"));
		self::assertSame("backslash", file_get_contents("$this->directory/stage/space and \\slash.txt"));
		self::assertSame("newline", file_get_contents("$this->directory/stage/line\nbreak.txt"));
		self::assertSame(0700, fileperms("$this->directory/stage/build.sh") & 07777);
	}

	#[DataProvider("unsafeEntries")]
	public function testExtract_rejectsUnsafeEntries(array $entries):void {
		$archive = $this->archive($entries);
		$sut = new SourceArchive();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(422);
		$sut->extract($archive, "$this->directory/stage");
	}

	public function testExtract_rejectsAbsolutePaths():void {
		$archive = $this->archive([["name" => "$this->directory/outside.txt", "data" => "outside"]]);
		$sut = new SourceArchive();

		try {
			$sut->extract($archive, "$this->directory/stage");
			self::fail("Expected absolute path to fail.");
		}
		catch(RuntimeException $exception) {
			self::assertSame(422, $exception->getCode());
		}

		self::assertFileDoesNotExist("$this->directory/outside.txt");
	}

	public function testExtract_rejectsOversizedDeclaredFile():void {
		$archive = $this->archive([["name" => "huge", "size" => SourceArchive::MAX_BYTES + 1]]);
		$sut = new SourceArchive();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(422);
		$sut->extract($archive, "$this->directory/stage");
	}

	public function testExtract_rejectsTooManyEntries():void {
		$archive = $this->archive([["name" => ".", "type" => "directory", "repeat" => SourceArchive::MAX_ENTRIES + 1]]);
		$sut = new SourceArchive();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(422);
		$sut->extract($archive, "$this->directory/stage");
	}

	public function testDiscard_doesNotFollowLinksOutsideTheSource():void {
		file_put_contents("$this->directory/outside.txt", "preserved");
		symlink("$this->directory/outside.txt", "$this->directory/stage/link");
		$sut = new SourceArchive();

		$sut->discard("$this->directory/stage");

		self::assertDirectoryDoesNotExist("$this->directory/stage");
		self::assertSame("preserved", file_get_contents("$this->directory/outside.txt"));
	}

	/** @return list<array{list<array<string, mixed>>}> */
	public static function unsafeEntries():array {
		return [
			[[["name" => "../escaped.txt", "data" => "outside"]]],
			[[["name" => "link", "type" => "symlink", "target" => "../escaped.txt"]]],
			[[["name" => "link", "type" => "hardlink", "target" => "../escaped.txt"]]],
			[[["name" => "pipe", "type" => "fifo"]]],
			[[["name" => "device", "type" => "device"]]],
			[[["name" => "duplicate", "data" => "first"], ["name" => "duplicate", "data" => "second"]]],
			[[["name" => "truncated", "size" => 100000]]],
		];
	}

	/** @param list<array<string, mixed>> $entries */
	private function archive(array $entries):string {
		$tar = "";
		$types = ["directory" => "5", "symlink" => "2", "hardlink" => "1", "fifo" => "6", "device" => "3"];
		foreach($entries as $entry) {
			$name = $entry["name"];
			$data = $entry["data"] ?? "";
			$type = $types[$entry["type"] ?? "file"] ?? "0";
			$member = "";
			if(strlen($name) > 100) {
				$longName = $name . "\0";
				$member .= $this->header("././@LongLink", strlen($longName), "L", 0600, "");
				$member .= $longName . str_repeat("\0", (512 - strlen($longName) % 512) % 512);
			}
			$member .= $this->header($name, $entry["size"] ?? strlen($data), $type, $entry["mode"] ?? 0600, $entry["target"] ?? "");
			$member .= $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
			$tar .= str_repeat($member, $entry["repeat"] ?? 1);
		}
		$path = "$this->directory/source.tar.gz";
		file_put_contents($path, gzencode($tar . str_repeat("\0", 1024)));
		return $path;
	}

	private function header(string $name, int $size, string $type, int $mode, string $target):string {
		// POSIX ustar fixture header; checksum is calculated with eight spaces in its field.
		$header = pack("a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12",
			$name, sprintf("%07o", $mode), "0000000", "0000000", sprintf("%011o", $size),
			"00000000000", "        ", $type, $target, "ustar\0", "00", "", "", "", "", "", "",
		);
		return substr_replace($header, sprintf("%06o\0 ", array_sum(unpack("C*", $header))), 148, 8);
	}
}
