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
	PRIMARY KEY(p_id),
	FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE
	) WITHOUT ROWID;

CREATE TABLE package_tars (
	p_id INTEGER NOT NULL,
	r_rev TEXT NOT NULL, -- commit hash or revision number
	t_rev TEXT NOT NULL, -- local commit hash or revision number
	t_stamp INTEGER NOT NULL,
	t_count INTEGER NOT NULL, -- local monotonically increasing revision number
	t_version TEXT NOT NULL,
	t_thash TEXT NOT NULL, -- sha256 hash of the sorted tarball
	t_version_dots TEXT NOT NULL, -- version with . instead of +~
	t_thash_dots TEXT NOT NULL, -- sha256 hash of the sorted tarball
	PRIMARY KEY(p_id, r_rev, t_version),
	FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE
	) WITHOUT ROWID;

CREATE TABLE package_bases (
	p_id INTEGER NOT NULL,
	t_version TEXT NOT NULL,
	b_bundled TEXT NOT NULL,
	b_thash TEXT NOT NULL, -- sha256 hash of the sorted source tarball
	b_chash TEXT NOT NULL, -- sha256 hash of the sorted control tarball
	PRIMARY KEY(p_id, t_version, b_bundled),
	FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE
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

-- Distros and other target platforms
CREATE TABLE targets (
	k_id INTEGER NOT NULL,
	tg_id INTEGER NOT NULL,
	tg_distro TEXT NOT NULL,
	tg_version TEXT NOT NULL DEFAULT '',
	tg_archs TEXT NOT NULL DEFAULT 'amd64,arm64',
	tg_extra TEXT NOT NULL DEFAULT '',
	PRIMARY KEY(tg_id AUTOINCREMENT),
	FOREIGN KEY(k_id) REFERENCES kinds(k_id) ON UPDATE CASCADE ON DELETE CASCADE,
	UNIQUE(tg_distro, tg_version)
	);

-- Published packages per target
CREATE TABLE target_packages (
	tg_id INTEGER NOT NULL,
	tg_arch TEXT NOT NULL,
	p_id INTEGER NOT NULL,
	t_version TEXT NOT NULL,
	tp_repo TEXT NOT NULL, -- nightly, release
	tp_stamp INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
	tp_distv INTEGER NOT NULL DEFAULT 1,
	tp_binaries TEXT NOT NULL,
	PRIMARY KEY(tg_id, tg_arch, p_id, t_version),
	FOREIGN KEY(tg_id) REFERENCES targets(tg_id) ON UPDATE CASCADE ON DELETE CASCADE,
	FOREIGN KEY(p_id) REFERENCES packages(p_id) ON UPDATE CASCADE ON DELETE CASCADE
	) WITHOUT ROWID;

-- Builds
CREATE TABLE builds (
	b_id INTEGER NOT NULL,
	b_task TEXT NOT NULL,
	b_wait_for INTEGER,
	b_priority INTEGER NOT NULL DEFAULT 127,
	b_created INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
	b_status TEXT NOT NULL DEFAULT 'ready', -- waiting, ready, started, success, fail
	p_id INTEGER,
	tg_id INTEGER,
	tg_arch TEXT,
	b_builder TEXT,
	b_started INTEGER,
	b_stopped INTEGER,
	b_log TEXT,
	PRIMARY KEY(b_id AUTOINCREMENT)
	);

BEGIN;
INSERT INTO kinds VALUES (1, 'debian');
INSERT INTO kinds VALUES (2, 'rpm');
INSERT INTO kinds VALUES (3, 'macos');
INSERT INTO kinds VALUES (4, 'mingw');
INSERT INTO kinds VALUES (5, 'scan-build');
INSERT INTO kinds VALUES (6, 'conda');
INSERT INTO kinds VALUES (7, 'vcpkg');
COMMIT;

BEGIN;
INSERT INTO targets (k_id, tg_distro, tg_version, tg_archs, tg_extra) VALUES (1, 'debian', 'sid', 'i386,amd64,arm64', '{dh:13}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'debian', 'stretch', '{dh:10}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'debian', 'buster', '{dh:12}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'debian', 'bullseye', '{dh:12}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'debian', 'bookworm', '{dh:13}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'ubuntu', 'bionic', '{dh:11}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'ubuntu', 'focal', '{dh:12}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'ubuntu', 'hirsute', '{dh:13}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'ubuntu', 'impish', '{dh:13}');
INSERT INTO targets (k_id, tg_distro, tg_version, tg_extra) VALUES (1, 'ubuntu', 'jammy', '{dh:13}');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (2, 'centos', '7');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (2, 'centos', '8');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (2, 'rocky', '8');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (2, 'fedora', '33');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (2, 'fedora', '34');
INSERT INTO targets (k_id, tg_distro) VALUES (3, 'macos');
INSERT INTO targets (k_id, tg_distro, tg_archs) VALUES (4, 'mingw', 'amd64');
INSERT INTO targets (k_id, tg_distro, tg_archs) VALUES (5, 'scan-build', 'amd64');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (6, 'conda', 'linux-py38');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (6, 'conda', 'linux-py39');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (6, 'conda', 'osx-py38');
INSERT INTO targets (k_id, tg_distro, tg_version) VALUES (6, 'conda', 'osx-py39');
INSERT INTO targets (k_id, tg_distro, tg_archs) VALUES (7, 'vcpkg', 'amd64');
COMMIT;
