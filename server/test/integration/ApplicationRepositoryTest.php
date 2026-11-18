<?php
namespace App\Test\Integration;

use App\ApplicationRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use GT\Ulid\Ulid;
use GT\Http\Stream;

class ApplicationRepositoryTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/deploy-reservation-test-" . bin2hex(random_bytes(8));
		mkdir($this->directory);
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

	public function testCreateDeployment_usesConfiguredPublicOrigin():void {
		$sut = new ApplicationRepository("$this->directory/deployment", "deploy.localhost", "http", 8080);
		$reservation = $sut->createDeployment();
		self::assertSame("http://$reservation->id.deploy.localhost:8080", $reservation->url);
	}

	public function testGetRoute_isPendingUntilActivationAndKeepsTheActivePortAfterFailure():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$host = "$reservation->id.deploy.php.gt";
		self::assertSame("reserved", $sut->getRoute($host)->status);
		self::assertNull($sut->getRoute($host)->port);
		$path = "$this->directory/deployment/$reservation->id";
		file_put_contents("$path/runtime.json", '{"port":12345}');
		self::assertSame(12345, $sut->getRoute($host)->port);
		$record = json_decode(file_get_contents("$path/reservation.json"), true);
		$record["status"] = "failed";
		file_put_contents("$path/reservation.json", json_encode($record));
		self::assertSame(12345, $sut->getRoute($host)->port);
		self::assertSame("failed", $sut->getRoute($host)->status);
	}

	public function testGetRoute_rejectsUnknownHostsAndInvalidPorts():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		self::assertNull($sut->getRoute("$reservation->id.attacker.example"));
		self::assertNull($sut->getRoute("../../etc/passwd.deploy.php.gt"));
		self::assertNull($sut->getRoute("0000000000000000.deploy.php.gt"));
		file_put_contents("$this->directory/deployment/$reservation->id/runtime.json", '{"port":"127.0.0.1:9000/attack"}');
		self::assertNull($sut->getRoute("$reservation->id.deploy.php.gt"));
	}

	public function testGetRoute_doesNotServeExpiredDeployments():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$filename = "$this->directory/deployment/$reservation->id/reservation.json";
		$record = json_decode(file_get_contents($filename), true);
		$record["expires_at"] = gmdate("c", time() - 1);
		file_put_contents($filename, json_encode($record));
		self::assertNull($sut->getRoute("$reservation->id.deploy.php.gt"));
	}

	public function testCreateDeployment_persistsDistinctReservations():void {
		$sut = new ApplicationRepository("$this->directory/deployment");

		$first = $sut->createDeployment();
		$firstPath = "$this->directory/deployment/$first->id/reservation.json";
		$original = file_get_contents($firstPath);
		$second = $sut->createDeployment();

		self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $first->id);
		self::assertNotSame($first->id, $second->id);
		self::assertSame("https://$first->id.deploy.php.gt", $first->url);
		self::assertSame("reserved", $first->status);
		self::assertSame($original, file_get_contents($firstPath));
		self::assertSame([
			"id" => $first->id,
			"url" => $first->url,
			"status" => $first->status,
			"created_at" => $first->createdAt,
			"expires_at" => $first->expiresAt,
			"token_hash" => hash("sha256", $first->token),
			"uploaded_bytes" => 0,
			"archive" => null,
			"source" => null,
		], json_decode($original, true, flags: JSON_THROW_ON_ERROR));
		self::assertFileExists("$this->directory/deployment/$second->id/reservation.json");
		self::assertStringNotContainsString($first->token, $original);
		self::assertSame(42, strlen($first->token));
		self::assertEqualsWithDelta(time() * 1000, (new Ulid(init: $first->token))->getTimestamp(), 2000);
	}

	public function testGetConfiguredKeys_returnsNamesOnlyForTheAuthenticatedDeployment():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		file_put_contents("$this->directory/deployment/$reservation->id/runtime.json", '{"port":12345,"configuration_keys":["database.driver","mail.api_key"]}');
		self::assertSame(["database.driver", "mail.api_key"], $sut->getConfiguredKeys($reservation->token));
	}

	public function testGetConfiguredKeys_requiresRuntimeMetadata():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(409);
		$sut->getConfiguredKeys($reservation->token);
	}

	public function testConfigureNext_appliesOwnedJobAndRemovesItsSecretPayload():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$path = "$this->directory/deployment/$reservation->id";
		file_put_contents("$path/runtime.json", '{"port":12345,"release":"source-test","configuration_keys":[]}');
		$values = new \App\ConfigurationValues('{"values":{"email.api_key":"private-job-secret"}}');
		$job = $sut->queueConfiguration($reservation->token, $values);
		self::assertSame("queued", $sut->getConfigurationUpdate($reservation->token, $job->id)->status);
		$file = "$path/configuration/$job->id.json";
		self::assertSame(0600, fileperms($file) & 0777);
		$runtime = $this->createMock(\App\DockerRuntime::class);
		$runtime->expects(self::once())->method("configure")->with($path, $reservation->id, ["email.api_key" => "private-job-secret"]);
		self::assertTrue($sut->configureNext($runtime));
		self::assertSame("applied", $sut->getConfigurationUpdate($reservation->token, $job->id)->status);
		self::assertStringNotContainsString("private-job-secret", file_get_contents($file));
		self::assertFalse($sut->configureNext($runtime));
	}

	public function testGetConfigurationUpdate_doesNotExposeAnotherDeploymentsJob():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$first = $sut->createDeployment();
		$second = $sut->createDeployment();
		file_put_contents("$this->directory/deployment/$first->id/runtime.json", '{"release":"source-test"}');
		$job = $sut->queueConfiguration($first->token, new \App\ConfigurationValues('{"values":{"email.key":"private"}}'));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);
		$sut->getConfigurationUpdate($second->token, $job->id);
	}

	public function testConfigureNext_failureRemovesPayloadAndReportsSafeError():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$path = "$this->directory/deployment/$reservation->id";
		file_put_contents("$path/runtime.json", '{"release":"source-test"}');
		$job = $sut->queueConfiguration($reservation->token, new \App\ConfigurationValues('{"values":{"email.key":"private"}}'));
		$runtime = $this->createMock(\App\DockerRuntime::class);
		$runtime->expects(self::once())->method("configure")->willThrowException(new RuntimeException("private"));
		$sut->configureNext($runtime);
		$result = $sut->getConfigurationUpdate($reservation->token, $job->id);
		self::assertSame("failed", $result->status);
		self::assertStringNotContainsString("private", $result->error);
		self::assertStringNotContainsString("private", file_get_contents("$path/configuration/$job->id.json"));
	}

	public function testGetByToken_returnsTheSameReservation():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();

		$existing = $sut->getByToken($reservation->token);

		self::assertEquals($reservation, $existing);
		self::assertCount(1, glob("$this->directory/deployment/*"));
	}

	public function testGetByToken_rejectsUnknownToken():void {
		$sut = new ApplicationRepository("$this->directory/deployment");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(401);
		$sut->getByToken((string)new Ulid(length: 42));
	}

	public function testGetByToken_rejectsExpiredToken():void {
		$token = (string)new Ulid(timestamp: (time() - 86401) * 1000, length: 42);
		$deploymentId = substr(hash("sha256", $token), 0, 16);
		$path = "$this->directory/deployment/$deploymentId";
		mkdir($path, 0700, true);
		file_put_contents("$path/reservation.json", json_encode(["token_hash" => hash("sha256", $token)], JSON_THROW_ON_ERROR));
		$sut = new ApplicationRepository("$this->directory/deployment");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(410);
		$sut->getByToken($token);
	}

	public function testUpload_replacesArchiveForSameDeployment():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$first = gzencode("first source");
		$second = gzencode("second source");

		$original = $sut->upload($reservation->token, $this->archiveStream($first), strlen($first));
		$updated = $sut->upload($reservation->token, $this->archiveStream($second), strlen($second));

		self::assertSame($original->url, $updated->url);
		self::assertSame($original->id, $updated->id);
		self::assertSame("uploaded", $updated->status);
		self::assertSame(strlen($second), $updated->uploadedBytes);
		self::assertFileDoesNotExist("$this->directory/deployment/$original->id/$original->archive");
		self::assertSame($second, file_get_contents("$this->directory/deployment/$updated->id/$updated->archive"));
		self::assertCount(1, glob("$this->directory/deployment/*"));
	}

	public function testUpload_incompleteUploadPreservesPreviousArchive():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$body = gzencode("source");
		$uploaded = $sut->upload($reservation->token, $this->archiveStream($body), strlen($body));

		try {
			$sut->upload($reservation->token, $this->archiveStream($body), strlen($body) + 1);
			self::fail("Expected incomplete upload to fail.");
		}
		catch(RuntimeException $exception) {
			self::assertSame(400, $exception->getCode());
		}

		self::assertEquals($uploaded, $sut->getByToken($reservation->token));
		self::assertSame($body, file_get_contents("$this->directory/deployment/$uploaded->id/$uploaded->archive"));
		self::assertSame([], glob("$this->directory/deployment/$uploaded->id/*.part"));
	}

	public function testUpload_rejectsOversizedUpload():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(413);
		$sut->upload($reservation->token, $this->archiveStream(""), ApplicationRepository::MAX_ARCHIVE_BYTES + 1);
	}

	public function testUpload_rejectsNonGzipAndRemovesPartial():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();

		try {
			$sut->upload($reservation->token, $this->archiveStream("invalid"), 7);
			self::fail("Expected non-gzip upload to fail.");
		}
		catch(RuntimeException $exception) {
			self::assertSame(400, $exception->getCode());
		}

		self::assertEquals($reservation, $sut->getByToken($reservation->token));
		self::assertSame([], glob("$this->directory/deployment/$reservation->id/*.part"));
	}

	public function testInitialise_extractsSourceAndIsIdempotent():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$archive = $this->sourceArchive("original source");
		$sut->upload($reservation->token, $this->archiveStream($archive), strlen($archive));

		$prepared = $sut->initialise($reservation->token);
		$again = $sut->initialise($reservation->token);

		self::assertSame("prepared", $prepared->status);
		self::assertEquals($prepared, $again);
		self::assertSame("original source", file_get_contents("$this->directory/deployment/$prepared->id/$prepared->source/page.html"));
	}

	public function testInitialise_replacesSourceWithoutLeavingDeletedFiles():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$archive = $this->sourceArchive("first source", "removed.html");
		$sut->upload($reservation->token, $this->archiveStream($archive), strlen($archive));
		$first = $sut->initialise($reservation->token);
		$archive = $this->sourceArchive("second source");
		$sut->upload($reservation->token, $this->archiveStream($archive), strlen($archive));

		$second = $sut->initialise($reservation->token);

		self::assertSame($first->url, $second->url);
		self::assertDirectoryDoesNotExist("$this->directory/deployment/$first->id/$first->source");
		self::assertFileDoesNotExist("$this->directory/deployment/$second->id/$second->source/removed.html");
		self::assertSame("second source", file_get_contents("$this->directory/deployment/$second->id/$second->source/page.html"));
	}

	public function testInitialise_badArchivePreservesPreviousSource():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$archive = $this->sourceArchive("original source");
		$sut->upload($reservation->token, $this->archiveStream($archive), strlen($archive));
		$first = $sut->initialise($reservation->token);
		$bad = gzencode("not a tar archive");
		$sut->upload($reservation->token, $this->archiveStream($bad), strlen($bad));

		try {
			$sut->initialise($reservation->token);
			self::fail("Expected corrupt archive to fail.");
		}
		catch(RuntimeException $exception) {
			self::assertSame(422, $exception->getCode());
		}

		$current = $sut->getByToken($reservation->token);
		self::assertSame("uploaded", $current->status);
		self::assertSame($first->source, $current->source);
		self::assertSame("original source", file_get_contents("$this->directory/deployment/$first->id/$first->source/page.html"));
		self::assertSame([], glob("$this->directory/deployment/$first->id/.extract-*"));
	}

	public function testInitialise_acceptsExistingRecordWithoutSourceField():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$archive = $this->sourceArchive("existing deployment");
		$sut->upload($reservation->token, $this->archiveStream($archive), strlen($archive));
		$path = "$this->directory/deployment/$reservation->id/reservation.json";
		$record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		unset($record["source"]);
		file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));

		$prepared = $sut->initialise($reservation->token);

		self::assertSame("prepared", $prepared->status);
		self::assertSame("existing deployment", file_get_contents("$this->directory/deployment/$prepared->id/$prepared->source/page.html"));
	}

	public function testInitialise_requiresAnUpload():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(409);
		$sut->initialise($reservation->token);
	}

	public function testQueueBuild_requiresPreparedSource():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(409);
		$sut->queueBuild($reservation->token);
	}

	public function testBuildNext_claimsAndDeploysQueuedSource():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$archive = $this->sourceArchive("source");
		$sut->upload($reservation->token, $this->archiveStream($archive));
		$prepared = $sut->initialise($reservation->token);
		self::assertSame("queued", $sut->queueBuild($reservation->token)->status);
		$runtime = $this->createMock(\App\DockerRuntime::class);
		$runtime->expects(self::once())->method("deploy")->with(
			"$this->directory/deployment/$reservation->id", $prepared->source, $reservation->id,
		)->willReturnCallback(function() use ($sut, $reservation):void {
			self::assertSame("building", $sut->getBuildStatus($reservation->token)->status);
			try {
				$sut->upload($reservation->token, $this->archiveStream(gzencode("new source")));
				self::fail("Upload must be locked during a build.");
			}
			catch(RuntimeException $exception) {
				self::assertSame(409, $exception->getCode());
			}
		});
		self::assertTrue($sut->buildNext($runtime));
		self::assertSame("deployed", $sut->getBuildStatus($reservation->token)->status);
		self::assertSame("deployed", $sut->queueBuild($reservation->token)->status);
		self::assertFalse($sut->buildNext($runtime));
		self::assertSame($reservation->id, $sut->getByToken($reservation->token)->id);
	}

	public function testBuildNext_failureIsRecordedAndCanBeRetried():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$sut->upload($reservation->token, $this->archiveStream($this->sourceArchive("source")));
		$sut->initialise($reservation->token);
		$sut->queueBuild($reservation->token);
		$runtime = $this->createMock(\App\DockerRuntime::class);
		$runtime->expects(self::exactly(2))->method("deploy")->willReturnCallback(function():void {
			static $attempt = 0;
			if($attempt++ === 0) {
				throw new RuntimeException("Private build details");
			}
		});
		self::assertTrue($sut->buildNext($runtime));
		self::assertSame("failed", $sut->getBuildStatus($reservation->token)->status);
		self::assertStringNotContainsString("Private", $sut->getBuildStatus($reservation->token)->error);
		$sut->queueBuild($reservation->token);
		self::assertTrue($sut->buildNext($runtime));
		self::assertSame("deployed", $sut->getBuildStatus($reservation->token)->status);
	}

	public function testQueueBuild_doesNotBuildOldSourceAfterANewUpload():void {
		$sut = new ApplicationRepository("$this->directory/deployment");
		$reservation = $sut->createDeployment();
		$sut->upload($reservation->token, $this->archiveStream($this->sourceArchive("first")));
		$sut->initialise($reservation->token);
		$sut->upload($reservation->token, $this->archiveStream($this->sourceArchive("second")));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(409);
		$sut->queueBuild($reservation->token);
	}

	private function sourceArchive(string $contents, ?string $extra = null):string {
		$source = "$this->directory/fixture-" . bin2hex(random_bytes(4));
		mkdir($source);
		file_put_contents("$source/page.html", $contents);
		if($extra !== null) {
			file_put_contents("$source/$extra", "removed on next deployment");
		}
		$process = proc_open(["tar", "-czf", "$source.tar.gz", "-C", $source, "."], [STDIN, STDOUT, STDERR], $pipes);
		unset($pipes);
		self::assertSame(0, proc_close($process));
		return file_get_contents("$source.tar.gz");
	}

	private function archiveStream(string $contents):Stream {
		$stream = new Stream();
		$stream->write($contents);
		$stream->rewind();
		return $stream;
	}

	public function testCreateDeployment_directoryPathIsAFile():void {
		$path = "$this->directory/deployment";
		file_put_contents($path, "existing data");
		$sut = new ApplicationRepository($path);

		try {
			$sut->createDeployment();
			self::fail("Expected a blocked reservation directory to fail.");
		}
		catch(RuntimeException $exception) {
			self::assertSame("The reservation directory path is already a file.", $exception->getMessage());
		}

		self::assertSame("existing data", file_get_contents($path));
	}
}
