<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/Utils.php';
require_once __DIR__.'/Pkg.php';
require_once __DIR__.'/Db.php';

clearstatcache();
$_ENV = \getenv();
\Utils\putenv('TZ', 'UTC');

foreach ($env as $k => $v) {
	if (!\array_key_exists($k, $_ENV)) {
		\Utils\putenv($k, $v);
	}
}