<?php
/*
 * Trusted IPs.
 *
 * Staff (moderators and above) can press "Trust IP" on a post. From then on, new posts from that
 * IP on that board skip approval: they are not held for post approval (SCHAN_REQMOD) and their
 * images and links are not held for approval (SCHAN_REQMODMEDIA / SCHAN_REQMODLINKS).
 * "Untrust IP" on any post from the same IP turns it back off.
 *
 * Trust is per board and stored in the database (<posts>_trusted) under a SHA-256 of the same
 * hash the posts table uses for IPs (never the raw IP).
 */
require_once __DIR__ . '/storage.php';

function trustKey($board, $ip) {
	return hash('sha256', hashData($ip));
}

// True when this IP is trusted on the board.
function trustedIPCheck($board, $ip) {
	if ($ip === '' || $ip === null || (function_exists('extensionEnabled') && !extensionEnabled('trust'))) {
		return false;
	}
	$row = storeRow("SELECT 1 AS t FROM `" . storeTable('trusted') . "` WHERE `board` = '" . storeEsc($board) . "' AND `ipkey` = '" . storeEsc(trustKey($board, $ip)) . "' LIMIT 1");
	return $row !== null;
}

// Trusts ($trusted = true) or untrusts an IP on a board. Returns false on failure.
function trustedIPSet($board, $ip, $trusted, $by) {
	if (function_exists('extensionEnabled') && !extensionEnabled('trust')) {
		return false;
	}
	storeEnsure();
	$table = storeTable('trusted');
	$key = storeEsc(trustKey($board, $ip));
	if ($trusted) {
		return (bool)storeQuery("INSERT INTO `" . $table . "` (`board`, `ipkey`, `trusted_by`, `created`) VALUES ('" . storeEsc($board) . "', '" . $key . "', '" . storeEsc((string)$by) . "', " . time() . ") ON DUPLICATE KEY UPDATE `trusted_by` = VALUES(`trusted_by`), `created` = VALUES(`created`)");
	}
	return (bool)storeQuery("DELETE FROM `" . $table . "` WHERE `board` = '" . storeEsc($board) . "' AND `ipkey` = '" . $key . "'");
}
