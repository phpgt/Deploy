<?php
namespace App;

use GT\Ulid\Ulid;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

readonly class ApplicationRepository {
	const TOKEN_LIFETIME = 86400;
	const MAX_ARCHIVE_BYTES = 104857600;

	public function __construct(private string $directory = "data/deployment") {
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
			"https://$deploymentId.deploy.php.gt",
			"reserved",
			gmdate("c", $timestamp),
			(string)$token,
			gmdate("c", $timestamp + self::TOKEN_LIFETIME),
		);
		$this->save($reservation);
		return $reservation;
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
			throw new RuntimeException("Deployment token has expired. Remove deploy.token and deploy.token_server to reserve a new deployment.", 410);
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

	private function prepareSource(Reservation $reservation):Reservation {
		$path = "$this->directory/$reservation->id";
		if($reservation->archive === null) {
			throw new RuntimeException("Upload a source archive before initialising deployment.", 409);
		}

		$name = basename($reservation->archive, ".tar.gz");
		if($reservation->source === $name && is_dir("$path/$name")) {
			return $reservation;
		}

		$stage = "$path/.extract-" . bin2hex(random_bytes(8)) . ".part";
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
		$name = "source-" . bin2hex(random_bytes(8)) . ".tar.gz";
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
		$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$path = "$this->directory/$reservation->id/reservation.json";
		$temporary = "$path.part";
		if(file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
			if(is_file($temporary)) {
				unlink($temporary);
			}

			throw new RuntimeException("Unable to save the deployment.");
		}
	}
}
