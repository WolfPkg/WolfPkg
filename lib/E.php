<?php
declare(strict_types=1);
namespace E;

function chdir(string $d): void {
	if (\chdir($d) === false) {
		throw new \RuntimeException("chdir('{$d}')");
	}
}

function chmod(string $f, int $t): void {
	if (\chmod($f, $t) === false) {
		throw new \RuntimeException("chmod('{$f}', {$t})");
	}
}

function chgrp(string $f, $t): void {
	if (\chgrp($f, $t) === false) {
		throw new \RuntimeException("chmod('{$f}', {$t})");
	}
}

function fileperms(string $d): int {
	$rv = \fileperms($d);
	if ($rv === false) {
		throw new \RuntimeException("fileperms('{$d}')");
	}
	return $rv;
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

function file_put_contents(string $f, string $t): void {
	if (\file_put_contents($f, $t) === false) {
		throw new \RuntimeException("file_put_contents('{$f}', '{$t}')");
	}
}

function fopen(string $d, string $m) {
	$rv = \fopen($d, $m);
	if ($rv === false) {
		throw new \RuntimeException("fopen('{$d}', '{$m}')");
	}
	return $rv;
}

function popen(string $d, string $m) {
	$rv = \popen($d, $m);
	if ($rv === false) {
		throw new \RuntimeException("popen('{$d}', '{$m}')");
	}
	return $rv;
}
