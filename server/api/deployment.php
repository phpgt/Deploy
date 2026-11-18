<?php
use App\ApplicationRepository;
use GT\Config\Config;
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Json\Schema\JSONDocument;

function go(
	Request $request,
	Config $config,
	JSONDocument $document,
	ResponseHeaders $headers,
):void {
	$headers->set("Cache-Control", "no-store");

	if($request->getMethod() !== "POST") {
		$headers->set("Allow", "POST");
		$document->error("Use POST to reserve a deployment URL.", 405);
		return;
	}

	$repository = new ApplicationRepository(
		domain: $config->get("server.domain") ?? "deploy.php.gt",
		scheme: $config->get("server.scheme") ?? "https",
		port: $config->get("server.port") === null ? null : (int)$config->get("server.port"),
	);
	$authorization = $request->getHeaderLine("Authorization");
	if($authorization !== "" && !str_starts_with($authorization, "Bearer ")) {
		$document->error("Expected a bearer token.", 401);
		return;
	}

	try {
		$reservation = $authorization === ""
			? $repository->createDeployment()
			: $repository->getByToken(substr($authorization, 7));
	}
	catch(RuntimeException $exception) {
		$document->error($exception->getMessage(), $exception->getCode() ?: 500);
		return;
	}
	$document->set("id", $reservation->id);
	$document->set("url", $reservation->url);
	$document->set("status", $reservation->status);
	$document->set("created_at", $reservation->createdAt);
	$document->set("token", $reservation->token);
	$document->set("expires_at", $reservation->expiresAt);
	$document->set("upload_url", "archive/");
}
