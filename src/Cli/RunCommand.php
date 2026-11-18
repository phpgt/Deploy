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
use GT\Deploy\Reservation;
use GT\Deploy\ProjectConfiguration;
use Throwable;
use RuntimeException;

// This command coordinates deployment and configuration CLI services.
/** @SuppressWarnings("PHPMD.CouplingBetweenObjects") */
class RunCommand extends Command {
	/** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass
	public function run(?ArgumentValueList $arguments = null):int {
		try {
			if($arguments?->contains("action")) {
				if((string)$arguments->get("action") !== "config-set") {
					throw new RuntimeException("Use gt deploy or gt deploy config-set <key|section|'*'>.");
				}
				$command = new ConfigSetCommand();
				$command->setStream($this->stream);
				return $command->run($arguments);
			}
			$timeStart = microtime(true);
			$directory = (string)getcwd();
			$config = (new ConfigFactory())->createForProject(
				$directory,
				"$directory/vendor/phpgt/webengine/config.default.ini",
			);
			$settings = new Settings($config);
			$client = new DeploymentClient();
			$reservation = $client->reserve($settings->server, $settings->token);
			$timeReserved = microtime(true);
			if($timeReserved - $timeStart < 500) {
				usleep(500_000);
			}
			$this->setOutputPalette(Palette::CYAN);
			$this->writeLine($reservation->url);
			$this->resetOutputPalette();

			$projectConfig = new ProjectConfiguration();
			if($settings->token !== $reservation->token) {
				$projectConfig->saveToken($directory, $reservation->token, $settings->server);
				if($settings->token !== null) {
					$this->writeLine("Previous anonymous deployment expired. Reserved a new URL.");
				}
			}

			if(!$projectConfig->isIgnoredByGit($directory)) {
				$this->writeLine("Warning: config.dev.ini is not ignored by Git. Add config.*.ini to .gitignore to protect your deployment token.", Stream::ERROR);
			}

			$this->writeLine();

			sleep(1);

			$this->writeLine("Packaging...");
			$package = (new Deployer())->deploy($directory);
			$this->writeLine("$package[files] files ($package[bytes] bytes) ready to be uploaded.");
			$this->writeLine("Archive: $package[archive]");
			$this->writeLine();
			sleep(1);

			$timeUploading = microtime(true);
			$this->uploadArchive($client, $reservation, $package["archive"]);

			$timeUploadComplete = number_format(microtime(true) - $timeUploading, 1);
			$this->writeLine("Uploaded in $timeUploadComplete seconds.");
			$this->writeLine();

			$this->writeLine("Preparing deployment...");
			$client->initialise($settings->server, $reservation->token);
			$this->writeLine("Source extracted.");

			$client->build($settings->server, $reservation->token, function(string $status):void {
				$this->writeLine($status === "queued" ? "Waiting for the build worker..." : "Building and activating deployment...");
			});

			$this->writeLine();
			$this->writeLine("Finished deployment: $reservation->url");
			$this->reportUnsetConfiguration($directory, $projectConfig, $client, $settings, $reservation);
			return 0;
		}
		catch(Throwable $exception) {
			$this->writeLine($exception->getMessage(), Stream::ERROR);
			return 1;
		}
	}

	private function reportUnsetConfiguration(string $directory, ProjectConfiguration $project, DeploymentClient $client, Settings $settings, Reservation $reservation):void {
		try {
			$keys = $project->getOverrideKeys($directory);
			if($keys === []) {
				return;
			}
			$missing = array_diff($keys, $client->getConfiguredKeys($settings->server, $reservation->token));
			if($missing === []) {
				return;
			}
			$this->writeLine();
			$this->writeLine("To deploy config values, run the following commands.");
			foreach($missing as $key) {
				$this->writeLine("  gt deploy config-set " . escapeshellarg($key));
			}
		}
		catch(Throwable) {
			$this->writeLine("Warning: deployment succeeded, but its unset configuration could not be checked.", Stream::ERROR);
		}
	}

	private function uploadArchive(DeploymentClient $client, Reservation $reservation, string $archive):void {
		$bar = $this->createProgressBar(100, "Uploading... ", 15);
		$lastPercent = 0;
		$this->saveCursorPosition();
		$bar->setProgress(0);
		try {
			$client->upload($reservation, $archive, function(int $uploaded, int $total) use ($bar, &$lastPercent):void {
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
		return [new NamedParameter("action"), new NamedParameter("selector")];
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
