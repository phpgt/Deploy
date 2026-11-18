<?php
use GT\Config\Config;
use GT\Json\Schema\JSONDocument;

function go(Config $config, JSONDocument $document):void {
	$document->set("secret_matches", $config->get("mail.secret") === "compose-private-secret");
	$document->set("host_matches", $config->get("mail.host") === "smtp.example.test");
	$document->set("numeric_matches", $config->get("mail.numeric") === "000123");
	$document->set("multiline_matches", $config->get("mail.multiline") === "line1\nline2");
	$document->set("quoted_matches", $config->get("mail.quoted") === 'a"b;= $plain ${MISSING_ENV}');
	$document->set("database_configured", $config->get("database.driver") !== null);
}
