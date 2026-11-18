<?php
// Transport integration collaborator: real queue/storage, no Docker in the fast suite.
require "$argv[1]/vendor/autoload.php";
chdir($argv[1]);
$runtime = new readonly class extends App\DockerRuntime {
	public function deploy(string $directory, string $source, string $id):void {
		if(is_file("$directory/$source/fail-build")) {
			throw new RuntimeException("Fixture build failed.");
		}
		if(!is_file("$directory/$source/page.html")) {
			throw new RuntimeException("Fixture source missing.");
		}
		$keys = [];
		foreach(@parse_ini_file("$directory/$source/config.ini", true, INI_SCANNER_TYPED) ?: [] as $section => $values) {
			foreach($values as $key => $value) {
				if($value !== "" && $value !== null) {
					$keys []= "$section.$key";
				}
			}
		}
		file_put_contents("$directory/runtime.json", json_encode(["port" => 12345, "container" => "gt-$id", "release" => $source, "configuration_keys" => $keys]));
	}
	public function configure(string $directory, string $id, array $values):void {
		if(isset($values["failure.trigger"])) {
			throw new RuntimeException("Fixture update failed.");
		}
		$runtime = json_decode(file_get_contents("$directory/runtime.json"), true);
		$runtime["configuration_keys"] = array_values(array_unique([...$runtime["configuration_keys"], ...array_keys($values)]));
		file_put_contents("$directory/runtime.json", json_encode($runtime));
		file_put_contents("$directory/fixture-config.json", json_encode($values));
	}
};
$repository = new App\ApplicationRepository();
while(true) {
	$repository->configureNext($runtime) || $repository->buildNext($runtime);
	usleep(10_000);
}
