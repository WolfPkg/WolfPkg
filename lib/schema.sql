PRAGMA auto_vacuum = INCREMENTAL;
PRAGMA case_sensitive_like = ON;
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA locking_mode = EXCLUSIVE;
PRAGMA synchronous = NORMAL;
PRAGMA threads = 4;
PRAGMA trusted_schema = OFF;

CREATE TABLE packages (
	p_id INTEGER NOT NULL,
	p_name TEXT NOT NULL,
	p_path TEXT NOT NULL, -- local path, relative to WolfPkg's root
	p_url TEXT NOT NULL, -- resolved URL
	p_mtime INTEGER NOT NULL, -- modification time of pkg.json5
	p_chash TEXT NOT NULL, -- sha256 hash of pkg.json5
	PRIMARY KEY(p_id AUTOINCREMENT),
	UNIQUE(p_name)
	);

CREATE TABLE package_repo (
	p_id INTEGER NOT NULL,
	r_rev TEXT NOT NULL, -- commit hash or revision number
	r_stamp INTEGER NOT NULL,
	r_count INTEGER NOT NULL, -- monotonically increasing revision number; for svn this == r_rev, but matters for git
	r_thash TEXT NOT NULL, -- combined hash of p_chash and other r_* fields
	PRIMARY KEY(p_id, r_rev),
	FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE
	) WITHOUT ROWID;

CREATE TABLE package_tar (
	p_id INTEGER NOT NULL,
	r_rev TEXT NOT NULL, -- commit hash or revision number
	t_rev TEXT NOT NULL, -- local commit hash or revision number
	t_stamp INTEGER NOT NULL,
	t_count INTEGER NOT NULL, -- local monotonically increasing revision number
	t_thash TEXT NOT NULL, -- combined hash of r_thash and other t_* fields
	t_version TEXT NOT NULL DEFAULT '',
	PRIMARY KEY(p_id, r_rev),
	FOREIGN KEY(p_id, r_rev) REFERENCES package_repo(p_id, r_rev) ON UPDATE CASCADE ON DELETE CASCADE
	) WITHOUT ROWID;

-- The type of packaging recipe, such as Debian vs. RPM.
CREATE TABLE kinds (
	k_id INTEGER NOT NULL,
	k_name TEXT NOT NULL,
	PRIMARY KEY(k_id AUTOINCREMENT),
	UNIQUE(k_name)
	);

-- Last seen values for a package's kinds
CREATE TABLE package_kind (
	p_id INTEGER NOT NULL,
	k_id INTEGER NOT NULL,
	pk_mtime INTEGER NOT NULL, -- latest modification time of any file in that recipe folder
	pk_chash TEXT NOT NULL, -- sha256 hash of all files in the recipe folder
	pk_thash TEXT NOT NULL, -- combined hash of pk_chash and p_chash
	PRIMARY KEY(p_id, k_id),
	FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE,
	FOREIGN KEY(k_id) REFERENCES kinds(k_id) ON UPDATE CASCADE ON DELETE CASCADE
	) WITHOUT ROWID;

BEGIN;
INSERT INTO kinds VALUES (1, 'debian');
INSERT INTO kinds VALUES (2, 'rpm');
INSERT INTO kinds VALUES (3, 'macos');
INSERT INTO kinds VALUES (4, 'mingw');
INSERT INTO kinds VALUES (5, 'scan-build');
INSERT INTO kinds VALUES (6, 'conda');
INSERT INTO kinds VALUES (7, 'vcpkg');
COMMIT;
