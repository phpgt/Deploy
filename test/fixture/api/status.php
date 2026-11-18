<?php
use GT\Http\Request;
use GT\Json\Schema\JSONDocument;

function go(JSONDocument $document, Request $request):void {
	$document->set("status", "ok");
	$document->set("scheme", $request->getUri()->getScheme());
}
