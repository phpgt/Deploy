<?php
use App\ApplicationRepository;
use App\ConfigurationValues;
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Input\Input;
use GT\Json\Schema\JSONDocument;

function go(Request $request, Input $input, JSONDocument $document, ResponseHeaders $headers):void {
	$headers->set("Cache-Control", "no-store");
	$method = $request->getMethod();
	if(!in_array($method, ["GET", "POST"], true)) {
		$headers->set("Allow", "GET, POST");
		$document->error("Use GET to inspect configuration or POST to update it.", 405);
		return;
	}
	$authorization = $request->getHeaderLine("Authorization");
	if(!str_starts_with($authorization, "Bearer ")) {
		$document->error("Expected a bearer token.", 401);
		return;
	}
	$repository = new ApplicationRepository();
	$token = substr($authorization, 7);
	try {
		if($method === "POST") {
			$repository->getByToken($token);
			if(explode(";", $request->getHeaderLine("Content-Type"))[0] !== "application/json") {
				$document->error("Expected application/json.", 415);
				return;
			}
			$values = new ConfigurationValues($input->getStream()->read(65537));
			$update = $repository->queueConfiguration($token, $values);
		}
		elseif(($job = $input->getString("job")) !== null) {
			$update = $repository->getConfigurationUpdate($token, $job);
		}
		else {
			$document->set("keys", $repository->getConfiguredKeys($token));
			return;
		}
	}
	catch(RuntimeException $exception) {
		$document->error($exception->getMessage(), $exception->getCode() ?: 500);
		return;
	}
	$document->set("id", $update->id);
	$document->set("url", $update->url);
	$document->set("status", $update->status);
	if($update->error !== null) {
		$document->set("error", $update->error);
	}
}
