<?php
declare(strict_types=1);
namespace Db;

function get(bool $read_only = false): object {
	$which = "-wolfpkg-db-handle-{$read_only}";
	if (!empty($GLOBALS[$which])) {
		return $GLOBALS[$which];
	}

	$db_file = $_ENV['WOLFPKG_WORKDIR'].'/wolfpkg.sqlite';
	$existed = file_exists($db_file) && (filesize($db_file) > 4096);

	$opts = [];
	if ($read_only) {
		$opts = [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY];
	}
	$db = new \TDC\PDO\SQLite($db_file, $opts);

	if (!$read_only) {
		$db->exec("PRAGMA auto_vacuum = INCREMENTAL");
	}
	$db->exec("PRAGMA case_sensitive_like = ON");
	$db->exec("PRAGMA foreign_keys = ON");
	$db->exec("PRAGMA journal_mode = WAL");
	$db->exec("PRAGMA locking_mode = EXCLUSIVE");
	$db->exec("PRAGMA synchronous = NORMAL");
	$db->exec("PRAGMA threads = 4");
	$db->exec("PRAGMA trusted_schema = OFF");

	if (!$existed) {
		printf("Creating SQLite database %s\n", $db_file);
		$db->exec(\E\file_get_contents($_ENV['WOLFPKG_ROOT'].'/lib/schema.sql'));
		chmod($db_file, 0664);
	}

	$GLOBALS[$which] = $db;
	return $db;
}

function get_rw(): object {
	return get(false);
}

function get_ro(): object {
	return get(true);
}
