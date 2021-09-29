<?php
namespace Db;

function get($read_only = false) {
	$which = "-wolfpkg-db-handle-{$read_only}";
	if (!empty($GLOBALS[$which])) {
		return $GLOBALS[$which];
	}

	$db_file = $_ENV['WOLFPKG_WORKDIR'].'/wolfpkg.sqlite';
	$existed = file_exists($db_file) && (filesize($db_file) > 4096);

	$opts = [];
	if ($read_only) {
		$opts = [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];
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
		$db->exec(file_get_contents($_ENV['WOLFPKG_ROOT'].'/lib/schema.sql'));
		/*
		$db->exec("CREATE TABLE sources (
			p_id INTEGER NOT NULL,
			-- Needs cadence/whatis
			s_rev TEXT NOT NULL,
			s_mtime INTEGER NOT NULL,
			-- s_version TEXT NOT NULL,
			s_thash TEXT NOT NULL,
			s_stamp INTEGER NOT NULL,
			PRIMARY KEY(p_id, s_rev),
			FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE
			) WITHOUT ROWID");
		$db->exec("CREATE TABLE package_targets (
			p_id INTEGER NOT NULL,
			t_id INTEGER NOT NULL, -- maybe not integer
			s_thash TEXT NOT NULL,
			PRIMARY KEY(p_id, t_id, s_thash),
			FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE,
			FOREIGN KEY(s_thash) REFERENCES sources(s_thash) ON UPDATE CASCADE ON DELETE RESTRICT
			) WITHOUT ROWID");
		$db->exec("CREATE TABLE published (
			p_id INTEGER NOT NULL,
			k_kind INTEGER NOT NULL,
			s_thash TEXT NOT NULL,
			s_version TEXT NOT NULL,
			b_binaries TEXT NOT NULL,
			PRIMARY KEY(p_id, k_kind, s_thash),
			FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE,
			FOREIGN KEY(s_thash) REFERENCES sources(s_thash) ON UPDATE CASCADE ON DELETE RESTRICT
			) WITHOUT ROWID");
		//*/
		chmod($db_file, 0664);
	}

	$GLOBALS[$which] = $db;
	return $db;
}

function get_rw() {
	return get(false);
}

function get_ro() {
	return get(true);
}
