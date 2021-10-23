<?php
declare(strict_types=1);
namespace Build;

function make_debian_base(array $conf, string $version, string $dep_ver = 'head', string $bundle_ver = ''): array {
	$pwd = new \Utils\KeepCwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/base", 0711, true);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars", 0711, true);

	$base = [
		'version' => $version,
		'bundle' => $bundle_ver,
		];

	$bundle = $conf['bundle_deps'] ? '-'.$bundle_ver : '';

	$stamp = date('Ymd-His');
	$base['log'] = $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/base/{$stamp}.log";
	$log = new \Utils\Log($base['log']);
	$log->ln('Version: '.$version);
	$log->ln("Bundle: {$conf['bundle_deps']}, {$bundle_ver}, {$bundle}");

	$path = $_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}/{$version}{$bundle}";
	$log->exec("rm -rf '{$path}'");
	@mkdir($path, 0711, true);
	\E\chdir($path);

	$tar = \Pkg\get_tarball($conf, null, $version);
	$log->ln('Log tarball: '.implode("\t", $tar['logs']));
	$base['thash'] = $tar['thash'];
	$base['path_tar'] = $tar['path'];

	$log->exec("cp -av --reflink=auto '{$tar['path']}' './{$conf['name']}_{$version}.tar.xz'");
	$log->exec("tar -Jxf '{$conf['name']}_{$version}.tar.xz'");
	\E\chdir("{$conf['name']}-{$version}");

	$hooks = glob("{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/_hooks/010-*");
	if (!empty($hooks)) {
		\Utils\putenv('WOLFPKG_PK_DEP_VER', $dep_ver);
		\Utils\putenv('WOLFPKG_PK_STAMP', $tar['stamp']);
		foreach ($hooks as $hook) {
			$hook = realpath($hook);
			$log->exec($hook);
		}
	}

	// Bundle to avoid version drift
	$did_bundle = false;
	$cnfs = ['control' => '', 'copyright' => '', 'rules' => ''];
	$rules = \E\file_get_contents("{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian/rules");
	if ($bundle && file_exists('configure.ac') && !preg_match('@dh_auto_configure|dh_auto_build@', $rules)) {
		$cnfs['rules'] = $rules;

		$copyright = [];
		foreach (preg_split('~\n\n+~', \E\file_get_contents("{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian/copyright")) as $f) {
			preg_match('@^([^\n]+)\n(.+)$@s', $f, $m);
			$copyright[$m[1]] = $m[2];
		}

		$cnfs['rules'] = preg_replace('@(\n%:)@s', "\nNUMJOBS = 1\nifneq (,$(filter parallel=%,$(DEB_BUILD_OPTIONS)))\n\tNUMJOBS = $(patsubst parallel=%,%,$(filter parallel=%,$(DEB_BUILD_OPTIONS)))\nendif\n$1", $cnfs['rules']);
		$cnfs['control'] = \Pkg\read_control("{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian/control");
		preg_match('@(Build-Depends:\s*[^\n]+)@', $cnfs['control'], $bdeps);
		$bdeps = $bdeps[1];
		$ss = ['override_dh_auto_configure:', 'override_dh_auto_build:'];
		$withlang = '';

		$deps = [];
		$add_deps = function($config) use(&$log, &$bdeps, &$deps) {
			$config = preg_replace('~(#|dnl )[^\n]+~s', '', \E\file_get_contents($config));
			if (preg_match_all('@AP_CHECK_LING\((.+?)\)@', $config, $ms, PREG_PATTERN_ORDER)) {
				foreach ($ms[1] as $dep) {
					if (preg_match('@\[(.+?)\], \[(.+?)\](?:, \[(.+?)\])?@', $dep, $m)) {
						$deps[$m[2]] = ['n' => intval($m[1]), 'p' => $m[2], 'v' => ($m[3] ?? '0.0.1')];
						$log->ln("Potential bundle: {$m[2]}");
					}
				}
			}
			if (preg_match('@(giella-core) \([<>= ]*(.+?)\)@', $bdeps, $m)) {
				$deps[$m[1]] = ['n' => 0, 'p' => $m[1], 'v' => $m[2]];
				$log->ln("Potential bundle: {$m[1]}");
			}
			if (preg_match('@(giella-common) \([<>= ]*(.+?)\)@', $bdeps, $m)) {
				$deps[$m[1]] = ['n' => 0, 'p' => $m[1], 'v' => $m[2]];
				$log->ln("Potential bundle: {$m[1]}");
			}
		};
		$add_deps('configure.ac');

		reset($deps);
		for ($i=0 ; $i<count($deps) ; ++$i, next($deps)) {
			$dep = current($deps);
			[$n, $p, $v] = [$dep['n'], $dep['p'], $dep['v']];

			$b_conf = \Pkg\get($p);
			if (!$b_conf) {
				$log->ln("Not bundling {$p} (external)");
				continue;
			}
			if (!$b_conf['enabled']) {
				$log->ln("Not bundling {$p} (disabled)");
				continue;
			}
			if (!$b_conf['bundle_self']) {
				$log->ln("Not bundling {$p} (says not to)");
				continue;
			}
			$log->ln("Bundling {$p}");

			if (empty($v)) {
				$v = '0.0.1';
			}
			if (preg_match('@\Q'.$p.'\E \(.*?([\d.]+)\)@', $bdeps, $m)) {
				$gt = $log->exec_null("dpkg --compare-versions '$v' lt '{$m[1]}' && echo 1");
				if ($gt) {
					$v = $m[1];
				}
			}
			$bdeps = preg_replace('@\s+\Q'.$p.'\E [^,\n]+,?@', ' ', $bdeps);
			$bdeps = preg_replace('@\s+\Q'.$p.'\E,@', ' ', $bdeps);
			$bdeps = preg_replace('@\s+,\s+\Q'.$p.'\E\s+@', ' ', $bdeps);

			$b_tar = null;
			if ($bundle_ver === 'exact') {
				$b_tar = \Pkg\get_tarball($b_conf, "v{$v}", $v);
			}
			else if ($bundle_ver === 'released') {
				$b_tar = \Pkg\get_tarball($b_conf, null, 'released');
			}
			else {
				$b_tar = \Pkg\get_tarball($b_conf, null, 'HEAD');
			}
			$tar['stamp'] = max($tar['stamp'], $b_tar['stamp']);
			$log->ln('Log bundle tarball: '.implode("\t", $b_tar['logs']));
			$b_base = get_debian_base($b_conf, $b_tar['version'], $dep_ver, $bundle_ver);
			$log->ln("Log bundle base: {$b_base['log']}");

			$log->exec("tar -Jxf '{$b_base['path_tar']}'");
			$log->exec("tar -Jxf '{$b_base['path_control']}'");
			preg_match('@Build-Depends:\s*([^\n]+)@', \Pkg\read_control('debian/control'), $m);
			$bdeps .= ", {$m[1]} ";

			if ($p === 'giella-core' || $p === 'giella-common') {
				if ($p === 'giella-core') {
					$cnfs['rules'] = preg_replace('@(\n\%:)@s', "\nexport GIELLA_CORE=\$(CURDIR)/{$p}-{$b_base['version']}\n$1", $cnfs['rules']);
				}
				else if ($p === 'giella-common') {
					$cnfs['rules'] = preg_replace('@(\n\%:)@s', "\nexport GIELLA_SHARED=\$(CURDIR)/{$p}-{$b_base['version']}\n$1", $cnfs['rules']);
				}
				$ss[0] = preg_replace('@(:)(?:\n|$)@s', "$1\n\tcd \$(CURDIR)/{$p}-{$b_base['version']} && autoreconf -fi && ./configure && \$(MAKE) -j\$(NUMJOBS)\n", $ss[0]);
			}
			else {
				$ss[0] .= "\n\tcd \$(CURDIR)/{$p}-{$b_base['version']} && autoreconf -fi && ./configure";
				$ss[1] .= "\n\tcd \$(CURDIR)/{$p}-{$b_base['version']} && \$(MAKE) -j\$(NUMJOBS)";
				if (strpos($p, 'giella-') === 0) {
					// Delete data files that won't be used for this bundled build, but leave the infrastructure for autoreconf and configure
					$log->exec("cd '{$p}-{$b_base['version']}/' && find devtools/ tools/analysers/ tools/tokenisers/ tools/freq_test/ tools/shellscripts/ tools/grammarcheckers/ tools/spellcheckers/ tools/hyphenators/ test/tools/grammarcheckers/ test/tools/hyphenators/ test/tools/spellcheckers/ test/tools/tokeniser/ -type f 2>/dev/null | grep -vF Makefile.am | grep -vF .in | xargs -r rm -fv");

					$ss[0] .= " --with-hfst --without-xfst --enable-alignment --enable-reversed-intersect --enable-apertium --with-backend-format=foma --disable-analysers --disable-generators";
					$bdeps = preg_replace('@\s+divvun-gramcheck,?@', ' ', $bdeps);
					$withlang .= " --with-lang{$n}=\$(CURDIR)/{$p}-{$b_base['version']}/tools/mt/apertium";
				}
				else {
					$withlang .= " --with-lang{$n}=\$(CURDIR)/{$p}-{$b_base['version']}";
				}
			}

			foreach (preg_split('~\n\n+~', \E\file_get_contents('debian/copyright')) as $f) {
				if (!preg_match('@^([^\n]+)\n(.+)$@s', $f, $m)) {
					$log->ln("Copyright chunk bad: $f");
					continue;
				}
				if (strpos($m[1], 'Format') === 0 || preg_match('@^Files.*debian/@', $m[1])) {
					continue;
				}
				if (preg_match('@^(Files:.+?)\n(Copyright:.+)$@s', $f, $mn)) {
					$m = $mn;
					$m[1] = preg_replace('@(\s)(\S)@s', "$1{$p}-{$b_base['version']}/$2", $m[1]);
				}
				$copyright[$m[1]] = $m[2];
			}

			$log->exec("rm -rf debian");
			$add_deps("{$p}-{$b_base['version']}/configure.ac");
			$did_bundle = true;
		}

		ksort($copyright);

		foreach ($copyright as $k => $v) {
			if (strpos($k, 'Format') === 0) {
				$cnfs['copyright'] = "{$k}\n{$v}\n\n".$cnfs['copyright'];
				continue;
			}
			$cnfs['copyright'] .= "{$k}\n{$v}\n\n";
		}

		$cnfs['control'] = preg_replace('@Build-Depends:\s*[^\n]+@', $bdeps, $cnfs['control']);
		$ss[0] .= "\n\tdh_auto_configure --{$withlang}";
		$ss[1] .= "\n\tdh_auto_build";

		$cnfs['rules'] .= "\n".implode("\n\n", $ss)."\n";
	}

	\E\chdir('..');

	$mtime = date('Y-m-d H:i:s', $tar['stamp']);
	if ($did_bundle) {
		$folder = "{$conf['name']}-{$version}";
		$log->exec("find '{$folder}' -not -type d | LC_ALL=C sort > orig.lst");
		$log->exec("tar -I 'xz -T0 -4' --owner=0 --group=0 --no-acls --no-selinux --no-xattrs '--mtime={$mtime}' -cf '{$conf['name']}_{$base['version']}.tar.xz' -T orig.lst");
		$base['thash'] = \Utils\sha256_file_b64x("{$conf['name']}_{$tar['version']}.tar.xz");
		$base['path_tar'] = $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$base['thash']}.tar.xz";
		\E\rename("{$conf['name']}_{$version}.tar.xz", $base['path_tar']);
	}

	$log->exec("cp -av --reflink=auto '{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian' './'");
	foreach ($cnfs as $k => $v) {
		if (!empty($v)) {
			$log->ln("Overwriting debian/{$k}");
			file_put_contents("debian/{$k}", $v);
		}
	}

	$log->exec("find debian -not -type d | LC_ALL=C sort > orig.lst");
	$log->exec("tar -I 'xz -T0 -4' --owner=0 --group=0 --no-acls --no-selinux --no-xattrs '--mtime={$mtime}' -cf debian.tar.xz -T orig.lst");
	$base['chash'] = \Utils\sha256_file_b64x('debian.tar.xz');
	$base['path_control'] = $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$base['chash']}.tar.xz";
	\E\rename('debian.tar.xz', $base['path_control']);

	$db = \Db\get_rw();
	$db->beginTransaction();
	$db->prepexec("DELETE FROM package_bases WHERE p_id = ? AND t_version = ? AND b_bundled = ?", [$conf['id'], $base['version'], $base['bundle']]);
	$db->prepexec("INSERT INTO package_bases (p_id, t_version, b_bundled, b_thash, b_chash) VALUES (?, ?, ?, ?, ?)", [$conf['id'], $base['version'], $base['bundle'], $base['thash'], $base['chash']]);
	$db->commit();

	$log->close();
	$pwd = null;

	return $base;
}

function get_debian_base(array $conf, string $version, string $dep_ver = 'head', string $bundle_ver = ''): array {
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);

	$db = \Db\get_rw();
	$tar = $db->prepexec("SELECT b_thash, b_chash FROM package_bases WHERE p_id = ? AND t_version = ? AND b_bundled = ?", [$conf['id'], $version, $bundle_ver])->fetchAll();
	if (!empty($tar[0]['b_thash']) && file_exists($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['b_thash']}.tar.xz")) {
		return [
			'log' => 'Existing base found',
			'version' => $version,
			'bundle' => $bundle_ver,
			'path_tar' => $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['b_thash']}.tar.xz",
			'path_control' => $_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/tars/{$tar[0]['b_chash']}.tar.xz",
			];
	}

	return make_debian_base($conf, $version, $bundle_ver);
}
