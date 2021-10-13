<?php
declare(strict_types=1);
namespace Utils;

function putenv(string $k, string $v): void {
	\putenv("{$k}={$v}");
	$_ENV[$k] = $v;
}

function b64x(string $str): string {
	$str = \base64_encode($str);
	$str = trim($str, '=');
	$str = strtr($str, '/+', '_-');
	return $str;
}

function sha256(string $str, bool $bin = true): string {
	return hash('sha256', $str, $bin);
}

function sha256_file($file, bool $bin = true): string {
	if (is_array($file)) {
		$h = hash_init('sha256');
		foreach ($file as $f) {
			if (!file_exists($f)) {
				throw new \RuntimeException("No such file '{$f}'");
			}
			hash_update_file($h, $f);
		}
		return hash_final($h, $bin);
	}
	if (!file_exists($file)) {
		throw new \RuntimeException("No such file '{$file}'");
	}
	return hash_file('sha256', $file, $bin);
}

function sha256_b64x(string $str): string {
	return b64x(sha256($str, true));
}

function sha256_file_b64x($file): string {
	return b64x(sha256_file($file, true));
}

function int(&$v): void {
	$v = intval($v);
}

class Log {
	private $f = null;

	public function __construct(string $fn) {
		$this->f = \E\fopen($fn, 'wb');
	}

	public function ln(string $s): void {
		fwrite($this->f, $s."\n");
		fflush($this->f);
	}

	public function exec(string $c, bool $ign = false): string {
		fwrite($this->f, "Command: {$c}\n");
		fflush($this->f);
		$t = [];
		$e = 0;
		exec("{$c} 2>&1", $t, $e);
		if (!$ign && $e) {
			throw new \RuntimeException($c, $e);
		}
		$t = trim(implode("\n", $t));
		if ($t) {
			fwrite($this->f, $t."\n");
		}
		fflush($this->f);
		return $t;
	}

	public function exec_null(string $c): string {
		return $this->exec($c, true);
	}

	public function flush(): void {
		fflush($this->f);
	}

	public function close(): void {
		fclose($this->f);
	}
}

function split(string $delim, string $str): array {
	if (empty($str)) {
		return [];
	}
	return \explode($delim, $str);
}
