<?php
namespace App;

readonly class BuildStatus {
	public function __construct(public string $status, public ?string $error = null) {
	}
}
