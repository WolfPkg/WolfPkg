<?php
namespace Utils;

function putenv($k, $v) {
	\putenv("{$k}={$v}");
	$_ENV[$k] = $v;
}

function b64x($str) {
	$str = \base64_encode($str);
	$str = trim($str, '=');
	$str = strtr($str, '/+', '-_');
	return $str;
}

function sha256($str, $bin = true) {
	return hash('sha256', $str, $bin);
}

function sha256_file($file, $bin = true) {
	if (is_array($file)) {
		$h = hash_init('sha256');
		foreach ($file as $f) {
			hash_update_file($h, $f);
		}
		return hash_final($h, $bin);
	}
	return hash_file('sha256', $file, $bin);
}

function sha256_b64x($str) {
	return b64x(sha256($str, true));
}

function sha256_file_b64x($file) {
	return b64x(sha256_file($file, true));
}

function int(&$v) {
	$v = intval($v);
}

function log_nl($f, $s) {
	fwrite($f, $s."\n");
	fflush($f);
}

function log_exec($f, $c) {
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
