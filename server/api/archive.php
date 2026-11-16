<?php
use App\ApplicationRepository;
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Input\Input;
use GT\Json\Schema\JSONDocument;

function go(Request $request, Input $input, JSONDocument $document, ResponseHeaders $headers):void {
	$headers->set("Cache-Control", "no-store");
	if($request->getMethod() !== "POST") {
		$headers->set("Allow", "POST");
		$document->error("Use POST to upload an archive.", 405);
		return;
	}

	$authorization = $request->getHeaderLine("Authorization");
	if(!str_starts_with($authorization, "Bearer ")) {
		$document->error("Expected a bearer token.", 401);
		return;
	}

	if($request->getHeaderLine("Content-Type") !== "application/gzip") {
		$document->error("Expected Content-Type: application/gzip.", 415);
		return;
	}

	$length = $request->getHeaderLine("Content-Length");
	if($length !== "" && !ctype_digit($length)) {
		$document->error("Invalid Content-Length.", 400);
		return;
	}

	try {
		$reservation = (new ApplicationRepository())->upload(
			substr($authorization, 7), $input->getStream(), $length === "" ? null : (int)$length,
		);
	}
	catch(RuntimeException $exception) {
		$document->error($exception->getMessage(), $exception->getCode() ?: 500);
		return;
	}

	$document->set("url", $reservation->url);
	$document->set("status", $reservation->status);
	$document->set("uploaded_bytes", $reservation->uploadedBytes);
}
