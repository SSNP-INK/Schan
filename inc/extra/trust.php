<?php
/*
 * Trusted IPs.
 *
 * Staff (moderators and above) can press "Trust IP" on a post. From then on, new posts from that
 * IP on that board skip approval: they are not held for post approval (SCHAN_REQMOD) and their
 * images and links are not held for approval (SCHAN_REQMODMEDIA / SCHAN_REQMODLINKS).
 * "Untrust IP" on any post from the same IP turns it back off.
 *
 * Trust is per board and is stored by the same hash the posts table uses for IPs (never the raw IP)
 * in .trustedips.php in the install directory. The file starts with a PHP exit line so it cannot be
 * downloaded.
 */

define('SCHAN_TRUST_HEADER', "<?php http_response_code(404); exit; ?>\n");

function trustStorePath() {
	return dirname(__DIR__) . '/.trustedips.php';
}

function trustStoreParse($raw) {
	if (!is_string($raw) || $raw === '') {
		return array();
	}
	$nl = strpos($raw, "\n");
	$data = json_decode($nl === false ? '' : substr($raw, $nl + 1), true);
	return is_array($data) ? $data : array();
}

function trustStoreLoad($force = false) {
	if (!$force && isset($GLOBALS['schan_trusted_ips'])) {
		return $GLOBALS['schan_trusted_ips'];
	}
	$data = array();
	$fh = @fopen(trustStorePath(), 'r');
	if ($fh) {
		flock($fh, LOCK_SH);
		$data = trustStoreParse(stream_get_contents($fh));
		flock($fh, LOCK_UN);
		fclose($fh);
	}
	$GLOBALS['schan_trusted_ips'] = $data;
	return $data;
}

function trustKey($board, $ip) {
	return $board . '|' . hashData($ip);
}

// True when this IP (raw or already hashed) is trusted on the board.
function trustedIPCheck($board, $ip) {
	if ($ip === '' || $ip === null || (function_exists('extensionEnabled') && !extensionEnabled('trust'))) {
		return false;
	}
	$data = trustStoreLoad();
	if (empty($data)) {
		return false;
	}
	return isset($data[trustKey($board, $ip)]);
}

// Trusts ($trusted = true) or untrusts an IP on a board. Returns false if the file cannot be written.
function trustedIPSet($board, $ip, $trusted, $by) {
	if (function_exists('extensionEnabled') && !extensionEnabled('trust')) {
		return false;
	}
	$key = trustKey($board, $ip);
	$fh = @fopen(trustStorePath(), 'c+');
	if (!$fh) {
		return false;
	}
	if (!flock($fh, LOCK_EX)) {
		fclose($fh);
		return false;
	}
	$data = trustStoreParse(stream_get_contents($fh));
	if ($trusted) {
		$data[$key] = array('by' => (string)$by, 'time' => time());
	} else {
		unset($data[$key]);
	}
	$ok = ftruncate($fh, 0) && rewind($fh) && (fwrite($fh, SCHAN_TRUST_HEADER . json_encode($data)) !== false);
	fflush($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	trustStoreLoad(true);
	return $ok;
}
