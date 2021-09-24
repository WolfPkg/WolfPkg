<?php
namespace Db;

function get($read_only = false) {
	$db_file = $_ENV['WOLFPKG_WORKDIR'].'/wolfpkg.sqlite';
	$existed = file_exists($db_file);

	$opts = [];
	if ($read_only) {
		$opts = [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];
	}
	$db = new \TDC\PDO\SQLite($db_file, $opts);

	if (!$read_only) {
		$db->exec("PRAGMA auto_vacuum = NONE");
	}
	$db->exec("PRAGMA case_sensitive_like = ON");
	$db->exec("PRAGMA foreign_keys = ON");
	$db->exec("PRAGMA journal_mode = MEMORY");
	$db->exec("PRAGMA locking_mode = EXCLUSIVE");
	$db->exec("PRAGMA synchronous = OFF");
	$db->exec("PRAGMA threads = 4");
	$db->exec("PRAGMA trusted_schema = OFF");

	if (!$existed) {
		printf("Creating SQLite database %s\n", $db_file);
		$db->exec("CREATE TABLE packages (
			p_id INTEGER NOT NULL,
			p_name TEXT NOT NULL UNIQUE,
			p_path TEXT NOT NULL,
			p_mtime INTEGER NOT NULL,
			p_chash TEXT NOT NULL,
			PRIMARY KEY(p_id AUTOINCREMENT)
			)");
		$db->exec("CREATE TABLE sources (
			p_id INTEGER NOT NULL,
			s_id TEXT NOT NULL,
			s_stamp INTEGER NOT NULL,
			s_version TEXT NOT NULL,
			PRIMARY KEY(p_id, s_id),
			FOREIGN KEY(p_id) REFERENCES packages(p_id) ON DELETE CASCADE
			) WITHOUT ROWID");
		chmod($db_file, 0600);
	}

	return $db;
}
