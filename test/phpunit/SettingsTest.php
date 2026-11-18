<?php
namespace GT\Deploy\Test;

use GT\Config\Config;
use GT\Config\ConfigSection;
use GT\Deploy\Settings;
use GT\Deploy\Transport;
use GT\Deploy\DeploymentEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {
	#[DataProvider("environments")]
	public function testConstruct_environmentUsesOnlyItsDestinationAndToken(DeploymentEnvironment $environment):void {
		$config = new Config(
			new ConfigSection("deploy", ["server" => "https://default.example", "token" => "default-token"]),
			new ConfigSection("deployment", [
				"staging_token" => "stage-token", "staging_token_server" => "http://localhost:9090",
				"production_token" => "prod-token", "production_token_server" => "http://localhost:9090",
			]),
		);
		$destination = new Config(new ConfigSection("deploy", ["server" => "http://localhost:9090", "transport" => "tar-http"]));
		$sut = new Settings($config, $environment, $destination);
		self::assertSame("http://localhost:9090", $sut->server);
		self::assertSame($environment === DeploymentEnvironment::Staging ? "stage-token" : "prod-token", $sut->token);
		self::assertSame(Transport::TarHttp, $sut->transport);
	}

	/** @return list<array{DeploymentEnvironment}> */
	public static function environments():array {
		return [[DeploymentEnvironment::Staging], [DeploymentEnvironment::Production]];
	}

	public function testConstruct_environmentDoesNotReuseDefaultToken():void {
		$config = new Config(new ConfigSection("deploy", ["token" => "default-token"]));
		$destination = new Config(new ConfigSection("deploy", ["server" => "deploy.php.gt", "transport" => "tar-http"]));
		$sut = new Settings($config, DeploymentEnvironment::Production, $destination);
		self::assertNull($sut->token);
	}

	public function testConstruct_selectedEnvironmentRequiresDestination():void {
		$config = new Config(new ConfigSection("deploy", ["server" => "deploy.php.gt", "transport" => "tar-http"]));
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("environment destination configuration is required");
		$sut = new Settings($config, DeploymentEnvironment::Production);
	}

	public function testConstruct_environmentRequiresExplicitDestinationFields():void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("requires [deploy] server and transport");
		$sut = new Settings(new Config(), DeploymentEnvironment::Staging, new Config());
	}

	public function testConstruct_unsupportedTransport():void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Unsupported deploy.transport");
		$sut = new Settings(new Config(new ConfigSection("deploy", ["transport" => "rsync"])));
	}

	public function testConstruct_environmentTokenMustMatchDestinationServer():void {
		$config = new Config(new ConfigSection("deployment", ["production_token" => "private", "production_token_server" => "https://other.example"]));
		$destination = new Config(new ConfigSection("deploy", ["server" => "deploy.php.gt", "transport" => "tar-http"]));
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("deployment.production_token");
		$sut = new Settings($config, DeploymentEnvironment::Production, $destination);
	}

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
