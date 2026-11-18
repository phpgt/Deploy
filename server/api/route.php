<?php
use App\ApplicationRepository;
use GT\Config\Config;
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Json\Schema\JSONDocument;

function go(Request $request, Config $config, JSONDocument $document, ResponseHeaders $headers):void {
	$headers->set("Cache-Control", "no-store");
	$repository = new ApplicationRepository(domain: $config->get("server.domain") ?? "deploy.php.gt");
	$route = $repository->getRoute($request->getUri()->getHost());
	if($route === null) {
		$document->error("Unknown deployment.", 403);
		return;
	}
	$headers->set("X-Deployment-Status", $route->status);
	if($route->port === null) {
		$document->error("Deployment is not ready.", 401);
		return;
	}
	$headers->set("X-Deployment-Upstream", "127.0.0.1:$route->port");
	$document->set("status", $route->status);
}
