<?php
namespace App;

readonly class DeploymentRoute {
	public function __construct(public string $status, public ?int $port = null) {
	}
}
