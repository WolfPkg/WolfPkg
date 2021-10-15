<?php
declare(strict_types=1);
namespace Build;

function make_debian_base(array $conf, string $version, bool $bundle = false) {
	$pwd = getcwd();
	$fl = substr($conf['name'], 0, 1).substr($conf['name'], -1);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/base", 0711, true);
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/base", 0711, true);

	$bundle = $bundle ? 'yes' : 'no';

	$stamp = date('Ymd-His');
	$log = new \Utils\Log($_ENV['WOLFPKG_WORKDIR']."/packages/{$fl}/{$conf['name']}/logs/base/{$stamp}.log");
	$log->ln('Version: '.$version);
	$log->ln('Bundle: '.$bundle);

	$log->exec("rm -rf '{$_ENV['WOLFPKG_WORKDIR']}/tmp/{$fl}/{$conf['name']}/{$version}-{$bundle}'");
	@mkdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}/{$version}-{$bundle}", 0711, true);
	\E\chdir($_ENV['WOLFPKG_WORKDIR']."/tmp/{$fl}/{$conf['name']}/{$version}-{$bundle}");

	$db = \Db\get_rw();
	$tar = $db->prepexec("SELECT t_thash FROM package_tars WHERE p_id = ? AND t_version = ?", [$conf['id'], $version])->fetchAll();
	if (empty($tar) || empty($tar[0])) {
		throw new \RuntimeException("No tar for {$conf['name']} @ {$version}");
	}
	$tar = $tar[0]['t_thash'];

	$log->exec("cp -av --reflink=auto '{$_ENV['WOLFPKG_WORKDIR']}/packages/{$fl}/{$conf['name']}/tars/{$tar}.tar.xz' './{$conf['name']}_{$version}.tar.xz'");
	$log->exec("tar -Jxf '{$conf['name']}_{$version}.tar.xz'");
	\E\chdir("{$conf['name']}-{$version}");

	$log->exec("cp -av --reflink=auto '{$_ENV['WOLFPKG_ROOT']}/{$conf['path']}/debian' './'");

	\E\chdir($pwd);
}
