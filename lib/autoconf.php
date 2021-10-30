<?php
declare(strict_types=1);
setlocale(LC_ALL, 'C.UTF-8');
date_default_timezone_set('UTC');

if (PHP_SAPI === 'cli' || empty($_SERVER['REMOTE_ADDR'])) {
	proc_nice(20);
}

$env = [];
$env['WOLFPKG_ROOT'] = dirname(__DIR__);
$env['WOLFPKG_WORKDIR'] = realpath($env['WOLFPKG_ROOT'].'/../data');
$env['WOLFPKG_URL'] = 'https://'.($_SERVER['HTTP_HOST'] ?? 'pkg.pjj.cc');
$env['WOLFPKG_HOST_USER'] = 'wolfpkg';
$env['WOLFPKG_HOST_GROUP'] = $env['WOLFPKG_HOST_USER'];
$env['WOLFPKG_HOST_UID'] = posix_getpwnam($env['WOLFPKG_HOST_USER'])['uid'];
$env['WOLFPKG_HOST_GID'] = posix_getgrnam($env['WOLFPKG_HOST_USER'])['gid'];
$env['WOLFPKG_GUEST_UID'] = 1848;
$env['WOLFPKG_GUEST_GID'] = $env['WOLFPKG_GUEST_UID'];
require_once __DIR__.'/../config.php';

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/E.php';
require_once __DIR__.'/Db.php';
require_once __DIR__.'/Utils.php';
require_once __DIR__.'/Pkg.php';
require_once __DIR__.'/Build.php';

clearstatcache();
$_ENV = \getenv();
\Utils\putenv('TZ', 'UTC');
\Utils\putenv('LC_ALL', 'C.UTF-8');
// GNUPGHOME

foreach ($env as $k => $v) {
	if (!\array_key_exists($k, $_ENV)) {
		\Utils\putenv($k, $v);
	}
}
