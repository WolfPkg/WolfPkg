#!/usr/bin/env php
<?php

require_once __DIR__.'/../lib/autoconf.php';

chdir($_ENV['WOLFPKG_ROOT']);

$db = \Db\open();

$exps = [];
$stm = $db->prepexec("SELECT p_id, p_name, p_path, p_mtime, p_chash FROM packages");
while ($row = $stm->fetch()) {
	\Utils\int($row['p_id']);
	\Utils\int($row['p_mtime']);
	$exps[$row['p_name']] = $row;
}

$db->beginTransaction();
$ins = $db->prepare("INSERT INTO packages (p_name, p_path, p_mtime, p_chash) VALUES (:p_name, :p_path, :p_mtime, :p_chash)");
$upd = $db->prepare("UPDATE packages SET p_path = ?, p_mtime = ?, p_chash = ?");

$todo = [];
$pkgs = \Pkg\enum_packages();
foreach ($pkgs as $path => $pkname) {
	$cpath = "{$path}/pkg.json5";
	$conf = \Pkg\load_conf($cpath, $pkname);

	if (!$conf['enabled']) {
		printf("Skipped (disabled): %s\n", $pkname);
		unset($pkgs[$path]);
		continue;
	}

	$mtime = filemtime($cpath);

	if (!array_key_exists($pkname, $exps)) {
		$exps[$pkname] = [
			'p_name' => $pkname,
			'p_path' => $path,
			'p_mtime' => $mtime,
			'p_chash' => $chash
			];
		$ins->execute($exps[$pkname]);
		$exps[$pkname]['p_id'] = intval($db->lastInsertId());
		echo "New: {$pkname}\n";
		$todo[] = $conf;
		continue;
	}

	if ($exps[$pkname]['p_mtime'] === $mtime) {
		echo "Skipped (mtime): {$pkname}\n";
		continue;
	}

	$data = var_export($conf, true);
	$chash = \Utils\b64_enx(\Utils\sha256($data));
	if ($exps[$pkname]['p_chash'] !== $chash) {
		$upd->execute([$path, $mtime, $chash]);
		$exps[$pkname]['p_path'] = $path;
		$exps[$pkname]['p_mtime'] = $mtime;
		$exps[$pkname]['p_chash'] = $chash;
		echo "Changed: {$pkname}\n";
		$todo[] = $conf;
	}
	else {
		echo "Skipped (hash): {$pkname}\n";
	}
	echo var_export($exps[$pkname], true), "\n";
}

foreach ($pkgs as $path => $pkname) {
	foreach (['debian', 'rpm', 'macos', 'windows', 'scan-build'] as $kind) {
		if (!is_dir("{$path}/{$kind}")) {
			continue;
		}
		$fs = explode("\n", trim(shell_exec("find '{$path}/{$kind}' -not -type d | LC_ALL=C sort")));

		$mtime = 0;
		foreach ($fs as $k => $f) {
			$fs[$k] = realpath($f);
			$mtime = max($mtime, filemtime($fs[$k]));
		}
	}
}

$db->commit();
