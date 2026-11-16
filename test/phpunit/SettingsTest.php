<?php
namespace GT\Deploy\Test;

use GT\Config\Config;
use GT\Config\ConfigSection;
use GT\Deploy\Settings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {
	public function testConstruct_defaultServer():void {
		$sut = new Settings(new Config());

		self::assertSame("https://deploy.php.gt", $sut->server);
	}

	public function testConstruct_localServer():void {
		$sut = new Settings(new Config(new ConfigSection("deploy", ["server" => "http://localhost:9090/"])));

		self::assertSame("http://localhost:9090", $sut->server);
	}

	public function testConstruct_preservesTokenForItsServer():void {
		$sut = new Settings(new Config(new ConfigSection("deploy", [
			"server" => "http://localhost:9090", "token" => "private", "token_server" => "http://localhost:9090",
		])));

		self::assertSame("private", $sut->token);
	}

	public function testConstruct_rejectsTokenForDifferentServer():void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new Settings(new Config(new ConfigSection("deploy", [
			"server" => "http://localhost:9090", "token" => "private", "token_server" => "https://deploy.php.gt",
		])));
	}

	#[DataProvider("serversWithoutScheme")]
	public function testConstruct_assumesHttps(string $server, string $expected):void {
		$sut = new Settings(new Config(new ConfigSection("deploy", ["server" => $server])));

		self::assertSame($expected, $sut->server);
	}

	/** @return list<array{string, string}> */
	public static function serversWithoutScheme():array {
		return [
			["deploy.php.gt", "https://deploy.php.gt"],
			["deploy.php.gt/", "https://deploy.php.gt"],
			["localhost:9090", "https://localhost:9090"],
			["deploy.php.gt/service", "https://deploy.php.gt/service"],
		];
	}

	#[DataProvider("invalidServers")]
	public function testConstruct_invalidServer(string $server):void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new Settings(new Config(new ConfigSection("deploy", ["server" => $server])));
	}

	/** @return list<array{string}> */
	public static function invalidServers():array {
		return [
			[""],
			["ftp://localhost"],
			["http://user:password@localhost"],
			["http://localhost?token=secret"],
			["http://localhost/#fragment"],
		];
	}
}
