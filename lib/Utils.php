<?php
namespace Utils;

function putenv($k, $v) {
	\putenv("{$k}={$v}");
	$_ENV[$k] = $v;
}

function base64_encode_x($str) {
	$str = \base64_encode($str);
	$str = trim($str, '=');
	$str = strtr($str, '/+', '-_');
	return $str;
}

function sha256_b64x($str) {
	$hash = hash('sha256', $str, true);
	return base64_encode_x($hash);
}

function sha256_b64x_file($file) {
	$hash = hash_file('sha256', $file, true);
	return base64_encode_x($hash);
}

function int(&$v) {
	$v = intval($v);
}
