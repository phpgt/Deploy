<?php
namespace App;

readonly class Reservation {
	public function __construct(
		public string $id,
		public string $url,
		public string $status,
		public string $createdAt,
		public string $token,
		public string $expiresAt,
		public int $uploadedBytes = 0,
		public ?string $archive = null,
		public ?string $source = null,
	) {
	}
}
