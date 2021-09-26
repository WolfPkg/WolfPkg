<?php
namespace Pkg;

function enum_packages() {
	$pkgs = [];
	$fs = glob('packages/[a-z]*/*/pkg.json5');
	foreach ($fs as $f) {
		$f = preg_replace('~^\Q'.$_ENV['WOLFPKG_ROOT'].'\E~', '', $f);
		$f = preg_replace('~/pkg\.json5$~', '', $f);
		preg_match('~([^/]+)$~', $f, $m);
		$pkgs[$f] = $m[1];
	}
	return $pkgs;
}

function parse_conf($j5, $pkname, $raw = false) {
	$conf = json5_decode($j5, true);
	if (!$raw) {
		$def = [
			'source' => $conf['source'] ?? "https://github.com/apertium/{$pkname}",
			'vcs' => 'git',
			'version_in' => 'configure.ac',
			'excludes' => [],
			'non_targets' => [],
			'enabled' => true,
			];

		if (!preg_match('~^https://github\.com/[^/]+?/[^/]+$~', $def['source'])) {
			$def['vcs'] = 'svn';
		}
		foreach ($conf as $k => $v) {
			$def[$k] = $v;
		}
		$conf = $def;
		sort($conf['nobuild']);
		ksort($conf);
	}
	return $conf;
}

function load_conf($path, $pkname, $raw = false) {
	return parse_conf(file_get_contents($path), $pkname, $raw);
}
