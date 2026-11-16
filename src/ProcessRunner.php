<?php
namespace GT\Deploy;

use RuntimeException;

class ProcessRunner {
	/** @param list<string> $command */
	public function run(array $command, string $directory):string {
		$process = proc_open($command, [STDIN, ["pipe", "w"], STDERR], $pipes, $directory);
		if(!is_resource($process)) {
			throw new RuntimeException("Unable to start $command[0].");
		}
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$code = proc_close($process);
		if($code !== 0) {
			throw new RuntimeException("$command[0] failed with exit status $code. Deployment stopped.");
		}
		if($output === false) {
			throw new RuntimeException("Unable to read $command[0] output.");
		}
		return $output;
	}
}
