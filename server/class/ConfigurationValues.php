<?php
namespace App;

use JsonException;
use RuntimeException;

readonly class ConfigurationValues {
	/** @var array<string, string> */
	public array $values;

	public function __construct(string $json) {
		if(strlen($json) > 65536) {
			throw new RuntimeException("Configuration payload exceeds 64 KiB.", 413);
		}
		try {
			$payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		}
		catch(JsonException) {
			throw new RuntimeException("Invalid JSON configuration payload.", 400);
		}
		$values = $payload["values"] ?? null;
		if(!is_array($values) || $values === [] || count($values) > 1000) {
			throw new RuntimeException("Expected between 1 and 1000 configuration values.", 400);
		}
		foreach($values as $key => $value) {
			$this->validateValue($key, $value);
		}
		$this->values = $values;
	}

	private function validateValue(mixed $key, mixed $value):void {
		if(!is_string($key) || !is_string($value)
		|| !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*\.[A-Za-z_][A-Za-z0-9_.-]*$/D', $key)
		|| str_starts_with($key, "deploy.") || $key === "app.production") {
			throw new RuntimeException("Configuration contains an invalid or managed key, or a non-string value.", 400);
		}
	}
}
