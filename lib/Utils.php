<?php
declare(strict_types=1);
namespace Utils;

function putenv(string $k, $v): void {
	$v = strval($v);
	\putenv("{$k}={$v}");
	$_ENV[$k] = $v;
}

function configure_string($d): string {
	foreach ($_ENV as $k => $v) {
		$d = str_replace("{ENV:{$k}}", $v, $d);
	}
	return $d;
}

function configure_file($fn, $fo): string {
	$mod = \E\fileperms($fn) & 0777;
	$d = \E\file_get_contents($fn);
	$d = configure_string($d);
	\E\file_put_contents($fo, $d);
	\E\chmod($fn, $mod);
	return $fo;
}

function configure_file_tmp($fn): string {
	$d = \E\file_get_contents($fn);
	$d = configure_string($d);
	$fo = \sys_get_temp_dir().'/'.sha256_b64x($d);
	\E\file_put_contents($fo, $d);
	return $fo;
}

function configure_file_in($fn): void {
	configure_file($fn.'.in', $fn);
}

function b64x(string $str): string {
	$str = \base64_encode($str);
	$str = trim($str, '=');
	// Make encoding safe for use in URLs and filenames
	$str = strtr($str, '/+', '_-');
	// Rotate potentially bad shell characters to the back
	while ($str[0] === '_' || $str[0] === '-') {
		$str = substr($str, 1).$str[0];
	}
	return $str;
}

function sha256(string $str, bool $bin = true): string {
	return hash('sha256', $str, $bin);
}

function sha256_file($file, bool $bin = true): string {
	if (is_array($file)) {
		$h = hash_init('sha256');
		foreach ($file as $f) {
			if (!file_exists($f)) {
				throw new \RuntimeException("No such file '{$f}'");
			}
			hash_update_file($h, $f);
		}
		return hash_final($h, $bin);
	}
	if (!file_exists($file)) {
		throw new \RuntimeException("No such file or folder '{$file}'");
	}
	if (is_dir($file)) {
		$hash = substr(exec("find ".escapeshellarg($file)." -type f | LC_ALL=C sort | xargs -r cat | sha256sum"), 0, 64);
		if ($bin) {
			$hash = hex2bin($hash);
		}
		return $hash;
	}
	return hash_file('sha256', $file, $bin);
}

function sha256_b64x(string $str): string {
	return b64x(sha256($str, true));
}

function sha256_file_b64x($file): string {
	return b64x(sha256_file($file, true));
}

function int(&$v): void {
	$v = intval($v);
}

function json5_encode($data, int $opts = 0): string {
	$data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $opts);
	$data = preg_replace('~"([a-zA-Z0-9_]+)":~', '\1:', $data);
	$data = str_replace('\\"', "\u{e001}", $data);
	$data = str_replace("'", '\\\'', $data);
	$data = str_replace('"', "'", $data);
	$data = str_replace("\u{e001}", '"', $data);
	$data = str_replace("'\n", "',\n", $data);
	$data = str_replace("]\n", "],\n", $data);
	$data = str_replace("}\n", "},\n", $data);
	$data = str_replace('    ', "\t", $data);
	$data = str_replace("\t}", "\t\t}", $data);
	$data = str_replace("\t]", "\t\t]", $data);
	return $data;
}

class KeepCwd {
	private $cwd = '';

	public function __construct() {
		$this->cwd = getcwd();
	}

	public function __destruct() {
		$this->chdir();
	}

	public function chdir(): void {
		\E\chdir($this->cwd);
	}
}

class Log {
	private $fn = null;
	private $f = null;

	public function __construct(string $fn = 'php://stdout') {
		$this->fn = $fn;
		$this->f = \E\fopen($fn, 'wb');
	}

	public function fn(): string {
		return $this->fn;
	}

	public function ln(string $s): void {
		fwrite($this->f, $s."\n");
		fflush($this->f);
	}

	public function exec(string $c, bool $ign = false): string {
		if (strpos($c, '2>') === false && strpos($c, '&>') === false) {
			$c .= ' 2>&1';
		}
		fwrite($this->f, "Command: {$c}\n");
		fflush($this->f);
		$t = [];
		$e = 0;
		\exec($c, $t, $e);
		if (!$ign && $e) {
			throw new \RuntimeException("{$c} => {$e}", $e);
		}
		$t = trim(implode("\n", $t));
		if ($t) {
			fwrite($this->f, $t."\n");
		}
		fflush($this->f);
		return $t;
	}

	public function exec_null(string $c): string {
		return $this->exec($c, true);
	}

	public function flush(): void {
		fflush($this->f);
	}

	public function close(): void {
		if ($this->f) {
			fclose($this->f);
		}
		$this->f = null;
	}

	public function unlink(): void {
		\unlink($this->fn);
	}
}

function exec(string $c, bool $ign = false): string {
	$t = [];
	$e = 0;
	if (strpos($c, '2>') === false && strpos($c, '&>') === false) {
		$c .= ' 2>&1';
	}
	\exec($c, $t, $e);
	if (!$ign && $e) {
		throw new \RuntimeException($c, $e);
	}
	$t = trim(implode("\n", $t));
	return $t;
}

function exec_null(string $c): string {
	return exec($c, true);
}

function ppassthru(string $c): void {
	if (strpos($c, '2>') === false && strpos($c, '&>') === false) {
		$c .= ' 2>&1';
	}
	echo "Command: {$c}\n";
	$p = \E\popen($c, 'rb');
	fpassthru($p);
	pclose($p);
}

function split(string $delim, string $str): array {
	if (empty($str)) {
		return [];
	}
	return \explode($delim, $str);
}

function head(string $fn, int $n = 1): string {
	$f = \E\fopen($fn, 'rb');
	$ln = '';
	for ($i = 0 ; $i<$n ; ++$i) {
		$ln .= fgets($f);
	}
	fclose($f);
	return $ln;
}
