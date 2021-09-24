#!/usr/bin/env php
<?php

require_once __DIR__.'/../lib/autoconf.php';

chdir($_ENV['WOLFPKG_ROOT']);

$db = \Db\get();

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

$pkgs = \Pkg\enum_packages();
foreach ($pkgs as $path => $pkname) {
	$cpath = "{$path}/pkg.json5";
	$conf = \Pkg\load_conf($cpath, $pkname);

	if (!$conf['enabled']) {
		printf("Disabled: %s\n", $pkname);
		continue;
	}

	$mtime = filemtime($cpath);
	$data = var_export($conf, true);
	$chash = \Utils\sha256_b64x($data);

	$todo = false;
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
		$todo = true;
	}
	else if ($exps[$pkname]['p_mtime'] !== $mtime || $exps[$pkname]['p_chash'] !== $chash) {
		$upd->execute([$path, $mtime, $chash]);
		$exps[$pkname]['p_path'] = $path;
		$exps[$pkname]['p_mtime'] = $mtime;
		$exps[$pkname]['p_chash'] = $chash;
		echo "Changed: {$pkname}\n";
		$todo = true;
	}
	echo var_export($exps[$pkname], true), "\n";
}

$db->commit();
