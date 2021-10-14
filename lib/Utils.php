<?php
declare(strict_types=1);
namespace Utils;

function putenv(string $k, string $v): void {
	\putenv("{$k}={$v}");
	$_ENV[$k] = $v;
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
		throw new \RuntimeException("No such file '{$file}'");
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

class Log {
	private $fn = null;
	private $f = null;

	public function __construct(string $fn) {
		$this->fn = $fn;
		$this->f = \E\fopen($fn, 'wb');
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
			throw new \RuntimeException($c, $e);
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

function split(string $delim, string $str): array {
	if (empty($str)) {
		return [];
	}
	return \explode($delim, $str);
}
