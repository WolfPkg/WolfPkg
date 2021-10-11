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
			'chroot' => '',
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

		if ($conf['vcs'] !== 'git' && strlen($conf['chroot'])) {
			throw new \RuntimeException("{$pkname}: chroot only makes sense for git repos");
		}
		if (!preg_match('~^https?://[^/]+~', $conf['url'])) {
			throw new \RuntimeException("{$pkname}: invalid URL '{$conf['url']}'");
		}
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
	$log = new \Utils\Log($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/repo/{$stamp}.log");
	$log->ln("URL: {$conf['url']}");
	$log->ln('VCS: '.$conf['vcs']);

	if ($conf['vcs'] === 'git') {
		if (!is_dir('repo.git')) {
			$log->exec("git clone --mirror '{$conf['url']}' repo.git");
		}
		\E\chdir('repo.git');
		$log->exec('git fetch --all -f');
		$log->exec('git remote update -p');

		if (intval($log->exec('git branch | grep [*] | wc -l')) == 0) {
			$log->ln('No default branch - trying to determine new default');
			$default = $log->exec("git remote show origin | grep 'HEAD branch' | egrep -o '([^ ]+)\$'");
			$log->exec("git symbolic-ref HEAD 'refs/heads/{$default}'");
			$log->exec("git fetch --all -f");
			$log->exec("git remote update -p");
			if (intval($log->exec('git branch | grep [*] | wc -l')) == 0) {
				$log->ln('Could not determine new default branch!');
				return false;
			}
		}

		$log->exec('git reflog expire --expire=now --all');
		$log->exec('git repack -ad');
		$log->exec('git prune');

		$default = $log->exec("git remote show origin | grep 'HEAD branch' | egrep -o '([^ ]+)\$'");
		$rev = $log->exec("git log '--date=format-local:%Y-%m-%d %H:%M:%S' --first-parent '--format=format:%H%x09%ad' -n1 '{$default}'");
		$cnt = intval($log->exec("git log '--format=format:%H' '{$default}' | sort | uniq | wc -l"));
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
				$log->exec("svn co '{$conf['url']}' repo.svn/");
			}
			\E\chdir('repo.svn');
			$log->exec("svn switch --ignore-ancestry --force --accept tf '{$conf['url']}/'");
			$log->exec('svn cleanup');
			$log->exec('svn cleanup --remove-unversioned --remove-ignored');
			$log->exec('svn revert -R .');
			$log->exec('svn up --force --accept tf');
		}
		catch (Exception $e) {
			if (!$retried) {
				$log->ln("Subversion repo failed: {$e}");
				\E\chdir('..');
				$log->exec('rm -rf repo.svn');
				$retried = true;
				goto RETRY_SVN;
			}
			else {
				$log->ln("Subversion retry also failed: {$e}");
				return false;
			}
		}

		$rev = $log->exec('svn info --show-item last-changed-revision && svn info --show-item last-changed-date');
		$rev = explode("\n", $rev);
		$rev = [
			'rev' => intval($rev[0]),
			'stamp' => strtotime($rev[1]),
			'count' => intval($rev[0]),
			];
	}

	$log->close();
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

function make_tarball(array $conf, string $rev) {
	$pwd = getcwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/tars", 0711, true);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars", 0711, true);

	@mkdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}", 0711, true);
	\E\chdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}");

	$stamp = date('Ymd-His');
	$log = new \Utils\Log($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/tars/{$stamp}.log");
	$log->ln('VCS: '.$conf['vcs']);
	$log->ln('Rev: '.$rev);

	$log->exec("rm -rf '{$rev}'");
	$tar = [];

	if ($conf['vcs'] === 'git') {
		$log->exec("git clone --shallow-submodules '{$_ENV['WOLFPKG_WORKDIR']}/packages/{$fl}/{$conf['name']}/repo.git' '{$rev}'");
		\E\chdir($rev);
		$log->exec("git reset --hard '{$rev}'");
		if (file_exists('.gitmodules') && filesize('.gitmodules')) {
			$log->exec('git submodule update --init --depth 1 --recursive || git submodule update --init --depth 100 --recursive');
		}

		$root = '';
		if (strlen($conf['chroot'])) {
			$root = escapeshellarg($conf['chroot']);
		}

		$trev = $log->exec("git log '--date=format-local:%Y-%m-%d %H:%M:%S' --first-parent '--format=format:%H%x09%ad' -n1 {$root}");
		$tcnt = intval($log->exec("git log '--format=format:%H' {$root} | sort | uniq | wc -l"));
		$trev = explode("\t", $trev);
		$tar = [
			'rev' => $trev[0],
			'stamp' => strtotime($trev[1]),
			'count' => $tcnt,
			];

		if ($root) {
			$rnd = bin2hex(random_bytes(8));
			$log->exec("mv -v {$root} '../{$rnd}'");
			\E\chdir('..');
			$log->exec("rm -rf '{$rev}'");
			$log->exec("mv -v '{$rnd}' '{$rev}'");
			\E\chdir($rev);
		}
	}
	else {
		$log->exec("cp -a --reflink=always '{$_ENV['WOLFPKG_WORKDIR']}/packages/{$fl}/{$conf['name']}/repo.svn' '{$rev}'");
		\E\chdir($rev);
		$log->exec("svn up --force --accept tf '-r{$rev}'");
		$log->exec('svn cleanup');
		$log->exec('svn cleanup --remove-unversioned --remove-ignored');
		$log->exec('svn revert -R .');

		$trev = $log->exec('svn info --show-item last-changed-revision && svn info --show-item last-changed-date');
		$tar = [
			'rev' => $trev[0],
			'stamp' => strtotime($trev[1]),
			'count' => intval($trev[0]),
			];
	}

	$major = 0;
	$minor = 0;
	$patch = 0;

	$data = file_get_contents($conf['version_in']);
	$version = '';
	if (preg_match('@_VERSION_MAJOR\], \[(\d+)\].*?_VERSION_MINOR\], \[(\d+)\].*?_VERSION_PATCH\], \[(\d+)\]@s', $data, $m)) {
		$log->ln('Found m4 _VERSION_MAJOR/MINOR/PATCH version');
		$version = "{$m[1]}.{$m[2]}.{$m[3]}";
	}
	else if (preg_match('@_VERSION_MAJOR = (\d+);.*?_VERSION_MINOR = (\d+);.*?_VERSION_PATCH = (\d+);@s', $data, $m)) {
		$log->ln('Found _VERSION_MAJOR/MINOR/PATCH version');
		$version = "{$m[1]}.{$m[2]}.{$m[3]}";
	}
	else if (preg_match('@__version__ = "([\d.]+)"@s', $data, $m) || preg_match('@__version__ = \'([\d.]+)\'@s', $data, $m)) {
		$log->ln('Found __version__ version');
		$version = $m[1];
	}
	else if (preg_match('@AC_INIT.*?\[([\d.]+)[^\]]*\]@s', $data, $m)) {
		$log->ln('Found AC_INIT version');
		$version = $m[1];
	}
	else if (preg_match('@\n\s*VERSION.*?([\d.]+)@s', $data, $m) || preg_match('@VERSION.*?([\d.]+)@s', $data, $m)) {
		$log->ln('Found VERSION version');
		$version = $m[1];
	}
	else if (preg_match('@PACKAGE_VERSION\s*=\s*"([\d.]+)@s', $data, $m)) {
		$log->ln('Found PACKAGE_VERSION version');
		$version = $m[1];
	}
	else if (preg_match('@\nVersion ([\d.]+)@s', $data, $m)) {
		$log->ln('Found Version version');
		$version = $m[1];
	}
	else {
		throw new \RuntimeException('No version found!');
	}

	if (preg_match('@^(\d+)$@', $version, $m)) {
		$patch = $m[1];
	}
	else if (preg_match('@^(\d+)\.(\d+)$@', $version, $m)) {
		$major = $m[1];
		$minor = $m[2];
	}
	else if (preg_match('@^(\d+)\.(\d+)\.(\d+)$@', $version, $m)) {
		$major = $m[1];
		$minor = $m[2];
		$patch = $m[3];
	}

	$tar['version'] = "{$major}.{$minor}.{$patch}";

	$includes = ['test/tests\.json', 'test/.*-input\.txt', 'test/.*-expected\.txt', 'test/.*-gold\.txt'];
	$excludes = ['\.svn.*', '\.git.*', '\.gut.*', '\.circleci.*', '\.travis.*', '\.clang.*', '\.editorconfig', '\.readthedocs.*', 'autogen\.sh', 'cmake\.sh', 'CONTRIBUTING.*', 'INSTALL', 'Jenkinsfile'];
	if (!empty($conf['excludes'])) {
		foreach ($conf['excludes'] as $p) {
			if (preg_match('@^\+ (.+)$@', $p, $m)) {
				$includes[] = $m[1];
			}
			else if (preg_match('@^\- (.+)$@', $p, $m)) {
				$excludes[] = $m[1];
			}
			else {
				$p = preg_replace('@\.@', '\\.', $p);
				$p = preg_replace('@\*@', '.*', $p);
				$p = preg_replace('@\?@', '.', $p);
				$excludes[] = "{$p}.*";
			}
		}
		$log->ln('Includes: '.implode(' ', $includes));
		$log->ln('Excludes: '.implode(' ', $excludes));
	}

	$files = \Utils\split("\n", trim(shell_exec('find . ! -type d')));
	foreach ($files as $f) {
		$f = substr($f, 2);
		$keep = false;
		foreach ($includes as $p) {
			if (preg_match("@^$p$@", $f)) {
				$log->ln("Keeping '$f'");
				$keep = true;
				break;
			}
		}
		if ($keep) {
			continue;
		}
		foreach ($excludes as $p) {
			if (preg_match("@^$p$@", $f)) {
				if (is_file($f)) {
					$log->ln("Removing file '$f'");
					unlink($f);
				}
				else {
					$log->exec("rm -rfv '$f'");
				}
			}
		}
	}
	while ($o = $log->exec('find . -type d -empty -print0 | LC_ALL=C sort -zr | xargs -0rn1 rm -rfv')) {
	}

	$sls = \Utils\split("\n", $log->exec('find . -type l'));
	foreach ($sls as $l) {
		$d = dirname($l);
		$s = readlink($l);
		if (!file_exists("{$d}/{$s}")) {
			$log->ln("Unresolvable symlink: $l");
			unlink($l);
		}
	}

	# OS tools should only try to use OS binaries
	$files = \Utils\split("\n", trim($log->exec("grep -rl '^#!/usr/bin/env'", true)));
	foreach ($files as $f) {
		$data = file_get_contents($f);
		if (strpos($data, '#!/usr/bin/env perl') !== false) {
			$log->ln("Fixing Perl shebang in '$f'");
			$data = preg_replace('~^#!/usr/bin/env perl~m', '#!/usr/bin/perl', $data);
		}
		if (strpos($data, '#!/usr/bin/env python') !== false) {
			$log->ln("Fixing Python shebang in '$f'");
			$data = preg_replace('~^#!/usr/bin/env python~m', '#!/usr/bin/python', $data);
		}
		if (strpos($data, '#!/usr/bin/env bash') !== false) {
			$log->ln("Fixing Bash shebang in '$f'");
			$data = preg_replace('~^#!/usr/bin/env bash~m', '#!/usr/bin/bash', $data);
		}
		file_put_contents($f, $data);
	}

	# Replace @APERTIUM_AUTO_VERSION@ with git/svn revision
	$files = \Utils\split("\n", trim($log->exec("grep -rl '@APERTIUM_AUTO_VERSION@'", true)));
	foreach ($files as $f) {
		$data = file_get_contents($f);
		$data = str_replace('@APERTIUM_AUTO_VERSION@', $tar['rev'], $data);
		file_put_contents($f, $data);
	}

	$tar['thash'] = \Utils\sha256_b64x($rev.var_export($tar, true));

	$db = \Db\get_rw();
	$db->beginTransaction();
	$db->prepexec("DELETE FROM package_tar WHERE p_id = ? AND r_rev = ?", [$conf['id'], $rev]);
	$db->prepexec("INSERT INTO package_tar (p_id, r_rev, t_rev, t_stamp, t_count, t_version, t_thash) VALUES (?, ?, ?, ?, ?, ?, ?)", [$conf['id'], $rev, $tar['rev'], $tar['stamp'], $tar['count'], $tar['version'], $tar['thash']]);
	$db->commit();

	$log->close();
	\E\chdir($pwd);

	return $tar;
}
