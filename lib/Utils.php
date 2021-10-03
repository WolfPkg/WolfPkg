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
			hash_update_file($h, $f);
		}
		return hash_final($h, $bin);
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

function log_nl($f, string $s): void {
	fwrite($f, $s."\n");
	fflush($f);
}

function log_exec($f, string $c): string {
	fwrite($f, "Command: {$c}\n");
	fflush($f);
	$t = [];
	$e = 0;
	exec("{$c} 2>&1", $t, $e);
	if ($e) {
		throw new RuntimeException($c, $e);
	}
	$t = trim(implode("\n", $t));
	fwrite($f, $t."\n");
	fflush($f);
	return $t;
}
