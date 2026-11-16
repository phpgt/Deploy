<?php
namespace GT\Deploy;

readonly class Reservation {
	public function __construct(public string $url, public string $token, public string $uploadUrl) {
	}
}
