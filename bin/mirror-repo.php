#!/usr/bin/env php
<?php

require_once __DIR__.'/../lib/autoconf.php';

chdir($_ENV['WOLFPKG_ROOT']);

$pkgs = \Pkg\enum_packages();
foreach ($pkgs as $path => $pkname) {
	if (!preg_match('~'.$argv[1].'~', $pkname)) {
		continue;
	}

	$cpath = "{$path}/pkg.json5";
	$conf = \Pkg\load_conf($cpath, $pkname);

	if (!$conf['enabled']) {
		printf("Skipped (disabled): %s\n", $pkname);
		unset($exps[$pkname]);
		unset($pkgs[$path]);
		continue;
	}

	echo "Updating mirror for {$pkname}:\n";
	$rev = \Pkg\mirror_repo($conf);
	echo var_export($rev, true), "\n";
}
