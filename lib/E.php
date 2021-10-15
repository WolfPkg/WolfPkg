<?php
declare(strict_types=1);
namespace E;

function chdir(string $d): void {
	if (\chdir($d) === false) {
		throw new \RuntimeException("chdir('{$d}')");
	}
}

function rename(string $f, string $t): void {
	if (\rename($f, $t) === false) {
		throw new \RuntimeException("rename('{$f}', '{$t}')");
	}
}

function file_get_contents(string $d): string {
	$rv = \file_get_contents($d);
	if ($rv === false) {
		throw new \RuntimeException("file_get_contents('{$d}')");
	}
	return $rv;
}

function fopen(string $d, string $m) {
	$rv = \fopen($d, $m);
	if ($rv === false) {
		throw new \RuntimeException("fopen('{$d}', '{$m}')");
	}
	return $rv;
}
