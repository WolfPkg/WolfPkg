#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/autoconf.php';

$pkgs = file_get_contents('packages.json');
$pkgs = str_replace('#', '//', $pkgs);
$pkgs = json5_decode($pkgs);

foreach ($pkgs as $p) {
	preg_match('~^([^/]+)/([^/]+)$~', $p[0], $mp);

	if (is_dir("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}")) {
		echo shell_exec("rm -rf '{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}'");
	}
	echo shell_exec("mkdir -pv '{$_ENV['WOLFPKG_ROOT']}/packages/{$mp[1]}/'");
	echo shell_exec("cp -aL --reflink=always '{$p[0]}' '{$_ENV['WOLFPKG_ROOT']}/packages/{$mp[1]}/'");

	$exc = [];
	if (file_exists("{$p[0]}/exclude.txt")) {
		$exc = explode("\n", trim(file_get_contents("{$p[0]}/exclude.txt")));
		unlink("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/exclude.txt");
	}
	@unlink("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/config.json");

	if (file_exists("{$p[0]}/rpm/{$mp[2]}.spec")) {
		rename("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/rpm/{$mp[2]}.spec", "{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/rpm/pkg.spec");
	}
	if (file_exists("{$p[0]}/win32/{$mp[2]}.sh")) {
		rename("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/win32/{$mp[2]}.sh", "{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/win32/setup.sh");
		rename("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/win32", "{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/mingw");
	}
	if (is_dir("{$p[0]}/osx")) {
		rename("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/osx", "{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/macos");
	}

	$conf = [];
	if (!empty($p[1])) {
		if (preg_match('~https://github.com/apertium/([^/]+)$~', $p[1], $m)) {
			$p[1] = $m[1];
		}
		else if (preg_match('~https://github.com/([^/]+/)'.$mp[2].'$~', $p[1], $m)) {
			$p[1] = $m[1];
		}
		else if (preg_match('~https://github.com/([^/]+/[^/]+)$~', $p[1], $m)) {
			$p[1] = $m[1];
		}
		$conf['url'] = $p[1];
	}
	if (!empty($p[2])) {
		$conf['version_in'] = $p[2];
	}
	if (!empty($p[3])) {
		$conf['non_targets'] = explode(',', $p[3]);
		sort($conf['non_targets']);
	}
	if (!empty($exc)) {
		$conf['excludes'] = $exc;
	}
	$conf = json_encode((object)$conf, JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	$conf = preg_replace('~"([^"]+)":~', '\1:', $conf);
	$conf = str_replace('"', "'", $conf);
	$conf = str_replace("'\n", "',\n", $conf);
	$conf = str_replace("]\n", "],\n", $conf);
	$conf = str_replace("}\n", "},\n", $conf);
	$conf = str_replace('    ', "\t", $conf);
	$conf = str_replace("\t}", "\t\t}", $conf);
	$conf = str_replace("\t]", "\t\t]", $conf);
	file_put_contents("{$_ENV['WOLFPKG_ROOT']}/packages/{$p[0]}/pkg.json5", $conf."\n");
	//echo var_export($p, true), "\n";
	//chdir($_ENV['WOLFPKG_ROOT']);
}
