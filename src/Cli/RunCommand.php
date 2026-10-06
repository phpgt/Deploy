<?php
namespace GT\Deploy\Cli;

use GT\Cli\Argument\ArgumentValueList;
use GT\Cli\Command\Command;
use GT\Cli\Parameter\NamedParameter;
use GT\Cli\Parameter\Parameter;
use GT\Cli\StreamName;

class RunCommand extends Command {
	public function run(?ArgumentValueList $arguments = null):int {
		unset($arguments);
		$this->writeLine("Deployment is not implemented yet.", StreamName::ERROR);
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
