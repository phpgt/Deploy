<?php
namespace App;

use RuntimeException;
use Throwable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

readonly class SourceArchive {
	const MAX_BYTES = 1073741824;
	const MAX_ENTRIES = 100000;

	public function extract(string $archive, string $destination):void {
		if(is_link($destination) || !is_dir($destination)) {
			throw new RuntimeException("Source destination must be a private directory.");
		}
		if((new FilesystemIterator($destination, FilesystemIterator::SKIP_DOTS))->valid()) {
			throw new RuntimeException("Source destination must be empty.");
		}

		$this->validate($archive);
		[$process, $output] = $this->start([
			"--extract", "--gzip", "--file", $archive, "--directory", $destination,
			"--no-same-owner", "--no-same-permissions",
			"--keep-old-files", "--delay-directory-restore", "--restrict",
		]);
		stream_get_contents($output);
		fclose($output);
		$this->checkStatus(proc_close($process));
		$this->setPermissions($destination);
	}

	private function validate(string $archive):void {
		[$process, $output] = $this->start([
			"--list", "--verbose", "--gzip", "--numeric-owner", "--full-time",
			"--quoting-style=escape", "--file", $archive,
		]);
		try {
			$entries = 0;
			$total = 0;
			while(($line = fgets($output, 32768)) !== false) {
				$size = $this->entrySize($line);
				if(++$entries > self::MAX_ENTRIES || $size > self::MAX_BYTES - $total) {
					throw new RuntimeException("Source archive exceeds extraction limits.", 422);
				}
				$total += $size;
			}
		}
		catch(Throwable $exception) {
			proc_terminate($process);
			throw $exception;
		}
		finally {
			fclose($output);
			$status = proc_close($process);
		}
		$this->checkStatus($status);
		if($entries === 0) {
			throw new RuntimeException("Source archive is empty or invalid.", 422);
		}
	}

	private function entrySize(string $line):int {
		if(!in_array($line[0], ["-", "d"], true)) {
			throw new RuntimeException("Source archives must not contain links or special files.", 422);
		}

		// Fixed GNU tar output: mode, numeric owner/group, bytes, ISO date, time, escaped name.
		if(!preg_match('/^\S+\s+\d+\/\d+\s+(\d+)\s+\S+\s+\S+ (.*)$/D', rtrim($line, "\r\n"), $fields)) {
			throw new RuntimeException("Source archive contains invalid metadata.", 422);
		}

		$name = stripcslashes($fields[2]);
		if($name === "" || str_starts_with($name, "/") || str_contains($name, "\0")
		|| in_array("..", explode("/", $name), true)) {
			throw new RuntimeException("Source archive contains an unsafe path.", 422);
		}

		return (int)$fields[1];
	}

	/**
	 * @param list<string> $arguments
	 * @return array{resource, resource}
	 */
	private function start(array $arguments):array {
		$environment = [
			"PATH" => getenv("PATH") ?: "/usr/local/bin:/usr/bin:/bin",
			"LC_ALL" => "C", "TZ" => "UTC", "TAR_OPTIONS" => "", "GZIP" => "",
		];
		$mask = umask(0077);
		try {
			$process = proc_open([
				"timeout", "--signal=KILL", "30s", "prlimit", "--cpu=20", "--as=268435456",
				"--fsize=" . self::MAX_BYTES, "--", "tar", ...$arguments,
			], [["file", "/dev/null", "r"], ["pipe", "w"], ["file", "/dev/null", "w"]], $pipes, null, $environment);
		}
		finally {
			umask($mask);
		}
		if(!is_resource($process)) {
			throw new RuntimeException("Unable to start GNU tar.");
		}

		return [$process, $pipes[1]];
	}

	private function checkStatus(int $status):void {
		if(in_array($status, [125, 126, 127], true)) {
			throw new RuntimeException("Source extraction requires GNU tar, gzip, timeout and prlimit.");
		}
		if($status !== 0) {
			throw new RuntimeException("Source archive is invalid or exceeds extraction limits.", 422);
		}
	}

	private function setPermissions(string $path):void {
		chmod($path, 0700);
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach($files as $file) {
			if(!$file->isLink()) {
				chmod($file->getPathname(), $file->isDir() ? 0700 : 0600 | ($file->getPerms() & 0100));
			}
		}
	}

	public function discard(string $path):void {
		if(is_link($path)) {
			unlink($path);
			return;
		}
		if(!is_dir($path)) {
			return;
		}

		$this->setPermissions($path);
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($files as $file) {
			if($file->isLink() || !$file->isDir()) {
				unlink($file->getPathname());
			}
			else {
				rmdir($file->getPathname());
			}
		}
		rmdir($path);
	}
}
