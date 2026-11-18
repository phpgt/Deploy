<?php
use App\ApplicationRepository;
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Json\Schema\JSONDocument;

function go(Request $request, JSONDocument $document, ResponseHeaders $headers):void {
	$headers->set("Cache-Control", "no-store");
	$method = $request->getMethod();
	if(!in_array($method, ["POST", "GET"], true)) {
		$headers->set("Allow", "POST, GET");
		$document->error("Use POST to queue a build or GET for its status.", 405);
		return;
	}
	$authorization = $request->getHeaderLine("Authorization");
	if(!str_starts_with($authorization, "Bearer ")) {
		$document->error("Expected a bearer token.", 401);
		return;
	}

	try {
		$repository = new ApplicationRepository();
		$token = substr($authorization, 7);
		$result = $method === "POST" ? $repository->queueBuild($token) : $repository->getBuildStatus($token);
	}
	catch(RuntimeException $exception) {
		$document->error($exception->getMessage(), $exception->getCode() ?: 500);
		return;
	}
	$document->set("status", $result->status);
	if($result->error !== null) {
		$document->set("error", $result->error);
	}
}
