<?php
namespace GT\Deploy\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Argument\Argument;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\NamedParameter;
use Gt\Cli\Stream;
use GT\Deploy\DeploymentClient;
use GT\Deploy\ProjectConfiguration;
use GT\Deploy\Settings;
use RuntimeException;
use Throwable;

class ConfigSetCommand extends Command {
	public function __construct(private Settings $settings) {
	}

	public function run(?ArgumentValueList $arguments = null):int {
		try {
			if(!$arguments?->contains("selector") || $arguments->contains(Argument::USER_DATA)) {
				throw new RuntimeException("Use gt deploy config-set <key|section|'*'>. Quote '*' to select all keys.");
			}
			$directory = (string)getcwd();
			$settings = $this->settings;
			if($settings->token === null) {
				$option = $settings->environment === null ? "" : " --{$settings->environment->value}";
				throw new RuntimeException("Run gt deploy$option before setting deployment configuration.");
			}
			$values = (new ProjectConfiguration())->getOverrideValues($directory, (string)$arguments->get("selector"));
			(new DeploymentClient())->setConfiguration($settings->server, $settings->token, $values, function(string $url):void {
				$this->writeLine($url);
				$this->writeLine("Setting configuration...");
			});
			$count = count($values);
			$noun = $count === 1 ? "value" : "values";
			$this->writeLine("Set $count configuration $noun on the deployment.");
			return 0;
		}
		catch(Throwable $exception) {
			$this->writeLine($exception->getMessage(), Stream::ERROR);
			return 1;
		}
	}

	public function getName():string {
		return "config-set";
	}

	public function getDescription():string {
		return "Send selected local configuration to the deployment";
	}

	public function getRequiredNamedParameterList():array {
		return [new NamedParameter("selector")];
	}

	public function getOptionalNamedParameterList():array {
		return [];
	}

	public function getRequiredParameterList():array {
		return [];
	}

	public function getOptionalParameterList():array {
		return [];
	}
}
