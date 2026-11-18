<?php
namespace GT\Deploy;

use GT\Config\Config;
use InvalidArgumentException;

readonly class Settings {
	public string $server;
	public ?string $token;
	public Transport $transport;

	// tryFrom() is a built-in enum method.
	/** @SuppressWarnings("PHPMD.StaticAccess") */
	public function __construct(
		Config $config,
		public ?DeploymentEnvironment $environment = null,
		?Config $destination = null,
	) {
		$target = $environment === null ? $config : ($destination
			?? throw new InvalidArgumentException("An environment destination configuration is required."));
		$server = $target->getString("deploy.server");
		$transport = $target->getString("deploy.transport");
		if($environment !== null && ($server === null || $transport === null)) {
			throw new InvalidArgumentException("deploy.$environment->value.ini requires [deploy] server and transport.");
		}
		$this->server = $this->normaliseServer($server ?? "deploy.php.gt");
		$this->transport = Transport::tryFrom($transport ?? Transport::TarHttp->value)
			?? throw new InvalidArgumentException("Unsupported deploy.transport. Available transport: tar-http.");
		$prefix = $environment === null ? "deploy." : "deployment.{$environment->value}_";
		$this->token = $config->getString("{$prefix}token") ?: null;
		if($this->token !== null && $config->getString("{$prefix}token_server") !== $this->server) {
			throw new InvalidArgumentException("The deployment token belongs to a different server. Remove {$prefix}token and {$prefix}token_server to reserve a new deployment.");
		}
	}

	private function normaliseServer(string $server):string {
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
