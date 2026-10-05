<?php
namespace GT\Deploy\Test\Cli;

use PHPUnit\Framework\TestCase;

class RunCommandTest extends TestCase {
	public function testUnimplementedDeploymentFailsClearly():void {
		[$exitCode, $output, $error] = $this->runCli();
		self::assertSame(1, $exitCode);
		self::assertSame("", $output);
		self::assertStringContainsString("Deployment is not implemented yet.", $error);
	}

	public function testHelpSucceedsWithoutDeploying():void {
		[$exitCode, $output, $error] = $this->runCli("--help");
		self::assertSame(0, $exitCode);
		self::assertStringContainsString("Deploy a PHP application", $output);
		self::assertSame("", $error);
	}

	/** @return array{int, string, string} */
	private function runCli(string ...$arguments):array {
		$process = proc_open(
			[PHP_BINARY, __DIR__ . "/../../../bin/deploy", ...$arguments],
			[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
			$pipes,
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output, $error];
	}
}
