<?php
namespace Utils;

function putenv($k, $v) {
	\putenv("{$k}={$v}");
	$_ENV[$k] = $v;
}

function b64_enx($str) {
	$str = \base64_encode($str);
	$str = trim($str, '=');
	$str = strtr($str, '/+', '-_');
	return $str;
}

function sha256($str, $bin = true) {
	return hash('sha256', $str, $bin);
}

function sha256_file($file, $bin = true) {
	return hash_file('sha256', $file, $bin);
}

function int(&$v) {
	$v = intval($v);
}
