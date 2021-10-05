#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/autoconf.php';

\E\chdir($_ENV['WOLFPKG_ROOT']);

$db = \Db\get_rw();
$kinds = \Pkg\get_kinds();
$todo = [];
$confs = [];

$exps = [];
$stm = $db->prepexec("SELECT p_id, p_name, p_path, p_url, p_mtime, p_chash FROM packages");
while ($row = $stm->fetch()) {
	\Utils\int($row['p_id']);
	\Utils\int($row['p_mtime']);
	$exps[$row['p_name']] = $row;
}

$expks = [];
$stm = $db->prepexec("SELECT p_id, k_id, p_name, k_name, pk_mtime, pk_chash, pk_thash FROM package_kind NATURAL JOIN packages NATURAL JOIN kinds");
while ($row = $stm->fetch()) {
	\Utils\int($row['p_id']);
	\Utils\int($row['k_id']);
	\Utils\int($row['pk_mtime']);
	$expks[$row['p_name']][$row['k_name']] = $row;
}

$db->beginTransaction();
$ins = $db->prepare("INSERT INTO packages (p_name, p_path, p_url, p_mtime, p_chash) VALUES (:p_name, :p_path, :p_url, :p_mtime, :p_chash)");
$upd = $db->prepare("UPDATE packages SET p_path = ?, p_url = ?, p_mtime = ?, p_chash = ? WHERE p_id = ?");
$upd_mtime = $db->prepare("UPDATE packages SET p_mtime = ? WHERE p_id = ?");

// Check for new/changed packages
$pkgs = \Pkg\enum_packages();
foreach ($pkgs as $path => $pkname) {
	$cpath = "{$path}/pkg.json5";
	$conf = \Pkg\load_conf($cpath, $pkname);

	if (!$conf['enabled']) {
		printf("Skipped (disabled): %s\n", $pkname);
		unset($exps[$pkname]);
		unset($pkgs[$path]);
		continue;
	}

	$confs[$pkname] = $conf;

	$mtime = filemtime($cpath);

	if (empty($exps[$pkname])) {
		$chash = \Utils\sha256_b64x(var_export($conf, true));
		$exps[$pkname] = [
			'p_name' => $pkname,
			'p_path' => $path,
			'p_url' => $conf['url'],
			'p_mtime' => $mtime,
			'p_chash' => $chash,
			];
		$ins->execute($exps[$pkname]);
		$exps[$pkname]['p_id'] = intval($db->lastInsertId());
		echo "New: {$pkname}\n";
		foreach ($kinds as $kind) {
			$todo[$pkname][$kind] = true;
		}
		continue;
	}

	if ($exps[$pkname]['p_mtime'] === $mtime) {
		echo "Skipped (mtime): {$pkname}\n";
		continue;
	}

	$chash = \Utils\sha256_b64x(var_export($conf, true));
	if ($exps[$pkname]['p_chash'] !== $chash) {
		$upd->execute([$path, $conf['url'], $mtime, $chash, $exps[$pkname]['p_id']]);
		$exps[$pkname]['p_path'] = $path;
		$exps[$pkname]['p_mtime'] = $mtime;
		$exps[$pkname]['p_chash'] = $chash;
		echo "Changed: {$pkname}\n";
		foreach ($kinds as $kind) {
			$todo[$pkname][$kind] = true;
		}
	}
	else {
		$upd_mtime->execute([$mtime, $exps[$pkname]['p_id']]);
		echo "Skipped (hash): {$pkname}\n";
	}
}


// Check each kind
$ins = $db->prepare("INSERT INTO package_kind (p_id, k_id, pk_mtime, pk_chash, pk_thash) VALUES (:p_id, :k_id, :pk_mtime, :pk_chash, :pk_thash)");
$upd = $db->prepare("UPDATE package_kind SET pk_mtime = ?, pk_chash = ?, pk_thash = ? WHERE p_id = ? AND k_id = ?");
$upd_mtime = $db->prepare("UPDATE package_kind SET pk_mtime = ? WHERE p_id = ? AND k_id = ?");
$del = $db->prepare("DELETE FROM package_kind WHERE p_id = ? AND k_id = ?");

foreach ($pkgs as $path => $pkname) {
	foreach ($kinds as $k_id => $kind) {
		if (!is_dir("{$path}/{$kind}")) {
			if (!empty($expks[$pkname][$kind])) {
				echo "Kind removed: {$pkname}/{$kind}\n";
				$del->execute([$exps[$pkname]['p_id'], $k_id]);
				unset($expks[$pkname][$kind]);
				unset($todo[$pkname][$kind]);
			}
			continue;
		}

		$fs = explode("\n", trim(shell_exec("find '{$path}/{$kind}' -not -type d")));
		sort($fs);

		$mtime = 0;
		foreach ($fs as $k => $f) {
			$fs[$k] = realpath($f);
			$mtime = max($mtime, filemtime($fs[$k]));
		}

		if (empty($expks[$pkname][$kind])) {
			$chash = \Utils\sha256_file_b64x($fs);
			$thash = \Utils\sha256_b64x($exps[$pkname]['p_chash'].$chash);
			$expks[$pkname][$kind] = [
				'p_id' => $exps[$pkname]['p_id'],
				'k_id' => $k_id,
				'pk_mtime' => $mtime,
				'pk_chash' => $chash,
				'pk_thash' => $thash,
				];
			$ins->execute($expks[$pkname][$kind]);
			$expks[$pkname][$kind]['p_name'] = $pkname;
			$expks[$pkname][$kind]['k_name'] = $kind;
			$todo[$pkname][$kind] = true;
			echo "New: {$pkname}/{$kind}\n";
			continue;
		}

		if (empty($todo[$pkname][$kind]) && $expks[$pkname][$kind]['pk_mtime'] === $mtime) {
			echo "Skipped (mtime): {$pkname}/{$kind}\n";
			continue;
		}

		$chash = \Utils\sha256_file_b64x($fs);
		$thash = \Utils\sha256_b64x($exps[$pkname]['p_chash'].$chash);
		if ($expks[$pkname][$kind]['pk_chash'] !== $chash || $expks[$pkname][$kind]['pk_thash'] !== $thash) {
			$upd->execute([$mtime, $chash, $thash, $exps[$pkname]['p_id'], $k_id]);
			$expks[$pkname][$kind]['pk_mtime'] = $mtime;
			$expks[$pkname][$kind]['pk_chash'] = $chash;
			$expks[$pkname][$kind]['pk_thash'] = $thash;
			echo "Changed: {$pkname}/{$kind}\n";
			$todo[$pkname][$kind] = true;
		}
		else {
			$upd->execute([$mtime, $exps[$pkname]['p_id'], $k_id]);
			echo "Skipped (hash): {$pkname}/{$kind}\n";
		}
	}
}

foreach ($todo as $pkname => $ks) {
	break;
	$confs[$pkname]['id'] = $exps[$pkname]['p_id'];
	$confs[$pkname]['chash'] = $exps[$pkname]['p_chash'];

	$rev = \Pkg\mirror_repo($confs[$pkname]);
	if ($rev['changed']) {
		$tar = \Pkg\make_tarball($conf, $rev);
	}
	foreach ($ks as $kind) {
	}
}

$db->commit();
