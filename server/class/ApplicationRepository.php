<?php
namespace App;

use GT\Ulid\Ulid;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

// Reservation, upload and build-state persistence share this repository and its locks.
/** @SuppressWarnings("PHPMD.ExcessiveClassComplexity") */
readonly class ApplicationRepository {
	const TOKEN_LIFETIME = 86400; //86400 is 1 day
	const MAX_ARCHIVE_BYTES = 104857600;

	public function __construct(
		private string $directory = "data/deployment",
		private string $domain = "deploy.php.gt",
		private string $scheme = "https",
		private ?int $port = null,
	) {
	}

	public function createDeployment():Reservation {
		$token = new Ulid(length: 42);
		$deploymentId = substr(hash("sha256", (string)$token), 0, 16);
		$path = "$this->directory/$deploymentId";
		if(file_exists($this->directory) && !is_dir($this->directory)) {
			throw new RuntimeException("The reservation directory path is already a file.");
		}

		if(!mkdir($path, 0700, true)) {
			throw new RuntimeException("Unable to create the reservation directory.");
		}

		$timestamp = intdiv($token->getTimestamp(), 1000);
		$reservation = new Reservation(
			$deploymentId,
			$this->deploymentUrl($deploymentId),
			"reserved",
			gmdate("c", $timestamp),
			(string)$token,
			gmdate("c", $timestamp + self::TOKEN_LIFETIME),
		);
		$this->save($reservation);
		return $reservation;
	}

	private function deploymentUrl(string $id):string {
		$port = $this->port === null || ($this->scheme === "https" && $this->port === 443)
			|| ($this->scheme === "http" && $this->port === 80) ? "" : ":$this->port";
		return "$this->scheme://$id.$this->domain$port";
	}

	public function getRoute(string $host):?DeploymentRoute {
		if(!preg_match('/^([a-f0-9]{16})\.' . preg_quote($this->domain, '/') . '$/D', strtolower($host), $matches)) {
			return null;
		}
		$path = "$this->directory/$matches[1]";
		if(!is_file("$path/reservation.json")) {
			return null;
		}
		$record = $this->readRecord($path);
		if(strtotime($record["expires_at"]) <= time()) {
			return null;
		}
		$port = null;
		if(is_file("$path/runtime.json")) {
			$runtime = json_decode((string)file_get_contents("$path/runtime.json"), true, flags: JSON_THROW_ON_ERROR);
			$port = filter_var($runtime["port"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]);
			if($port === false) {
				return null;
			}
		}
		return new DeploymentRoute($record["status"], $port);
	}

	public function getByToken(string $token):Reservation {
		if(!preg_match('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{42}$/D', $token)) {
			throw new RuntimeException("Invalid deployment token.", 401);
		}

		$tokenHash = hash("sha256", $token);
		$deploymentId = substr($tokenHash, 0, 16);
		$path = "$this->directory/$deploymentId/reservation.json";
		if(!is_file($path)) {
			throw new RuntimeException("Invalid deployment token.", 401);
		}

		$record = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		if(!hash_equals($record["token_hash"] ?? "", $tokenHash)) {
			throw new RuntimeException("Invalid deployment token.", 401);
		}

		$timestamp = intdiv((new Ulid(init: $token))->getTimestamp(), 1000);
		if($timestamp > time() + 1 || $timestamp + self::TOKEN_LIFETIME <= time()) {
			throw new RuntimeException("The anonymous deployment has expired.", 410);
		}

		return new Reservation(
			$record["id"], $record["url"], $record["status"],
			$record["created_at"], $token, $record["expires_at"],
			$record["uploaded_bytes"], $record["archive"], $record["source"] ?? null,
		);
	}

	public function upload(string $token, StreamInterface $input, ?int $length = null):Reservation {
		$reservation = $this->getByToken($token);
		if($length !== null && $length > self::MAX_ARCHIVE_BYTES) {
			throw new RuntimeException("Archive exceeds the 100 MiB upload limit.", 413);
		}

		$path = "$this->directory/$reservation->id";
		$lock = fopen("$path/upload.lock", "c");
		if($lock === false) {
			throw new RuntimeException("Unable to lock deployment storage.");
		}

		try {
			if(!flock($lock, LOCK_EX | LOCK_NB)) {
				throw new RuntimeException("Another upload is in progress.", 409);
			}

			return $this->receiveArchive($this->getByToken($token), $input, $length);
		}
		finally {
			fclose($lock);
		}
	}

	public function initialise(string $token):Reservation {
		$reservation = $this->getByToken($token);
		$lock = fopen("$this->directory/$reservation->id/upload.lock", "c");
		if($lock === false) {
			throw new RuntimeException("Unable to lock deployment storage.");
		}

		try {
			if(!flock($lock, LOCK_EX | LOCK_NB)) {
				throw new RuntimeException("Another deployment operation is in progress.", 409);
			}

			return $this->prepareSource($this->getByToken($token));
		}
		finally {
			fclose($lock);
		}
	}

	public function queueBuild(string $token):BuildStatus {
		$reservation = $this->getByToken($token);
		$path = "$this->directory/$reservation->id";
		$lock = fopen("$path/upload.lock", "c");
		if($lock === false) {
			throw new RuntimeException("Unable to lock deployment storage.");
		}

		try {
			if(!flock($lock, LOCK_EX | LOCK_NB)) {
				throw new RuntimeException("Another deployment operation is in progress.", 409);
			}

			$record = $this->readRecord($path);
			if(in_array($record["status"], ["queued", "building", "deployed"], true)) {
				return new BuildStatus($record["status"]);
			}
			if(!in_array($record["status"], ["prepared", "failed"], true)
			|| $record["source"] === null || !is_dir("$path/$record[source]")) {
				throw new RuntimeException("Prepare source before building deployment.", 409);
			}

			$record["status"] = "queued";
			$record["build_error"] = null;
			$this->writeRecord($path, $record);
			return new BuildStatus("queued");
		}
		finally {
			fclose($lock);
		}
	}

	/** @return list<string> */
	public function getConfiguredKeys(string $token):array {
		$reservation = $this->getByToken($token);
		$path = "$this->directory/$reservation->id/runtime.json";
		if(!is_file($path)) {
			throw new RuntimeException("Deploy the application before inspecting its configuration.", 409);
		}
		$runtime = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		$keys = $runtime["configuration_keys"] ?? null;
		if(!is_array($keys) || !array_is_list($keys) || count(array_filter($keys, "is_string")) !== count($keys)) {
			throw new RuntimeException("Redeploy the application to inspect its configuration.", 409);
		}
		return $keys;
	}

	public function queueConfiguration(string $token, ConfigurationValues $values):ConfigurationUpdate {
		$reservation = $this->getByToken($token);
		$path = "$this->directory/$reservation->id";
		$lock = fopen("$path/upload.lock", "c");
		if($lock === false) {
			throw new RuntimeException("Unable to lock deployment storage.");
		}
		try {
			if(!flock($lock, LOCK_EX | LOCK_NB)) {
				throw new RuntimeException("A deployment operation is in progress. Try again when it finishes.", 409);
			}
			$record = $this->readRecord($path);
			if(in_array($record["status"], ["queued", "building"], true) || !is_file("$path/runtime.json")) {
				throw new RuntimeException("Finish deploying the application before setting configuration.", 409);
			}
			$runtime = json_decode((string)file_get_contents("$path/runtime.json"), true, flags: JSON_THROW_ON_ERROR);
			$id = bin2hex(random_bytes(8));
			if(!is_dir("$path/configuration") && !mkdir("$path/configuration", 0700)) {
				throw new RuntimeException("Unable to create configuration storage.");
			}
			$this->writeConfigurationJob("$path/configuration/$id.json", [
				"id" => $id, "status" => "queued", "release" => $runtime["release"], "values" => $values->values,
			]);
			return new ConfigurationUpdate($id, $reservation->url, "queued");
		}
		finally {
			fclose($lock);
		}
	}

	public function getConfigurationUpdate(string $token, string $id):ConfigurationUpdate {
		$reservation = $this->getByToken($token);
		if(!preg_match('/^[a-f0-9]{16}$/D', $id)) {
			throw new RuntimeException("Invalid configuration job.", 400);
		}
		$file = "$this->directory/$reservation->id/configuration/$id.json";
		if(!is_file($file)) {
			throw new RuntimeException("Configuration job not found.", 404);
		}
		$job = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
		return new ConfigurationUpdate($id, $reservation->url, $job["status"], $job["error"] ?? null);
	}

	public function configureNext(DockerRuntime $runtime):bool {
		foreach(glob("$this->directory/*/configuration/*.json") ?: [] as $file) {
			$job = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
			if($job["status"] !== "queued") {
				continue;
			}
			$path = dirname($file, 2);
			$lock = fopen("$path/upload.lock", "c");
			if($lock === false) {
				throw new RuntimeException("Unable to lock deployment storage.");
			}
			try {
				if(!flock($lock, LOCK_EX | LOCK_NB)) {
					continue;
				}
				$job = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
				if($job["status"] === "queued") {
					$this->applyConfiguration($runtime, $path, $file, $job);
					return true;
				}
			}
			finally {
				fclose($lock);
			}
		}
		return false;
	}

	/** @param array<string, mixed> $job */
	private function applyConfiguration(DockerRuntime $runtime, string $path, string $file, array $job):void {
		try {
			$record = $this->readRecord($path);
			$current = json_decode((string)file_get_contents("$path/runtime.json"), true, flags: JSON_THROW_ON_ERROR);
			if(strtotime($record["expires_at"]) <= time() || $current["release"] !== $job["release"]) {
				throw new RuntimeException("The deployment changed or expired. Run gt deploy and try again.", 409);
			}
			$job["status"] = "applying";
			$this->writeConfigurationJob($file, $job);
			$runtime->configure($path, $record["id"], $job["values"]);
			$job["status"] = "applied";
		}
		catch(Throwable $exception) {
			$job["status"] = "failed";
			$job["error"] = $exception instanceof RuntimeException && $exception->getCode() === 409
				? $exception->getMessage() : "Configuration update failed. The previous configuration was retained.";
		}
		unset($job["values"]);
		$this->writeConfigurationJob($file, $job);
	}

	/** @param array<string, mixed> $job */
	private function writeConfigurationJob(string $file, array $job):void {
		$temporary = tempnam(dirname($file), ".configuration-");
		if($temporary === false) {
			throw new RuntimeException("Unable to stage configuration state.");
		}
		try {
			$json = json_encode($job, JSON_THROW_ON_ERROR);
			if(file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
				throw new RuntimeException("Unable to save configuration state.");
			}
		}
		finally {
			if(is_file($temporary)) {
				unlink($temporary);
			}
		}
	}

	public function getBuildStatus(string $token):BuildStatus {
		$reservation = $this->getByToken($token);
		$record = $this->readRecord("$this->directory/$reservation->id");
		return new BuildStatus($record["status"], $record["build_error"] ?? null);
	}

	public function buildNext(DockerRuntime $runtime):bool {
		foreach(glob("$this->directory/*/reservation.json") ?: [] as $filename) {
			if($this->readRecord(dirname($filename))["status"] !== "queued") {
				continue;
			}
			$path = dirname($filename);
			$lock = fopen("$path/upload.lock", "c");
			if($lock === false) {
				throw new RuntimeException("Unable to lock deployment storage.");
			}

			try {
				if(!flock($lock, LOCK_EX | LOCK_NB)) {
					continue;
				}
				$record = $this->readRecord($path);
				if($record["status"] !== "queued") {
					continue;
				}
				$this->runBuild($runtime, $path, $record);
				return true;
			}
			finally {
				fclose($lock);
			}
		}
		return false;
	}

	/** @param array<string, mixed> $record */
	private function runBuild(DockerRuntime $runtime, string $path, array $record):void {
		try {
			if(strtotime($record["expires_at"]) <= time()) {
				throw new RuntimeException("Deployment expired before the build could finish.");
			}
			$record["status"] = "building";
			$this->writeRecord($path, $record);
			$runtime->deploy($path, $record["source"], $record["id"]);
			$record["status"] = "deployed";
			$record["build_error"] = null;
		}
		catch(Throwable) {
			$record["status"] = "failed";
			$record["build_error"] = "Deployment build failed. See the server's build.log for details.";
		}
		$this->writeRecord($path, $record);
	}

	/** @return array<string, mixed> */
	private function readRecord(string $path):array {
		return json_decode((string)file_get_contents("$path/reservation.json"), true, flags: JSON_THROW_ON_ERROR);
	}

	/** @param array<string, mixed> $record */
	private function writeRecord(string $path, array $record):void {
		$temporary = "$path/reservation.json.part";
		$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if(file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, "$path/reservation.json")) {
			throw new RuntimeException("Unable to save the deployment.");
		}
	}

	private function prepareSource(Reservation $reservation):Reservation {
		$path = "$this->directory/$reservation->id";
		if($reservation->archive === null) {
			throw new RuntimeException("Upload a source archive before initialising deployment.", 409);
		}

		$name = basename($reservation->archive, ".tar.gz");
		if($reservation->source === $name && is_dir("$path/$name")) {
			return $reservation;
		}

		$stage = "$path/.extract-" . bin2hex(random_bytes(4)) . ".part";
		if(!mkdir($stage, 0700)) {
			throw new RuntimeException("Unable to create the source directory.");
		}

		$sourceArchive = new SourceArchive();
		try {
			$sourceArchive->extract("$path/$reservation->archive", $stage);
			if(!rename($stage, "$path/$name")) {
				throw new RuntimeException("Unable to publish extracted source.");
			}

			$prepared = new Reservation(
				$reservation->id, $reservation->url, "prepared", $reservation->createdAt,
				$reservation->token, $reservation->expiresAt, $reservation->uploadedBytes,
				$reservation->archive, $name,
			);
			$this->save($prepared);
		}
		catch(Throwable $exception) {
			$sourceArchive->discard($stage);
			$sourceArchive->discard("$path/$name");
			throw $exception;
		}

		if($reservation->source !== null && $reservation->source !== $name) {
			$sourceArchive->discard("$path/$reservation->source");
		}

		return $prepared;
	}

	private function receiveArchive(Reservation $reservation, StreamInterface $input, ?int $length):Reservation {
		$path = "$this->directory/$reservation->id";
		$name = "source-" . bin2hex(random_bytes(4)) . ".tar.gz";
		$partial = "$path/$name.part";
		$output = fopen($partial, "xb");
		if($output === false) {
			throw new RuntimeException("Unable to create upload file.");
		}

		$bytes = $this->copyArchive($input, $output, $partial, $length);

		$signature = file_get_contents($partial, length: 2);
		if($signature !== "\x1f\x8b") {
			unlink($partial);
			throw new RuntimeException("Expected a gzip archive.", 400);
		}

		if(!rename($partial, "$path/$name")) {
			unlink($partial);
			throw new RuntimeException("Unable to finish upload.");
		}

		$uploaded = new Reservation(
			$reservation->id, $reservation->url, "uploaded", $reservation->createdAt,
			$reservation->token, $reservation->expiresAt, $bytes, $name, $reservation->source,
		);
		try {
			$this->save($uploaded);
		}
		catch(Throwable $exception) {
			unlink("$path/$name");
			throw $exception;
		}

		if($reservation->archive !== null) {
			unlink("$path/$reservation->archive");
		}

		return $uploaded;
	}

	/** @param resource $output */
	private function copyArchive(StreamInterface $input, $output, string $partial, ?int $length):int {
		try {
			$bytes = 0;
			while(!$input->eof()) {
				$chunk = $input->read(65536);
				if($chunk === "") {
					break;
				}

				$bytes += strlen($chunk);
				if($bytes > self::MAX_ARCHIVE_BYTES) {
					throw new RuntimeException("Archive exceeds the 100 MiB upload limit.", 413);
				}

				if(fwrite($output, $chunk) !== strlen($chunk)) {
					throw new RuntimeException("Unable to save upload.");
				}
			}

			$this->verifyUploadLength($bytes, $length);
		}
		catch(Throwable $exception) {
			unlink($partial);
			throw $exception;
		}
		finally {
			fclose($output);
		}

		return $bytes;
	}

	private function verifyUploadLength(int $bytes, ?int $length):void {
		if($bytes === 0 || ($length !== null && $bytes !== $length)) {
			throw new RuntimeException("Upload was incomplete.", 400);
		}
	}

	private function save(Reservation $reservation):void {
		$record = [
			"id" => $reservation->id,
			"url" => $reservation->url,
			"status" => $reservation->status,
			"created_at" => $reservation->createdAt,
			"expires_at" => $reservation->expiresAt,
			"token_hash" => hash("sha256", $reservation->token),
			"uploaded_bytes" => $reservation->uploadedBytes,
			"archive" => $reservation->archive,
			"source" => $reservation->source,
		];
		$this->writeRecord("$this->directory/$reservation->id", $record);
	}
}
