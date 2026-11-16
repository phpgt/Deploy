<?php
use GT\DomTemplate\Binder;
use GT\Input\Input;

function go(Binder $binder, Input $input):void {
	$binder->bindKeyValue("name", $input->getString("name"));
}
