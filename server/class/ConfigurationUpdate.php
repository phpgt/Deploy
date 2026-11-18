<?php
namespace App;

readonly class ConfigurationUpdate {
	public function __construct(
		public string $id,
		public string $url,
		public string $status,
		public ?string $error = null,
	) {
	}
}
