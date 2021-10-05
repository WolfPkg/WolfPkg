<?php
declare(strict_types=1);
namespace Pkg;

function enum_packages(): array {
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

function parse_conf(string $j5, string $pkname, bool $raw = false): array {
	$conf = json5_decode($j5, true);
	if (!$raw) {
		if (empty($conf['url'])) {
			$conf['url'] = "https://github.com/apertium/{$pkname}";
		}
		else if (preg_match('~^[^/]+/$~', $conf['url'])) {
			$conf['url'] = 'https://github.com/'.$conf['url'].$pkname;
		}
		else if (preg_match('~^[^/]+/[^/]+$~', $conf['url'])) {
			$conf['url'] = 'https://github.com/'.$conf['url'];
		}
		else if (preg_match('~^[^/]+$~', $conf['url'])) {
			$conf['url'] = 'https://github.com/apertium/'.$conf['url'];
		}
		$def = [
			'name' => $pkname,
			'url' => $conf['url'],
			'root' => '',
			'vcs' => 'git',
			'version_in' => 'configure.ac',
			'excludes' => [],
			'non_targets' => [],
			'min_ram' => 0,
			'enabled' => true,
			];

		if (!preg_match('~^https://github\.com/[^/]+?/[^/]+$~', $def['url'])) {
			$def['vcs'] = 'svn';
		}
		foreach ($conf as $k => $v) {
			$def[$k] = $v;
		}
		$conf = $def;
		sort($conf['non_targets']);
		ksort($conf);
	}
	return $conf;
}

function load_conf(string $path, string $pkname, bool $raw = false): array {
	return parse_conf(\E\file_get_contents($path), $pkname, $raw);
}

function read_control(string $path): string {
	$c = \E\file_get_contents("{$path}/debian/control");
	$c = preg_replace('~,[\s\n]+~', ', ', $c);
	return $c;
}

function get_kinds(): array {
	$db = \Db\get_rw();
	$ks = [];
	$stm = $db->prepexec("SELECT k_id, k_name FROM kinds");
	while ($row = $stm->fetch()) {
		$ks[$row['k_id']] = $row['k_name'];
	}
	return $ks;
}

function mirror_repo(array $conf) {
	$rev = false;
	$pwd = getcwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/repo", 0711, true);
	\E\chdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}");

	$stamp = date('Ymd-His');
	$log = \E\fopen($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/repo/{$stamp}.log", 'wb');
	\Utils\log_nl($log, "URL: {$conf['url']}");
	\Utils\log_nl($log, 'VCS: '.$conf['vcs']);

	if ($conf['vcs'] === 'git') {
		if (!is_dir('repo.git')) {
			\Utils\log_exec($log, "git clone --mirror '{$conf['url']}' repo.git");
		}
		\E\chdir('repo.git');
		\Utils\log_exec($log, 'git fetch --all -f');
		\Utils\log_exec($log, 'git remote update -p');

		if (intval(\Utils\log_exec($log, 'git branch | grep [*] | wc -l')) == 0) {
			\Utils\log_nl($log, 'No default branch - trying to determine new default');
			$default = \Utils\log_exec($log, "git remote show origin | grep 'HEAD branch' | egrep -o '([^ ]+)\$'");
			\Utils\log_exec($log, "git symbolic-ref HEAD 'refs/heads/{$default}'");
			\Utils\log_exec($log, "git fetch --all -f");
			\Utils\log_exec($log, "git remote update -p");
			if (intval(\Utils\log_exec($log, 'git branch | grep [*] | wc -l')) == 0) {
				\Utils\log_nl($log, 'Could not determine new default branch!');
				return false;
			}
		}

		\Utils\log_exec($log, 'git reflog expire --expire=now --all');
		\Utils\log_exec($log, 'git repack -ad');
		\Utils\log_exec($log, 'git prune');

		$default = \Utils\log_exec($log, "git remote show origin | grep 'HEAD branch' | egrep -o '([^ ]+)\$'");
		$rev = \Utils\log_exec($log, "git log '--date=format-local:%Y-%m-%d %H:%M:%S' --first-parent '--format=format:%H%x09%ad' -n1 '{$default}'");
		$cnt = intval(\Utils\log_exec($log, "git log '--format=format:%H' '{$default}' | sort | uniq | wc -l"));
		$rev = explode("\t", $rev);
		$rev = [
			'rev' => $rev[0],
			'stamp' => strtotime($rev[1]),
			'count' => $cnt,
			];
	}
	else {
		$retried = false;

		RETRY_SVN:
		try {
			if (!is_dir('repo.svn')) {
				\Utils\log_exec($log, "svn co '{$conf['url']}' repo.svn/");
			}
			\E\chdir('repo.svn');
			\Utils\log_exec($log, "svn switch --ignore-ancestry --force --accept tf '{$conf['url']}/'");
			\Utils\log_exec($log, 'svn cleanup');
			\Utils\log_exec($log, 'svn cleanup --remove-unversioned --remove-ignored');
			\Utils\log_exec($log, 'svn revert -R .');
			\Utils\log_exec($log, 'svn up --force --accept tf');
		}
		catch (Exception $e) {
			if (!$retried) {
				\Utils\log_nl($log, "Subversion repo failed: {$e}");
				\E\chdir('..');
				\Utils\log_exec($log, 'rm -rf repo.svn');
				$retried = true;
				goto RETRY_SVN;
			}
			else {
				\Utils\log_nl($log, "Subversion retry also failed: {$e}");
				return false;
			}
		}

		$rev = \Utils\log_exec($log, 'svn info --show-item last-changed-revision && svn info --show-item last-changed-date');
		$rev = explode("\n", $rev);
		$rev = [
			'rev' => intval($rev[0]),
			'stamp' => strtotime($rev[1]),
			'count' => intval($rev[0]),
			];
	}

	fclose($log);
	\E\chdir($pwd);

	if ($rev === false) {
		return $rev;
	}

	$rev['thash'] = \Utils\sha256_b64x($conf['chash'].var_export($rev, true));
	$rev['changed'] = false;

	$db = \Db\get_rw();
	$db->beginTransaction();
	$orev = $db->prepexec("SELECT p_id, r_rev, r_stamp, r_count, r_thash FROM package_repo WHERE p_id = ? AND r_rev = ?", [$conf['id'], $rev['rev']])->fetchAll();
	if (!empty($orev)) {
		$orev = $orev[0];
		if ($orev['r_thash'] !== $rev['thash']) {
			$db->prepexec("UPDATE package_repo SET r_rev = ?, r_stamp = ?, r_count = ?, r_thash = ?, r_version = '' WHERE p_id = ? AND r_rev = ?", [$rev['rev'], $rev['stamp'], $rev['count'], $rev['thash'], $conf['id'], $rev['rev']]);
			$rev['changed'] = true;
		}
	}
	else {
		$db->prepexec("INSERT INTO package_repo (p_id, r_rev, r_stamp, r_count, r_thash) VALUES (?, ?, ?, ?, ?)", [$conf['id'], $rev['rev'], $rev['stamp'], $rev['count'], $rev['thash']]);
		$rev['changed'] = true;
	}
	$db->prepexec("DELETE FROM package_repo WHERE p_id = ? AND r_count > ?", [$conf['id'], $rev['count']]);
	$db->commit();

	return $rev;
}

function make_tarball(array $conf, array $rev) {
	$pwd = getcwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/tars", 0711, true);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars", 0711, true);

	@mkdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}", 0711, true);
	\E\chdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}");

	$stamp = date('Ymd-His');
	$log = \E\fopen($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/tars/{$stamp}.log", 'wb');
	\Utils\log_nl($log, 'VCS: '.$conf['vcs']);
	\Utils\log_nl($log, 'Rev: '.$rev['rev']);

	$tar = [];

	if ($conf['vcs'] === 'git') {
		\Utils\log_exec($log, "git clone --shallow-submodules '{$_ENV['WOLFPKG_WORKDIR']}/packages/{$fl}/{$conf['name']}/repo.git' '{$rev['rev']}'");
		\E\chdir($rev['rev']);
		\Utils\log_exec($log, "git reset --hard '{$rev['rev']}'");
		\Utils\log_exec($log, 'git submodule update --init --depth 1 --recursive || git submodule update --init --depth 100 --recursive');

		$root = '';
		if (strlen($conf['root'])) {
			$root = escapeshellarg($conf['root']);
		}

		$trev = \Utils\log_exec($log, "git log '--date=format-local:%Y-%m-%d %H:%M:%S' --first-parent '--format=format:%H%x09%ad' -n1 {$root}");
		$tcnt = intval(\Utils\log_exec($log, "git log '--format=format:%H' {$root} | sort | uniq | wc -l"));
		$trev = explode("\t", $trev);
		$tar = [
			'rev' => $trev[0],
			'stamp' => strtotime($trev[1]),
			'count' => $tcnt,
			];

		if ($root) {
			$rnd = bin2hex(random_bytes(8));
			\Utils\log_exec($log, "mv -v {$root} '../{$rnd}'");
			\E\chdir('..');
			\Utils\log_exec($log, "rm -rf '{$rev['rev']}'");
			\Utils\log_exec($log, "mv -v '{$rnd}' '{$rev['rev']}'");
			\E\chdir($rev['rev']);
		}
	}
	else {
	}

	fclose($log);
	\E\chdir($pwd);
}
