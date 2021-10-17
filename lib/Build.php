<?php
declare(strict_types=1);
namespace Build;

function make_debian_base(array $conf, string $version, string $bundle_ver = '') {
	$pwd = getcwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/base", 0711, true);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/base", 0711, true);

	$bundle = $conf['bundle_deps'] ? '-'.$bundle_ver : '';

	$stamp = date('Ymd-His');
	$log = new \Utils\Log($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/base/{$stamp}.log");
	$log->ln('Version: '.$version);
	$log->ln('Bundle: '.$bundle);

	$log->exec("rm -rf '{$_ENV['WOLFPKG_WORKDIR']}/tmp/{$fl}/{$conf['name']}/{$version}{$bundle}'");
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}/{$version}{$bundle}", 0711, true);
	\E\chdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}/{$version}{$bundle}");

	$db = \Db\get_rw();
	$tar = \Pkg\get_tarball($conf, null, $version);

	$log->exec("cp -av --reflink=auto '{$_ENV['WOLFPKG_WORKDIR']}/packages/{$fl}/{$conf['name']}/tars/{$tar['path']}.tar.xz' './{$conf['name']}_{$version}.tar.xz'");
	$log->exec("tar -Jxf '{$conf['name']}_{$version}.tar.xz'");
	\E\chdir("{$conf['name']}-{$version}");

	// Bundle to avoid version drift
	$cnfs = ['control' => '', 'copyright' => '', 'rules' => ''];
	$rules = file_get_contents("{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian/rules");
	if ($bundle && file_exists('configure.ac') && !preg_match('m@dh_auto_configure|dh_auto_build@', $rules)) {
		$config = '';
		$cnfs['rules'] = $rules;

		$copyright = [];
		foreach (preg_split('~\n\n+~', file_get_contents("{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian/copyright")) as $f) {
			preg_match('@^([^\n]+)\n(.+)$@s', $f, $m);
			$copyright[$m[1]] = $m[2];
		}

		$cnfs['rules'] = preg_replace('s@(\n%:)@s', "\nNUMJOBS = 1\nifneq (,$(filter parallel=%,$(DEB_BUILD_OPTIONS)))\n\tNUMJOBS = $(patsubst parallel=%,%,$(filter parallel=%,$(DEB_BUILD_OPTIONS)))\nendif\n\$1", $cnfs['rules']);
		$cnfs['control'] = \Pkg\read_control("{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian/control");
		preg_match('@(Build-Depends:\s*[^\n]+)@', $cnfs['control'], $bdeps);
		$bdeps = $bdeps[1];
		$ss = ['override_dh_auto_configure:', 'override_dh_auto_build:'];
		$withlang = '';

		$do_bundle = function($dep) use ($bundle_ver, &$log, &$bdeps) {
			$log->ln("Maybe bundle {$dep}");
			preg_match('@\[(.+?)\], \[(.+?)\](?:, \[(.+?)\])?@', $dep, $m);
			[$n, $p, $v] = [$m[1], $m[2], $m[3]];

			$pkg = \Pkg\get($p);
			if (!$pkg) {
				$log->ln("Not bundling {$p} (external)");
				return;
			}
			if ($pkg['disabled']) {
				$log->ln("Not bundling {$p} (disabled)");
				return;
			}
			if (!$pkg['bundle_self']) {
				$log->ln("Not bundling {$p} (says not to)");
				return;
			}
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

			$tar = null;
			if ($bundle_ver === 'exact') {
				$tar = \Pkg\get_tarball($pkg, "v{$v}", $v);
			}
			else if ($bundle_ver === 'released') {
				$tar = \Pkg\get_tarball($pkg, null, 'released');
			}
			else {
				$tar = \Pkg\get_tarball($pkg, null, 'HEAD');
			}
			my ($newrev,$version,$srcdate) = split(/\t/, $gv);
			if (!$newrev) {
				die "Missing revision: $newrev\n";
			}
			print "Bundling $n $p $v $newrev $version $srcdate\n";

			my $cli = "--nobuild '$opts{nobuild}' -p '$pkg['path']' -u '$pkg['url']' -v '$version' --distv '0' -d '$srcdate' --rev $newrev -m 'Apertium Automaton <apertium-packaging\@lists.sourceforge.net>' -e 'Apertium Automaton <apertium-packaging\@lists.sourceforge.net>'";
			`export 'AUTOPKG_PKPATH=$Bin/$pkg['path']' 'AUTOPKG_AUTOPATH=/opt/autopkg/$ENV{AUTOPKG_BUILDTYPE}/$p' && $Bin/make-deb-source.pl $cli >&2`;

			print `cp -av --reflink=auto /opt/autopkg/$ENV{AUTOPKG_BUILDTYPE}/$p/$p-$version '$pkname-$opts{v}/'`;
			my ($bds) = (read_control("$pkname-$opts{v}/$p-$version/debian/control") =~ m@Build-Depends:\s*([^\n]+)@);
			$bdeps .= ", $bds ";

			if ($p =~ m@^giella-(core|common)$@) {
				if ($p eq 'giella-core') {
					$cnfs{'rules'} =~ s@(\n\%:)@\nexport GIELLA_CORE=\$(CURDIR)/$p-$version\n$1@gs;
				}
				elsif ($p eq 'giella-common') {
					$cnfs{'rules'} =~ s@(\n\%:)@\nexport GIELLA_SHARED=\$(CURDIR)/$p-$version\n$1@gs;
				}
				$ss[0] =~ s@(:\n)@$1\tcd \$(CURDIR)/$p-$version && autoreconf -fi && ./configure && \$(MAKE) -j\$(NUMJOBS)\n@gs;
			}
			else {
				$ss[0] .= "\n\tcd \$(CURDIR)/$p-$version && autoreconf -fi && ./configure";
				$ss[1] .= "\n\tcd \$(CURDIR)/$p-$version && \$(MAKE) -j\$(NUMJOBS)";
				if ($p =~ m@^giella-@) {
					# Delete data files that won't be used for this bundled build, but leave the infrastructure for autoreconf and configure
					`cd '$pkname-$opts{v}/$p-$version/' && find devtools/ tools/analysers/ tools/tokenisers/ tools/freq_test/ tools/shellscripts/ tools/grammarcheckers/ tools/spellcheckers/ tools/hyphenators/ test/tools/grammarcheckers/ test/tools/hyphenators/ test/tools/spellcheckers/ test/tools/tokeniser/ -type f | grep -vF Makefile.am | grep -vF .in | xargs -r rm -fv >&2`;

					$ss[0] .= " --with-hfst --without-xfst --enable-alignment --enable-reversed-intersect --enable-apertium --with-backend-format=foma --disable-analysers --disable-generators";
					$bdeps =~ s@\s+divvun-gramcheck,?@ @g;
					$withlang .= " --with-lang$n=\$(CURDIR)/$p-$version/tools/mt/apertium";
				}
				else {
					$withlang .= " --with-lang$n=\$(CURDIR)/$p-$version";
				}
			}

			for my $f (split(/\n\n+/, file_get_contents("$pkname-$opts{v}/$p-$version/debian/copyright"))) {
				my ($a,$b) = ($f =~ m@^([^\n]+)\n(.+)$@s);
				if ($a =~ m@^Format@ || $a =~ m@^Files.*debian/@) {
					next;
				}
				if ($f =~ m@^(Files:.+?)\n(Copyright:.+)$@s) {
					($a,$b) = ($1,$2);
					$a =~ s@(\s)(\S)@$1$p-$version/$2@gs;
				}
				$copyright{$a} = $b;
			}

			`rm -rfv '$pkname-$opts{v}/$p-$version/debian'`;
		};

		$config =~ s/(#|dnl )[^\n]+//sg;
		for my $dep ($config =~ m@AP_CHECK_LING\((.+?)\)@g) {
			$do_bundle($dep);
		}
		if ($bdeps =~ m@(giella-core) \((.+?)\)@) {
			$do_bundle("[0], [$1], [$2]");
		}
		if ($bdeps =~ m@(giella-common) \((.+?)\)@) {
			$do_bundle("[0], [$1], [$2]");
		}

		for my $k (sort(keys(%copyright))) {
			my $v = $copyright{$k};
			if ($k =~ m@^Format@) {
				$cnfs{'copyright'} = "$k\n$v\n\n".$cnfs{'copyright'};
				next;
			}
			$cnfs{'copyright'} .= "$k\n$v\n\n";
		}

		$cnfs{'control'} =~ s@Build-Depends:\s*[^\n]+@$bdeps@;
		$ss[0] .= "\n\tdh_auto_configure --$withlang";
		$ss[1] .= "\n\tdh_auto_build";

		$cnfs{'rules'} .= "\n".join("\n\n", @ss)."\n";
	}
	}

	$log->exec("cp -av --reflink=auto '{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian' './'");
	while (my ($k,$v) = each(%cnfs)) {
		if ($v) {
			file_put_contents("$pkname-$opts{v}/debian/$k", $v);
		}
	}

	\E\chdir($pwd);
}
