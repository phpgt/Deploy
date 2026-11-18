<?php
namespace GT\Deploy;

use RuntimeException;
use Throwable;

class Deployer {
	public function __construct(private readonly ProcessRunner $runner = new ProcessRunner()) {
	}

	/** @return array{archive: string, files: int, bytes: int} */
	public function deploy(string $directory):array {
		$temporary = tempnam(sys_get_temp_dir(), "gt-deploy-");
		if($temporary === false) {
			throw new RuntimeException("Unable to create deployment archive.");
		}
		$archive = "$temporary.tar.gz";
		if(!rename($temporary, $archive)) {
			unlink($temporary);
			throw new RuntimeException("Unable to name deployment archive.");
		}
		try {
			$files = $this->package($directory, $archive);
			$bytes = filesize($archive);
			if($bytes === false) {
				throw new RuntimeException("Unable to read deployment archive size.");
			}
			return ["archive" => $archive, "files" => $files, "bytes" => $bytes];
		}
		catch(Throwable $exception) {
			unlink($archive);
			throw $exception;
		}
	}

	private function package(string $directory, string $archive):int {
		$exclude = ["/cache", "/test", "/vendor", "/node_modules", ".git", ".idea", ".env", ".env.*", "config.*.ini", "deploy.*.ini", "*.sqlite*", "*.db", "*.db-*", "*.sql.gz", "*.pem", "*.key", ".ssh"];
		$command = ["tar", "-czvf", $archive, "--hard-dereference", "--quoting-style=escape", "--transform=s,^\\./,,"];
		foreach($exclude as $pattern) {
			$command []= "--exclude=" . (str_starts_with($pattern, "/") ? ".$pattern" : $pattern);
		}
		if(is_file("$directory/.deployignore")) {
			$command []= "--exclude-from=.deployignore";
		}
		$manifest = $this->trackedFiles($directory);
		try {
			if($manifest === null) {
				$command []= ".";
			}
			else {
				array_push($command, "--null", "--verbatim-files-from", "--no-recursion", "--files-from=$manifest");
			}
			$output = $this->runner->run($command, $directory);
		}
		finally {
			if($manifest !== null) {
				unlink($manifest);
			}
		}
		$inventory = $this->runner->run(["tar", "-tzvf", $archive, "--quoting-style=escape"], $directory);
		if(preg_match('/^l/m', $inventory)) {
			throw new RuntimeException("Deployment source must not contain symlinks. Remove or exclude them before deploying.");
		}
		return $this->countFiles($output);
	}

	private function countFiles(string $output):int {
		// Tar escapes newlines in names; directories have a trailing slash.
		$files = 0;
		foreach(explode("\n", $output) as $entry) {
			if($entry !== "" && !str_ends_with($entry, "/")) {
				$files++;
			}
		}
		return $files;
	}

	private function trackedFiles(string $directory):?string {
		$parent = realpath($directory);
		while($parent !== false && !$this->hasGitMetadata($parent)) {
			$next = dirname($parent);
			if($next === $parent) {
				return null;
			}
			$parent = $next;
		}
		$output = $this->runner->run(["git", "ls-files", "--cached", "--deduplicate", "-z", "--", "."], $directory);
		$tracked = explode("\0", $output);
		array_pop($tracked); // Git terminates each filename with NUL.
		$files = [];
		foreach($tracked as $file) {
			if(file_exists("$directory/$file") || is_link("$directory/$file")) {
				$this->assertNoSymlink($directory, $file);
				$files []= "./$file";
			}
		}
		$manifest = tempnam(sys_get_temp_dir(), "gt-deploy-files-");
		if($manifest === false) {
			throw new RuntimeException("Unable to create deployment file list.");
		}
		file_put_contents($manifest, implode("\0", $files) . ($files === [] ? "" : "\0"));
		return $manifest;
	}

	private function hasGitMetadata(string $directory):bool {
		return is_file("$directory/.git") || is_file("$directory/.git/HEAD");
	}

	private function assertNoSymlink(string $directory, string $file):void {
		$path = $directory;
		foreach(explode("/", $file) as $component) {
			$path .= "/$component";
			if(is_link($path)) {
				throw new RuntimeException("Deployment source must not contain symlinks. Remove or exclude them before deploying.");
			}
		}
	}
}
