<?php
namespace GT\Deploy;

use GT\Config\Config;
use InvalidArgumentException;

readonly class Settings {
	public string $server;
	public ?string $token;

	public function __construct(Config $config) {
		$this->server = $this->normaliseServer($config);
		$this->token = $config->getString("deploy.token") ?: null;
		if($this->token !== null && $config->getString("deploy.token_server") !== $this->server) {
			throw new InvalidArgumentException("The deployment token belongs to a different server. Remove deploy.token and deploy.token_server to reserve a new deployment.");
		}
	}

	private function normaliseServer(Config $config):string {
		// Existing WebEngine releases do not yet include the deploy section.
		$server = $config->getString("deploy.server") ?? "deploy.php.gt";
		if(!str_contains($server, "://")) {
			$server = "https://$server";
		}

		$parts = parse_url($server);
		if(!filter_var($server, FILTER_VALIDATE_URL)
		|| !is_array($parts)
		|| !in_array($parts["scheme"] ?? "", ["http", "https"], true)
		|| isset($parts["user"])
		|| isset($parts["query"])
		|| isset($parts["fragment"])) {
			throw new InvalidArgumentException("deploy.server must be an HTTP or HTTPS base URL without credentials, a query or a fragment.");
		}

		return rtrim($server, "/");
	}
}
