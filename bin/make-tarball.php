#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/autoconf.php';

\E\chdir($_ENV['WOLFPKG_ROOT']);

if (empty($argv[1])) {
	echo "Must provide at least package name!\n";
	exit(-1);
}

$db = \Db\get_rw();

$exps = [];
$stm = $db->prepexec("SELECT p_id, p_name, p_chash, r_rev FROM packages NATURAL JOIN package_repo WHERE p_name = ?", [$argv[1]]);
while ($row = $stm->fetch()) {
	$exps[$row['p_name']] = $row;
}
if (empty($exps)) {
	printf("No such package '%s'!\n", $argv[1]);
	exit(-1);
}

$pkgs = \Pkg\enum_packages();
foreach ($pkgs as $path => $pkname) {
	if (empty($exps[$pkname])) {
		continue;
	}
	if ($pkname !== $argv[1]) {
		continue;
	}

	$cpath = "{$path}/pkg.json5";
	$conf = \Pkg\load_conf($cpath, $pkname);
	$conf['id'] = $exps[$pkname]['p_id'];
	$conf['chash'] = $exps[$pkname]['p_chash'];
	$rev = $argv[2] ?? $exps[$pkname]['r_rev'];

	if (!$conf['enabled']) {
		printf("Package %s disabled!\n", $pkname);
		continue;
	}

	echo "Making tarball for {$pkname} @ {$rev}:\n";
	$rev = \Pkg\make_tarball($conf, $rev);
	echo var_export($rev, true), "\n";
}
