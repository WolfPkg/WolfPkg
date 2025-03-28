<?php
declare(strict_types=1);
namespace Pkg;

function enum_packages(string $filter = '*'): array {
	$pkgs = [];
	$fs = glob("packages/[a-z]*/{$filter}/pkg.json5");
	foreach ($fs as $f) {
		$f = preg_replace('~^\Q'.$_ENV['WOLFPKG_ROOT'].'\E~', '', $f);
		$f = preg_replace('~/pkg\.json5$~', '', $f);
		preg_match('~([^/]+)$~', $f, $m);
		$pkgs[$f] = $m[1];
	}
	return $pkgs;
}

function parse_conf(string $path, string $j5, string $pkname, bool $raw = false): array {
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
			'path' => dirname($path),
			'chroot' => '',
			'vcs' => 'git',
			'version_in' => 'configure.ac',
			'excludes' => [],
			'non_targets' => [],
			'min_ram' => 0,
			'bundle_self' => false,
			'bundle_deps' => false,
			'enabled' => true,
			];

		if (!preg_match('~^https://github\.com/[^/]+?/[^/]+$~', $def['url'])) {
			$def['vcs'] = 'svn';
		}
		if (preg_match('~/(pairs|languages)/\Q'.$pkname.'\E/~', $path)) {
			$def['bundle_self'] = true;
			$def['bundle_deps'] = true;
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
	return parse_conf($path, \E\file_get_contents($path), $pkname, $raw);
}

function get($pkname) {
	$db = \Db\get_rw();
	$pkg = $db->prepexec("SELECT p_id, p_name, p_path, p_chash FROM packages WHERE p_name = ?", [$pkname])->fetchAll();
	if (empty($pkg)) {
		return null;
	}
	$conf = load_conf($_ENV['WOLFPKG_ROOT']."/{$pkg[0]['p_path']}/pkg.json5", $pkname);
	$conf['id'] = $pkg[0]['p_id'];
	$conf['chash'] = $pkg[0]['p_chash'];
	$conf['path'] = $pkg[0]['p_path'];
	return $conf;
}

function read_control(string $path): string {
	$c = \E\file_get_contents($path);
	$c = preg_replace('~,[\s\n]+~', ', ', $c);
	return $c;
}

function get_released(array $conf): string {
	$rev = trim(\Utils\head($_ENV['WOLFPKG_ROOT']."/{$conf['path']}/debian/changelog"));
	if (preg_match('@\((?:\d+:)?[\d.]+\+[gs]([^-)]+)@', $rev, $m)) {
		$rev = $m[1];
	}
	else if (preg_match('@\((?:\d+:)?(\d+\.\d+\.\d+)-\d+@', $rev, $m)) {
		$rev = "v{$m[1]}";
	}
	else {
		throw new \RuntimeException("No usable release version found for {$conf['name']}");
	}

	$epoch = 0;
	if (preg_match('@\((\d+:)[\d.]+\+[gs][^-)]+@', $rev, $m) || preg_match('@\((\d+:)\d+\.\d+\.\d+-\d+@', $rev, $m)) {
		$epoch = intval($m[1]);
	}

	return [
		'epoch' => $epoch,
		'rev' => $rev,
		];
}

function closest_version(array $conf, string $min, object $log = null): string {
	$rev = null;
	if (!$log) {
		$log = new \Utils\Log();
	}

	$chlog = \E\file_get_contents($_ENV['WOLFPKG_ROOT']."/{$conf['path']}/debian/changelog");
	if (preg_match('~^\Q'.$conf['name'].'\E \((?:\d+:)?\Q'.$min.'\E\+[gs]([^-)]+)~m', $chlog, $m)) {
		$log->ln('Found matching changelog +g or +s revision');
		$rev = $m[1];
	}
	else if (preg_match('~^\Q'.$conf['name'].'\E \((?:\d+:)?\Q'.$min.'\E-\d+~m', $chlog, $m)) {
		$log->ln('Found matching changelog version');
		$rev = "v{$min}";
	}
	else {
		$pwd = new \Utils\KeepCwd();
		$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);

		$stamp = date('Y-m-d H:i:s', intval($_ENV['WOLFPKG_PK_STAMP']));
		if ($conf['vcs'] === 'git') {
			\E\chdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/repo.git");
			$rev = $log->exec("git tag -l '{$min}'");
			if ($rev === $min) {
				$log->ln("Found matching git tag {$rev}");
			}
			else {
				$default = $log->exec("git remote show origin | grep 'HEAD branch' | egrep -o '([^ ]+)\$'");
				$rev = $log->exec("git log --first-parent '--format=format:%H' '--until={$stamp}' -n1 '{$default}'");
				$log->ln("Latest git commit before {$stamp}: {$rev}");
			}
		}
		else {
			\E\chdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/repo.git");
			$rev = $log->exec("svn info --show-item last-changed-revision '-r{{$stamp}}'");
			$log->ln("Latest svn commit before {$stamp}: {$rev}");
		}
		$pwd = null;
	}

	return $rev;
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
	$pwd = new \Utils\KeepCwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/repo", 0711, true);
	\E\chdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}");

	$stamp = date('Ymd-His');
	$rev = [
		'rev' => '',
		'stamp' => 0,
		'count' => 0,
		];
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
		$n_rev = $log->exec("git log '--date=format-local:%Y-%m-%d %H:%M:%S' --first-parent '--format=format:%H%x09%ad' -n1 '{$default}'");
		$cnt = intval($log->exec("git log '--format=format:%H' '{$default}' | sort | uniq | wc -l"));
		$n_rev = explode("\t", $n_rev);
		$rev['rev'] = $n_rev[0];
		$rev['stamp'] = strtotime($n_rev[1]);
		$rev['count'] = $cnt;
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

		$n_rev = $log->exec('svn info --show-item last-changed-revision && svn info --show-item last-changed-date');
		$n_rev = explode("\n", $rev);
		$rev['rev'] = intval($n_rev[0]);
		$rev['stamp'] = strtotime($n_rev[1]);
		$rev['count'] = intval($n_rev[0]);
	}

	if (empty($rev['rev'])) {
		$rev['log'] = $log->fn();
		return $rev;
	}

	$rev['thash'] = \Utils\sha256_b64x($conf['chash'].var_export($rev, true));
	$rev['changed'] = false;

	$db = \Db\get_rw();
	$db->beginTransaction();
	$orev = $db->prepexec("SELECT p_id, r_rev, r_stamp, r_count, r_thash FROM package_repo WHERE p_id = ?", [$conf['id']])->fetchAll();
	if (!empty($orev)) {
		$orev = $orev[0];
		if ($orev['r_thash'] !== $rev['thash']) {
			$db->prepexec("UPDATE package_repo SET r_rev = ?, r_stamp = ?, r_count = ?, r_thash = ? WHERE p_id = ?", [$rev['rev'], $rev['stamp'], $rev['count'], $rev['thash'], $conf['id']]);
			$log->ln('Repo updated');
			$rev['changed'] = true;
		}
	}
	else {
		$db->prepexec("INSERT INTO package_repo (p_id, r_rev, r_stamp, r_count, r_thash) VALUES (?, ?, ?, ?, ?)", [$conf['id'], $rev['rev'], $rev['stamp'], $rev['count'], $rev['thash']]);
		$log->ln('Repo inserted');
		$rev['changed'] = true;
	}
	$db->commit();

	$log->close();
	$pwd = null;

	$rev['log'] = $log->fn();
	return $rev;
}

function make_tarball(array $conf, string $rev, string $version = 'long'): array {
	$pwd = new \Utils\KeepCwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/tars", 0711, true);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars", 0711, true);

	@mkdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}", 0711, true);
	\E\chdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}");

	$stamp = date('Ymd-His');
	$tar = [
		'log' => $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/tars/{$stamp}.log",
		];
	$log = new \Utils\Log($tar['log']);
	$log->ln('VCS: '.$conf['vcs']);
	$log->ln('Rev: '.$rev);

	$log->exec("rm -rf '{$rev}'");

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
		$tar['rev'] = $trev[0];
		$tar['stamp'] = strtotime($trev[1]);
		$tar['count'] = $tcnt;

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
		$log->exec("cp -a --reflink=auto '{$_ENV['WOLFPKG_WORKDIR']}/packages/{$fl}/{$conf['name']}/repo.svn' '{$rev}'");
		\E\chdir($rev);
		$log->exec("svn up --force --accept tf '-r{$rev}'");
		$log->exec('svn cleanup');
		$log->exec('svn cleanup --remove-unversioned --remove-ignored');
		$log->exec('svn revert -R .');

		$trev = $log->exec('svn info --show-item last-changed-revision && svn info --show-item last-changed-date');
		$trev = explode("\n", $trev);
		$tar['rev'] = intval($trev[0]);
		$tar['stamp'] = strtotime($trev[1]);
		$tar['count'] = intval($trev[0]);
	}

	$tar['version'] = $version;
	if ($version === 'long' || $version === 'short') {
		$major = 0;
		$minor = 0;
		$patch = 0;

		$data = file_get_contents($conf['version_in']);
		$ver_in = '';
		if (preg_match('@_VERSION_MAJOR\], \[(\d+)\].*?_VERSION_MINOR\], \[(\d+)\].*?_VERSION_PATCH\], \[(\d+)\]@s', $data, $m)) {
			$log->ln('Found m4 _VERSION_MAJOR/MINOR/PATCH version');
			$ver_in = "{$m[1]}.{$m[2]}.{$m[3]}";
		}
		else if (preg_match('@_VERSION_MAJOR = (\d+);.*?_VERSION_MINOR = (\d+);.*?_VERSION_PATCH = (\d+);@s', $data, $m)) {
			$log->ln('Found _VERSION_MAJOR/MINOR/PATCH version');
			$ver_in = "{$m[1]}.{$m[2]}.{$m[3]}";
		}
		else if (preg_match('@MAJOR_VERSION (\d+).*?MINOR_VERSION (\d+).*?BUILD_VERSION (\d+)@s', $data, $m)) {
			$log->ln('Found MAJOR_/MINOR_/BUILD_VERSION version');
			$ver_in = "{$m[1]}.{$m[2]}.{$m[3]}";
		}
		else if (preg_match('@__version__ = "([\d.]+)"@s', $data, $m) || preg_match('@__version__ = \'([\d.]+)\'@s', $data, $m)) {
			$log->ln('Found __version__ version');
			$ver_in = $m[1];
		}
		else if (preg_match('@AC_INIT.*?\[open-([\d.]+)[^\]]*\]@s', $data, $m)) {
			$log->ln('Found Manatee AC_INIT version');
			$ver_in = $m[1];
		}
		else if (preg_match('@AC_INIT.*?\[([\d.]+)[^\]]*\]@s', $data, $m)) {
			$log->ln('Found AC_INIT version');
			$ver_in = $m[1];
		}
		else if (preg_match('@\n\s*VERSION.*?([\d.]+)@s', $data, $m) || preg_match('@VERSION.*?([\d.]+)@s', $data, $m)) {
			$log->ln('Found VERSION version');
			$ver_in = $m[1];
		}
		else if (preg_match('@PACKAGE_VERSION\s*=\s*"([\d.]+)@s', $data, $m)) {
			$log->ln('Found PACKAGE_VERSION version');
			$ver_in = $m[1];
		}
		else if (preg_match('@\nVersion ([\d.]+)@s', $data, $m)) {
			$log->ln('Found Version version');
			$ver_in = $m[1];
		}
		else {
			throw new \RuntimeException('No version found!');
		}

		if (preg_match('@^(\d+)$@', $ver_in, $m)) {
			$patch = $m[1];
		}
		else if (preg_match('@^(\d+)\.(\d+)$@', $ver_in, $m)) {
			$major = $m[1];
			$minor = $m[2];
		}
		else if (preg_match('@^(\d+)\.(\d+)\.(\d+)$@', $ver_in, $m)) {
			$major = $m[1];
			$minor = $m[2];
			$patch = $m[3];
		}

		$tar['version'] = "{$major}.{$minor}.{$patch}";
	}

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

	$files = \Utils\split("\n", trim(shell_exec('find . -not -type d')));
	foreach ($files as $f) {
		$f = substr($f, 2);
		$keep = false;
		foreach ($includes as $p) {
			if (preg_match("@^$p$@", $f)) {
				$log->ln("Keeping '{$f}'");
				$keep = true;
				break;
			}
		}
		if ($keep) {
			continue;
		}
		foreach ($excludes as $p) {
			if (preg_match("@^{$p}$@", $f)) {
				if (is_file($f)) {
					$log->ln("Removing file '{$f}'");
					unlink($f);
				}
				else {
					$log->exec("rm -rfv '{$f}'");
				}
			}
		}
	}

	// Remove symlinks that are unresolvable when relative to their folder
	// Side effect: Removes absolute symlinks
	$sls = \Utils\split("\n", $log->exec('find . -type l'));
	foreach ($sls as $l) {
		$d = dirname($l);
		$s = readlink($l);
		if (!file_exists("{$d}/{$s}")) {
			$log->ln("Unresolvable symlink: {$l}");
			unlink($l);
		}
	}

	// Remove all empty folders
	while ($o = $log->exec('find . -type d -empty -print0 | LC_ALL=C sort -zr | xargs -0rn1 rm -rfv')) {
	}

	// OS tools should only try to use OS binaries
	$files = \Utils\split("\n", trim($log->exec_null("grep -srl '^#!/usr/bin/env'; grep -Psrl '^#!/(usr|usr/local|opt/local)/bin/' *")));
	foreach ($files as $f) {
		$data = file_get_contents($f);
		if (strpos($data, '#!/usr/local/') !== false) {
			$log->ln("Fixing /usr/local shebang in '{$f}'");
			$data = preg_replace('~^#!/usr/local/~m', '#!/usr/', $data);
		}
		if (strpos($data, '#!/opt/local/') !== false) {
			$log->ln("Fixing /opt/local shebang in '{$f}'");
			$data = preg_replace('~^#!/opt/local/~m', '#!/usr/', $data);
		}
		if (strpos($data, '#!/usr/bin/env perl') !== false) {
			$log->ln("Fixing Perl shebang in '{$f}'");
			$data = preg_replace('~^#!/usr/bin/env perl~m', '#!/usr/bin/perl', $data);
		}
		if (strpos($data, '#!/usr/bin/env python') !== false) {
			$log->ln("Fixing Python shebang in '{$f}'");
			$data = preg_replace('~^#!/usr/bin/env python~m', '#!/usr/bin/python', $data);
		}
		if (strpos($data, '#!/usr/bin/env bash') !== false) {
			$log->ln("Fixing env Bash shebang in '{$f}'");
			$data = preg_replace('~^#!/usr/bin/env bash~m', '#!/bin/bash', $data);
		}
		if (strpos($data, '#!/usr/bin/bash') !== false) {
			$log->ln("Fixing usr Bash shebang in '{$f}'");
			$data = preg_replace('~^#!/usr/bin/bash~m', '#!/bin/bash', $data);
		}
		\E\file_put_contents($f, $data);
	}

	// Replace @APERTIUM_AUTO_VERSION@ with git/svn revision
	// TODO: Move into hook
	$files = \Utils\split("\n", trim($log->exec_null("grep -srl '@APERTIUM_AUTO_VERSION@'")));
	foreach ($files as $f) {
		$data = file_get_contents($f);
		$data = str_replace('@APERTIUM_AUTO_VERSION@', $tar['rev'], $data);
		\E\file_put_contents($f, $data);
	}

	if ($version === 'long') {
		if ($conf['vcs'] === 'git') {
			$tar['version'] .= "+g{$tar['count']}~".substr($tar['rev'], 0, 8);
		}
		else {
			$tar['version'] .= "+s{$tar['rev']}";
		}
	}
	$folder = $conf['name'].'-'.$tar['version'];
	$mtime = date('Y-m-d H:i:s', $tar['stamp']);

	\E\chdir('..');
	$log->exec("rm -rf '{$folder}'");

	\E\rename($rev, $folder);
	$log->exec("find '{$folder}' -not -type d | LC_ALL=C sort > orig.lst");
	$log->exec("tar -I 'xz -T0 -4' --owner=0 --group=0 --no-acls --no-selinux --no-xattrs '--mtime={$mtime}' -cf '{$conf['name']}_{$tar['version']}.tar.xz' -T orig.lst");
	\E\rename($folder, $rev);

	// TODO: A hash with includes/excludes
	$tar['thash'] = \Utils\sha256_file_b64x("{$conf['name']}_{$tar['version']}.tar.xz");
	$tar['path'] = $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar['thash']}";
	$log->exec("rm -rf '{$tar['path']}'");
	$log->exec("cp -a --reflink=auto '{$rev}' '{$tar['path']}'");
	$tar['path'] .= '.tar.xz';
	$tar['thash_dots'] = $tar['thash'];
	$tar['path_dots'] = $tar['path'];
	\E\rename("{$conf['name']}_{$tar['version']}.tar.xz", $tar['path']);

	$tar['version_dots'] = preg_replace('@[+~]@', '.', $tar['version']);
	if ($tar['version_dots'] !== $tar['version']) {
		$folder = $conf['name'].'-'.$tar['version_dots'];
		$log->exec("rm -rf '{$folder}'");

		\E\rename($rev, $folder);
		$log->exec("find '{$conf['name']}-{$tar['version_dots']}' -not -type d | LC_ALL=C sort > orig.lst");
		$log->exec("tar -I 'xz -T0 -4' --owner=0 --group=0 --no-acls --no-selinux --no-xattrs '--mtime={$mtime}' -cf '{$conf['name']}_{$tar['version_dots']}.tar.xz' -T orig.lst");
		\E\rename($folder, $rev);

		$tar['thash_dots'] = \Utils\sha256_file_b64x("{$conf['name']}_{$tar['version_dots']}.tar.xz");
		$tar['path_dots'] = $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar['thash_dots']}";
		$log->exec("rm -rf '{$tar['path_dots']}'");
		$log->exec("cp -a --reflink=auto '{$rev}' '{$tar['path_dots']}'");
		$tar['path_dots'] .= '.tar.xz';
		\E\rename("{$conf['name']}_{$tar['version_dots']}.tar.xz", $tar['path_dots']);
	}

	$db = \Db\get_rw();
	$db->beginTransaction();
	$db->prepexec("DELETE FROM package_tars WHERE p_id = ? AND r_rev = ? AND t_version = ?", [$conf['id'], $rev, $tar['version']]);
	$db->prepexec("INSERT INTO package_tars (p_id, r_rev, t_rev, t_stamp, t_count, t_version, t_thash, t_version_dots, t_thash_dots) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [$conf['id'], $rev, $tar['rev'], $tar['stamp'], $tar['count'], $tar['version'], $tar['thash'], $tar['version_dots'], $tar['thash_dots']]);
	$db->commit();

	$log->close();
	$pwd = null;

	return $tar;
}

function get_tarball(array $conf, ?string $rev = null, string $version = 'long'): array {
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);

	$db = \Db\get_rw();
	$tar = $db->prepexec("SELECT t_thash, t_stamp FROM package_tars WHERE p_id = ? AND t_version = ?", [$conf['id'], $version])->fetchAll();
	if (!empty($tar[0]['t_thash']) && file_exists($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['t_thash']}.tar.xz")) {
		return [
			'logs' => ['Existing tarball found'],
			'version' => $version,
			'thash' => $tar[0]['t_thash'],
			'stamp' => intval($tar[0]['t_stamp']),
			'path' => $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['t_thash']}.tar.xz",
			];
	}

	$repo = mirror_repo($conf);
	if ($version === 'released') {
		$rev = get_released($conf)['rev'];
		$version = 'short';

		$tar = $db->prepexec("SELECT t_thash, t_stamp FROM package_tars WHERE p_id = ? AND t_version = ?", [$conf['id'], $rev])->fetchAll();
		if (!empty($tar[0]['t_thash']) && file_exists($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['t_thash']}.tar.xz")) {
			return [
				'logs' => ['Existing tarball found'],
				'version' => $rev,
				'thash' => $tar[0]['t_thash'],
				'stamp' => intval($tar[0]['t_stamp']),
				'path' => $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['t_thash']}.tar.xz",
				];
		}
	}
	else if ($version === 'HEAD') {
		$rev = $repo['rev'];
		$version = 'long';

		$tar = $db->prepexec("SELECT t_version, t_thash, t_stamp FROM package_tars WHERE p_id = ? AND r_rev = ? AND t_version LIKE '%+%' AND t_version LIKE '%~%' ORDER BY t_count DESC LIMIT 1", [$conf['id'], $rev])->fetchAll();
		if (!empty($tar[0]['t_thash']) && file_exists($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['t_thash']}.tar.xz")) {
			return [
				'logs' => ['Existing tarball found'],
				'version' => $tar[0]['t_version'],
				'thash' => $tar[0]['t_thash'],
				'stamp' => intval($tar[0]['t_stamp']),
				'path' => $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['t_thash']}.tar.xz",
				];
		}
	}

	$tar = make_tarball($conf, $rev, $version);
	$tar['logs'] = [$repo['log'], $tar['log']];
	return $tar;
}
