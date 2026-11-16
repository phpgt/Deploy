<?php
use App\ApplicationRepository;
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Json\Schema\JSONDocument;

function go(Request $request, JSONDocument $document, ResponseHeaders $headers):void {
	$headers->set("Cache-Control", "no-store");
	if($request->getMethod() !== "POST") {
		$headers->set("Allow", "POST");
		$document->error("Use POST to initialise a deployment.", 405);
		return;
	}

	$authorization = $request->getHeaderLine("Authorization");
	if(!str_starts_with($authorization, "Bearer ")) {
		$document->error("Expected a bearer token.", 401);
		return;
	}

	try {
		$reservation = (new ApplicationRepository())->initialise(substr($authorization, 7));
	}
	catch(RuntimeException $exception) {
		$document->error($exception->getMessage(), $exception->getCode() ?: 500);
		return;
	}

	$document->set("url", $reservation->url);
	$document->set("status", $reservation->status);
}
