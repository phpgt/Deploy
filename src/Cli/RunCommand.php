<?php
namespace GT\Deploy\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Palette;
use Gt\Cli\Parameter\NamedParameter;
use Gt\Cli\Parameter\Parameter;
use Gt\Cli\Stream;
use GT\Config\ConfigFactory;
use GT\Deploy\Deployer;
use GT\Deploy\DeploymentClient;
use GT\Deploy\Settings;
use GT\Deploy\ProjectConfiguration;
use Throwable;

class RunCommand extends Command {
	/** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass
	public function run(?ArgumentValueList $arguments = null):int {
		try {
			$timeStart = microtime(true);
			$directory = (string)getcwd();
			$config = (new ConfigFactory())->createForProject(
				$directory,
				"$directory/vendor/phpgt/webengine/config.default.ini",
			);
			$settings = new Settings($config);
			$client = new DeploymentClient();
			$reservation = $client->reserve($settings->server, $settings->token);
			$projectConfig = new ProjectConfiguration();
			if($settings->token === null) {
				$projectConfig->saveToken($directory, $reservation->token, $settings->server);
			}

			if(!$projectConfig->isIgnoredByGit($directory)) {
				$this->writeLine("Warning: config.dev.ini is not ignored by Git. Add config.*.ini to .gitignore to protect your deployment token.", Stream::ERROR);
			}

			$timeReserved = microtime(true);
			if($timeReserved - $timeStart < 500) {
				usleep(500_000);
			}
			$this->setOutputPalette(Palette::CYAN);
			$this->writeLine($reservation->url);
			$this->resetOutputPalette();
			$this->writeLine();

			sleep(1);

			$this->writeLine("Packaging...");
			$package = (new Deployer())->deploy($directory);
			$this->writeLine("$package[files] files ($package[bytes] bytes) ready to be uploaded.");
			$this->writeLine("Archive: $package[archive]");
			$this->writeLine();
			sleep(1);

			$timeUploading = microtime(true);
			$bar = $this->createProgressBar(100, "Uploading... ", 15);
			$lastPercent = 0;
			$this->saveCursorPosition();
			$bar->setProgress(0);
			try {
				$client->upload($reservation, $package["archive"], function(int $uploaded, int $total) use ($bar, &$lastPercent):void {
					if($total <= 0) {
						return;
					}

					$percent = min(100, (int)floor($uploaded * 100 / $total));
					if($percent === $lastPercent) {
						return;
					}

					$this->restoreCursorPosition();
					$bar->setProgress($percent);
					$lastPercent = $percent;
				});
			}
			catch(Throwable $exception) {
				$this->writeLine();
				throw $exception;
			}

			$this->restoreCursorPosition();
			$bar->finish();

			$timeUploadComplete = number_format(microtime(true) - $timeUploading, 1);
			$this->writeLine("Uploaded in $timeUploadComplete seconds.");
			$this->writeLine();

			$this->writeLine("Preparing deployment...");
			$client->initialise($settings->server, $reservation->token);
			$this->writeLine("Source extracted. Docker build is not implemented yet.");
			return 0;
		}
		catch(Throwable $exception) {
			$this->writeLine($exception->getMessage(), Stream::ERROR);
			return 1;
		}
	}

	public function getName():string {
		return "run";
	}

	public function getDescription():string {
		return "Package a PHP application for deployment";
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
