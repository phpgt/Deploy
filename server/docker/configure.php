<?php
$domain = strtolower(getenv("DEPLOY_DOMAIN") ?: "deploy.localhost");
$scheme = getenv("DEPLOY_SCHEME") ?: "http";
$port = filter_var(getenv("DEPLOY_PUBLIC_PORT") ?: "8080", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]);
$fpmPort = filter_var(getenv("DEPLOY_FPM_PORT") ?: "19000", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]);
$database = getenv("DEPLOY_DATABASE") ?: "sqlite";
if(!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
|| !in_array($scheme, ["http", "https"], true)
|| !in_array($database, ["sqlite", "mysql", "none", "external"], true)
|| $port === false || $fpmPort === false) {
	throw new RuntimeException("Invalid deployment server environment settings.");
}
file_put_contents("config.deploy.ini", "[app]\nproduction=true\n[server]\ndomain=$domain\nscheme=$scheme\nport=$port\n[docker]\ndatabase=$database\n");
file_put_contents("/usr/local/etc/php-fpm.d/zzz-deploy-server.conf", "[www]\nlisten=127.0.0.1:$fpmPort\nclear_env=no\nphp_admin_value[post_max_size]=128M\nphp_admin_value[upload_max_filesize]=100M\n");
