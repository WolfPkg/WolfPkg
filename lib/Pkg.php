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

function load_conf($path, $pkname, $raw = false) {
	return parse_conf(file_get_contents($path), $pkname, $raw);
}

function read_control($path) {
	$c = file_get_contents("{$path}/debian/control");
	$c = preg_replace('~,[\s\n]+~', ', ', $c);
	return $c;
}

function get_kinds() {
	$db = \Db\get_rw();
	$ks = [];
	$stm = $db->prepexec("SELECT k_id, k_name FROM kinds");
	while ($row = $stm->fetch()) {
		$ks[$row['k_id']] = $row['k_name'];
	}
	return $ks;
}

function mirror_repo($conf) {
	$rev = false;
	$pwd = getcwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/repo", 0711, true);
	chdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}");

	$stamp = date('Ymd-His');
	$log = fopen($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/repo/{$stamp}.log", 'wb');
	\Utils\log_nl($log, "URL: {$conf['url']}");
	\Utils\log_nl($log, 'VCS: '.$conf['vcs']);

	if ($conf['vcs'] === 'git') {
		if (!is_dir('repo.git')) {
			\Utils\log_exec($log, "git clone --mirror '{$conf['url']}' repo.git");
		}
		chdir('repo.git');
		\Utils\log_exec($log, 'git fetch --all -f');
		\Utils\log_exec($log, 'git remote update -p');

		if (intval(\Utils\log_exec($log, 'git branch | grep [*] | wc -l')) == 0) {
			\Utils\log_nl($log, 'No default branch - trying to determine new default');
			$head = \Utils\log_exec($log, "git remote show origin | grep 'HEAD branch' | egrep -o '([^ ]+)\$'");
			\Utils\log_exec($log, "git symbolic-ref HEAD 'refs/heads/$head'");
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
		$cnt = intval(\Utils\log_exec($log, "git log '--format=format:\%H' '{$default}' | sort | uniq | wc -l"));
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
			chdir('repo.svn');
			\Utils\log_exec($log, "svn switch --ignore-ancestry --force --accept tf '{$conf['url']}/'");
			\Utils\log_exec($log, 'svn cleanup');
			\Utils\log_exec($log, 'svn cleanup --remove-unversioned --remove-ignored');
			\Utils\log_exec($log, 'svn revert -R .');
			\Utils\log_exec($log, 'svn up --force --accept tf');
		}
		catch (Exception $e) {
			if (!$retried) {
				\Utils\log_nl($log, "Subversion repo failed: {$e}");
				chdir('..');
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
	chdir($pwd);

	return $rev;
}
