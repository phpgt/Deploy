<?php
namespace GT\Deploy\Test;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GT\Deploy\DeploymentClient;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DeploymentClientTest extends TestCase {
	public function testBuild_queuesAndPollsAuthenticatedStatus():void {
		$history = [];
		$handler = HandlerStack::create(new MockHandler([
			new Response(200, [], '{"status":"queued"}'),
			new Response(200, [], '{"status":"deployed"}'),
		]));
		$handler->push(\GuzzleHttp\Middleware::history($history));
		$sut = new DeploymentClient(new Client(["handler" => $handler]));
		$progress = [];
		$sut->build("https://deploy.php.gt", "secret-token", function(string $status) use (&$progress):void {
			$progress []= $status;
		});
		self::assertSame(["queued"], $progress);
		self::assertSame("POST", $history[0]["request"]->getMethod());
		self::assertSame("GET", $history[1]["request"]->getMethod());
		self::assertSame("Bearer secret-token", $history[1]["request"]->getHeaderLine("Authorization"));
	}

	public function testBuild_reportsFailureWithoutExposingToken():void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(200, [], '{"status":"failed","error":"Failed secret-token"}'));
		$sut = new DeploymentClient($client);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Failed [redacted]");
		$sut->build("https://deploy.php.gt", "secret-token", static function():void {
		});
	}

	public function testBuild_httpErrorMayHaveAnHtmlBody():void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(503, [], '<html>Unavailable</html>'));
		$sut = new DeploymentClient($client);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("HTTP 503");
		$sut->build("https://deploy.php.gt", "token", static function():void {
		});
	}

	public function testGetConfiguredKeys_usesAuthenticatedNamesOnlyEndpoint():void {
		$client = $this->createMock(ClientInterface::class);
		$client->expects(self::once())->method("request")->with("GET", "https://deploy.php.gt/configuration/", self::callback(
			fn(array $options):bool => $options["headers"]["Authorization"] === "Bearer private-token",
		))->willReturn(new Response(200, [], '{"keys":["mail.api_key"]}'));
		$sut = new DeploymentClient($client);
		self::assertSame(["mail.api_key"], $sut->getConfiguredKeys("https://deploy.php.gt", "private-token"));
	}

	public function testGetConfiguredKeys_rejectsInvalidResponse():void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(200, [], '{"keys":["mail.api_key",123]}'));
		$sut = new DeploymentClient($client);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("invalid configuration keys");
		$sut->getConfiguredKeys("https://deploy.php.gt", "token");
	}

	public function testSetConfiguration_sendsValuesInJsonAndPollsWithoutRepeatingThem():void {
		$history = [];
		$handler = HandlerStack::create(new MockHandler([
			new Response(200, [], '{"id":"aaaaaaaaaaaaaaaa","url":"https://app.deploy.php.gt","status":"queued"}'),
			new Response(200, [], '{"status":"applied"}'),
		]));
		$handler->push(\GuzzleHttp\Middleware::history($history));
		$sut = new DeploymentClient(new Client(["handler" => $handler]));
		$urls = [];
		$sut->setConfiguration("https://deploy.php.gt", "token", ["email.key" => "private"], function(string $url) use (&$urls):void {
			$urls []= $url;
		});
		self::assertSame(["https://app.deploy.php.gt"], $urls);
		self::assertSame(["values" => ["email.key" => "private"]], json_decode((string)$history[0]["request"]->getBody(), true));
		self::assertSame("Bearer token", $history[0]["request"]->getHeaderLine("Authorization"));
		self::assertSame("GET", $history[1]["request"]->getMethod());
		self::assertSame("", (string)$history[1]["request"]->getBody());
	}

	public function testReserve_expiredTokenCreatesANewAnonymousReservation():void {
		$history = [];
		$handler = HandlerStack::create(new MockHandler([
			new Response(410),
			new Response(200, [], json_encode([
				"url" => "https://fresh.deploy.php.gt", "token" => str_repeat("B", 42), "upload_url" => "archive/",
			], JSON_THROW_ON_ERROR)),
		]));
		$handler->push(\GuzzleHttp\Middleware::history($history));
		$sut = new DeploymentClient(new Client(["handler" => $handler]));
		$reservation = $sut->reserve("https://deploy.php.gt", str_repeat("A", 42));
		self::assertSame("https://fresh.deploy.php.gt", $reservation->url);
		self::assertSame(str_repeat("B", 42), $reservation->token);
		self::assertSame("Bearer " . str_repeat("A", 42), $history[0]["request"]->getHeaderLine("Authorization"));
		self::assertFalse($history[1]["request"]->hasHeader("Authorization"));
	}

	public function testReserve_doesNotRetryAnAnonymousGoneResponse():void {
		$client = $this->createMock(ClientInterface::class);
		$client->expects(self::once())->method("request")->willReturn(new Response(410));
		$sut = new DeploymentClient($client);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("HTTP 410");
		$sut->reserve("https://deploy.php.gt");
	}

	public function testReserve():void {
		$client = $this->createMock(ClientInterface::class);
		$client->expects(self::once())->method("request")->with(
			"POST",
			"http://localhost:9090/deployment/",
			self::callback(function(array $options):bool {
				return $options["headers"]["Accept"] === "application/json";
			}),
		)->willReturn(new Response(200, [], json_encode([
			"url" => "https://abc.deploy.php.gt", "token" => str_repeat("A", 42), "upload_url" => "archive/",
		], JSON_THROW_ON_ERROR)));
		$sut = new DeploymentClient($client);

		$reservation = $sut->reserve("http://localhost:9090");
		self::assertSame("https://abc.deploy.php.gt", $reservation->url);
		self::assertSame("http://localhost:9090/archive/", $reservation->uploadUrl);
	}

	public function testReserve_httpFailure():void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(503, [], '{"error":"Unavailable"}'));
		$sut = new DeploymentClient($client);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("HTTP 503");
		$sut->reserve("http://localhost:9090");
	}

	#[DataProvider("invalidReservations")]
	public function testReserve_invalidResponse(string $body):void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(200, [], $body));
		$sut = new DeploymentClient($client);

		$this->expectException(RuntimeException::class);
		$sut->reserve("http://localhost:9090");
	}

	public function testReserve_malformedJson():void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(200, [], "<html>Not JSON</html>"));
		$sut = new DeploymentClient($client);

		$this->expectException(JsonException::class);
		$sut->reserve("http://localhost:9090");
	}

	public function testReserve_existingTokenIsAuthenticated():void {
		$token = str_repeat("A", 42);
		$client = $this->createMock(ClientInterface::class);
		$client->expects(self::once())->method("request")->with("POST", "http://localhost:9090/deployment/", self::callback(
			fn(array $options):bool => $options["headers"]["Authorization"] === "Bearer $token",
		))->willReturn(new Response(200, [], json_encode([
			"url" => "https://abc.deploy.php.gt", "token" => $token, "upload_url" => "archive/",
		], JSON_THROW_ON_ERROR)));
		$sut = new DeploymentClient($client);

		self::assertSame($token, $sut->reserve("http://localhost:9090", $token)->token);
	}

	public function testReserve_rejectsUploadToAnotherServer():void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(200, [], json_encode([
			"url" => "https://abc.deploy.php.gt", "token" => str_repeat("A", 42), "upload_url" => "https://other.example/archive/",
		], JSON_THROW_ON_ERROR)));
		$sut = new DeploymentClient($client);

		$this->expectException(RuntimeException::class);
		$sut->reserve("http://localhost:9090");
	}

	public function testReserve_followsSameOrigin308WithPostAndToken():void {
		$token = str_repeat("A", 42);
		$handler = new MockHandler([
			new Response(308, ["Location" => "/canonical/"]),
			new Response(200, [], json_encode([
				"url" => "https://abc.deploy.php.gt", "token" => $token, "upload_url" => "archive/",
			], JSON_THROW_ON_ERROR)),
		]);
		$sut = new DeploymentClient(new Client(["handler" => HandlerStack::create($handler)]));

		$reservation = $sut->reserve("http://localhost:9090", $token);

		self::assertSame("https://abc.deploy.php.gt", $reservation->url);
		self::assertSame("POST", $handler->getLastRequest()->getMethod());
		self::assertSame("Bearer $token", $handler->getLastRequest()->getHeaderLine("Authorization"));
	}

	public function testReserve_doesNotRedirectTokenToAnotherOrigin():void {
		$handler = new MockHandler([new Response(308, ["Location" => "https://other.example/"])]);
		$sut = new DeploymentClient(new Client(["handler" => HandlerStack::create($handler)]));

		$this->expectException(RuntimeException::class);
		$sut->reserve("http://localhost:9090", str_repeat("A", 42));
	}

	public function testInitialise():void {
		$token = str_repeat("A", 42);
		$client = $this->createMock(ClientInterface::class);
		$client->expects(self::once())->method("request")->with("POST", "http://localhost:9090/initialise/", self::callback(
			fn(array $options):bool => $options["headers"]["Authorization"] === "Bearer $token",
		))->willReturn(new Response(200, [], '{"status":"prepared"}'));
		$sut = new DeploymentClient($client);

		$sut->initialise("http://localhost:9090", $token);
	}

	public function testInitialise_reportsFailure():void {
		$client = $this->createStub(ClientInterface::class);
		$client->method("request")->willReturn(new Response(422, [], '{"error":"Invalid source"}'));
		$sut = new DeploymentClient($client);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("HTTP 422");
		$sut->initialise("http://localhost:9090", str_repeat("A", 42));
	}

	/** @return list<array{string}> */
	public static function invalidReservations():array {
		return [['{}'], ['{"url":null}'], ['{"url":"file:///etc/passwd"}'], ['{"url":"invalid"}'], ['[]']];
	}
}
