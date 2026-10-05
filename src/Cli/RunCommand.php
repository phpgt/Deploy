<?php
namespace GT\Deploy\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\NamedParameter;
use Gt\Cli\Parameter\Parameter;
use Gt\Cli\Stream;

class RunCommand extends Command {
	public function run(?ArgumentValueList $arguments = null):int {
		unset($arguments);
		$this->writeLine("Deployment is not implemented yet.", Stream::ERROR);
		return 1;
	}

	public function getName():string {
		return "run";
	}

	public function getDescription():string {
		return "Deploy a PHP application (not yet implemented)";
	}

	/** @return NamedParameter[] */
	public function getRequiredNamedParameterList():array {
		return [];
	}

	/** @return NamedParameter[] */
	public function getOptionalNamedParameterList():array {
		return [];
	}

	/** @return Parameter[] */
	public function getRequiredParameterList():array {
		return [];
	}

	/** @return Parameter[] */
	public function getOptionalParameterList():array {
		return [];
	}
}
