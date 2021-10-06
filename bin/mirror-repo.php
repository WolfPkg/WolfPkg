#!/usr/bin/env php
<?php

require_once __DIR__.'/../lib/autoconf.php';

\E\chdir($_ENV['WOLFPKG_ROOT']);

$db = \Db\get_rw();

$exps = [];
$stm = $db->prepexec("SELECT p_id, p_name, p_chash FROM packages");
while ($row = $stm->fetch()) {
	$exps[$row['p_name']] = $row;
}

$pkgs = \Pkg\enum_packages();
foreach ($pkgs as $path => $pkname) {
	if (empty($exps[$pkname])) {
		continue;
	}
	if (!preg_match('~'.$argv[1].'~', $pkname)) {
		continue;
	}

	$cpath = "{$path}/pkg.json5";
	$conf = \Pkg\load_conf($cpath, $pkname);
	$conf['id'] = $exps[$pkname]['p_id'];
	$conf['chash'] = $exps[$pkname]['p_chash'];

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