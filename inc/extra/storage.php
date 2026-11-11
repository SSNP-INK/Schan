<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * Database-backed storage for everything that used to live in JSON / PHP data files:
 *
 *   .globalmessage.json  -> <posts>_settings   (name = 'globalmessage')
 *   pending-data.php     -> <posts>_pending
 *   spoiler-data.php     -> <posts>_spoilers
 *   .trustedips.php      -> <posts>_trusted
 *   .postmedia.php       -> <posts>_media
 *   <board>/polls/*.php  -> <posts>_polls + <posts>_poll_votes
 *   .boardflags.json     -> <posts>_settings   (name = 'boardflags')
 *   .boardfeatures.php   -> <posts>_settings   (name = 'boardfeatures')
 *   .extensions.php      -> <posts>_settings   (name = 'extensions')
 *   .home.php            -> <posts>_settings   (name = 'home')
 *   .pages.php           -> <posts>_settings   (name = 'pages')
 *   news_cache.json      -> <posts>_settings   (name = 'news_cache')
 *   flood.dat.php        -> <posts>_settings   (name = 'flood', guarded by a MySQL named lock)
 *   .postedits.php       -> <posts>_postedits
 *   .watching.php        -> <posts>_watching
 *   .ipmap.php           -> <posts>_ipmap
 *   .appeals.php         -> <posts>_appeals
 *
 * Tables are created on first use (CREATE TABLE IF NOT EXISTS), named after SCHAN_DBPOSTS.
 * The first time they are created, any old data files that still exist are imported once and
 * renamed to <name>.imported. Works in both mysqli and the legacy mysql mode.
 */

function storeTable($suffix) {
	return SCHAN_DBPOSTS . '_' . $suffix;
}

// the extras only have a database backend for the MySQL modes; other modes fail soft (nothing is stored)
function storeSupported() {
	return SCHAN_DBMODE == 'mysqli' || SCHAN_DBMODE == 'mysql';
}

function storeQuery($sql) {
	global $link;
	if (!storeSupported()) {
		return false;
	}
	if (SCHAN_DBMODE == 'mysqli') {
		return mysqli_query($link, $sql);
	}
	return mysql_query($sql);
}

function storeEsc($s) {
	global $link;
	if (!storeSupported()) {
		return addslashes((string)$s);
	}
	if (SCHAN_DBMODE == 'mysqli') {
		return mysqli_real_escape_string($link, (string)$s);
	}
	return mysql_real_escape_string((string)$s);
}

function storeRows($sql) {
	storeEnsure();
	$rows = array();
	$result = storeQuery($sql);
	if ($result) {
		if (SCHAN_DBMODE == 'mysqli') {
			while ($row = mysqli_fetch_assoc($result)) {
				$rows[] = $row;
			}
		} else {
			while ($row = mysql_fetch_assoc($result)) {
				$rows[] = $row;
			}
		}
	}
	return $rows;
}

function storeRow($sql) {
	$rows = storeRows($sql);
	return empty($rows) ? null : $rows[0];
}

// runs a write query, returns the number of affected rows (0 on failure)
function storeExec($sql) {
	global $link;
	storeEnsure();
	$ok = storeQuery($sql);
	if (!$ok) {
		return 0;
	}
	return (SCHAN_DBMODE == 'mysqli') ? mysqli_affected_rows($link) : mysql_affected_rows();
}

function storeEnsure() {
	static $done = false;
	if ($done) {
		return;
	}
	$done = true;
	if (!storeSupported()) {
		return;
	}
	$opt = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
	$ascii = ' CHARACTER SET ascii COLLATE ascii_bin';

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('settings') . "` (
		`name` VARCHAR(64) NOT NULL,
		`value` MEDIUMTEXT NOT NULL,
		PRIMARY KEY (`name`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('pending') . "` (
		`board` VARCHAR(64) NOT NULL,
		`post_id` INT UNSIGNED NOT NULL,
		`media` TINYINT(1) NOT NULL DEFAULT 0,
		`links` TINYINT(1) NOT NULL DEFAULT 0,
		`created` INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (`board`, `post_id`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('spoilers') . "` (
		`board` VARCHAR(64) NOT NULL,
		`post_id` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`board`, `post_id`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('trusted') . "` (
		`board` VARCHAR(64) NOT NULL,
		`ipkey` CHAR(64)" . $ascii . " NOT NULL,
		`trusted_by` VARCHAR(255) NOT NULL DEFAULT '',
		`created` INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (`board`, `ipkey`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('media') . "` (
		`board` VARCHAR(64) NOT NULL,
		`post_id` INT UNSIGNED NOT NULL,
		`position` TINYINT UNSIGNED NOT NULL,
		`file` VARCHAR(255) NOT NULL DEFAULT '',
		`orig` VARCHAR(255) NOT NULL DEFAULT '',
		`file_hex` VARCHAR(64) NOT NULL DEFAULT '',
		`size_text` VARCHAR(64) NOT NULL DEFAULT '',
		`w` INT NOT NULL DEFAULT 0,
		`h` INT NOT NULL DEFAULT 0,
		`thumb` VARCHAR(255) NOT NULL DEFAULT '',
		`tw` INT NOT NULL DEFAULT 0,
		`th` INT NOT NULL DEFAULT 0,
		`spoiler` TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (`board`, `post_id`, `position`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('polls') . "` (
		`board` VARCHAR(64) NOT NULL,
		`post_id` INT UNSIGNED NOT NULL,
		`options` MEDIUMTEXT NOT NULL,
		`created` INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (`board`, `post_id`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('poll_votes') . "` (
		`board` VARCHAR(64) NOT NULL,
		`post_id` INT UNSIGNED NOT NULL,
		`voter` CHAR(40)" . $ascii . " NOT NULL,
		`opt` TINYINT UNSIGNED NOT NULL,
		PRIMARY KEY (`board`, `post_id`, `voter`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('postedits') . "` (
		`board` VARCHAR(64) NOT NULL,
		`post_id` INT UNSIGNED NOT NULL,
		`name` TEXT NULL,
		`subject` TEXT NULL,
		`deleted` TINYINT(1) NULL,
		PRIMARY KEY (`board`, `post_id`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('watching') . "` (
		`board` VARCHAR(64) NOT NULL,
		`thread` INT UNSIGNED NOT NULL,
		`viewer` VARCHAR(64)" . $ascii . " NOT NULL,
		`seen` INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (`board`, `thread`, `viewer`),
		KEY `seen` (`seen`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('ipmap') . "` (
		`hash` VARCHAR(128)" . $ascii . " NOT NULL,
		`ip` VARCHAR(64) NOT NULL,
		PRIMARY KEY (`hash`)
	)" . $opt);

	storeQuery("CREATE TABLE IF NOT EXISTS `" . storeTable('appeals') . "` (
		`ban_id` INT UNSIGNED NOT NULL,
		`ts` INT UNSIGNED NOT NULL DEFAULT 0,
		`created` INT UNSIGNED NOT NULL DEFAULT 0,
		`text` TEXT NOT NULL,
		PRIMARY KEY (`ban_id`)
	)" . $opt);

	storeImportLegacy();
	storeImportFlatFiles();
}

// settings (name => text)

function storeSettingGet($name, $default = '') {
	$row = storeRow("SELECT `value` FROM `" . storeTable('settings') . "` WHERE `name` = '" . storeEsc($name) . "' LIMIT 1");
	return $row === null ? $default : $row['value'];
}

function storeSettingSet($name, $value) {
	storeEnsure();
	return (bool)storeQuery("INSERT INTO `" . storeTable('settings') . "` (`name`, `value`) VALUES ('" . storeEsc($name) . "', '" . storeEsc($value) . "') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
}

function storeSettingDelete($name) {
	storeEnsure();
	return (bool)storeQuery("DELETE FROM `" . storeTable('settings') . "` WHERE `name` = '" . storeEsc($name) . "'");
}

// JSON blobs (stored in the settings table)

function storeJsonGet($name, $default = array()) {
	$raw = storeSettingGet($name, null);
	if (!is_string($raw) || $raw === '') {
		return $default;
	}
	$data = json_decode($raw, true);
	return is_array($data) ? $data : $default;
}

function storeJsonSet($name, $data, $flags = 0) {
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}
	$json = json_encode($data, $flags);
	if (!is_string($json)) {
		return false;
	}
	return storeSettingSet($name, $json);
}

// named lock (replaces flock() on the old data files). returns true when the lock is held
function storeLockName($name) {
	return 'schan_' . md5(SCHAN_DBPOSTS . '|' . $name);
}

function storeLock($name, $timeout = 5) {
	$row = storeRow("SELECT GET_LOCK('" . storeEsc(storeLockName($name)) . "', " . intval($timeout) . ") AS l");
	return $row !== null && isset($row['l']) && (int)$row['l'] === 1;
}

function storeUnlock($name) {
	storeRows("SELECT RELEASE_LOCK('" . storeEsc(storeLockName($name)) . "') AS l");
}

// one-time import of the old data files

function storeLegacyDecode($path, $skipFirstLine = true) {
	$raw = @file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		return array();
	}
	if ($skipFirstLine) {
		$nl = strpos($raw, "\n");
		if ($nl === false) {
			return array();
		}
		$raw = substr($raw, $nl + 1);
	}
	$data = json_decode($raw, true);
	return is_array($data) ? $data : array();
}

function storeLegacyDone($path) {
	@rename($path, $path . '.imported');
}

// boards that have a post with this id (optionally only if it has a file)
function storeOwnerBoards($id, $needFile) {
	$prev = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$owners = array();
	foreach (allBoards() as $b) {
		if (defined('SCHAN_OVERBOARD') && $b['slug'] === SCHAN_OVERBOARD) {
			continue;
		}
		$GLOBALS['schan_forced_board'] = $b['slug'];
		if (function_exists('clearPostCache')) {
			clearPostCache();
		}
		$p = postByID($id);
		if (!empty($p) && (!$needFile || (isset($p['file']) && $p['file'] != ''))) {
			$owners[] = $b['slug'];
		}
	}
	$GLOBALS['schan_forced_board'] = $prev;
	if (function_exists('clearPostCache')) {
		clearPostCache();
	}
	return $owners;
}

function storeImportLegacy() {
	if (storeSettingGet('legacy_imported') === '1') {
		return;
	}
	// this file lives in inc/extra/. Old data files were written to inc/ or to the install root, so look in both
	$roots = array(dirname(dirname(__DIR__)), dirname(__DIR__));
	$find = function ($name) use ($roots) {
		foreach ($roots as $r) {
			if (is_file($r . '/' . $name)) {
				return $r . '/' . $name;
			}
		}
		return $roots[0] . '/' . $name;
	};
	$gm = $find('.globalmessage.json');
	$trust = $find('.trustedips.php');
	$media = $find('.postmedia.php');
	$pending = $find('pending-data.php');
	$spoiler = $find('spoiler-data.php');
	$poll_files = array();
	foreach ($roots as $root) {
		foreach ((array)glob($root . '/*/polls/*.php') as $f) {
			$poll_files[] = array($f, basename(dirname(dirname($f))));
		}
		foreach ((array)glob($root . '/polls/*.php') as $f) {
			$poll_files[] = array($f, '');
		}
	}

	$any = is_file($gm) || is_file($trust) || is_file($media) || is_file($pending) || is_file($spoiler) || !empty($poll_files);
	if (!$any) {
		storeSettingSet('legacy_imported', '1');
		return;
	}
	// plain post ids need the boards/posts tables to work out which board they belong to
	if (!function_exists('allBoards') || !function_exists('postByID')) {
		return; // try again on a later request
	}

	// global message
	if (is_file($gm)) {
		$d = storeLegacyDecode($gm, false);
		if (isset($d['text']) && is_string($d['text']) && $d['text'] !== '') {
			storeSettingSet('globalmessage', $d['text']);
		}
		storeLegacyDone($gm);
	}

	// trusted IPs: "board|hashedip" => array(by, time)
	if (is_file($trust)) {
		foreach (storeLegacyDecode($trust) as $key => $v) {
			$parts = explode('|', (string)$key, 2);
			if (count($parts) != 2) {
				continue;
			}
			storeQuery("INSERT IGNORE INTO `" . storeTable('trusted') . "` (`board`, `ipkey`, `trusted_by`, `created`) VALUES ('" . storeEsc($parts[0]) . "', '" . hash('sha256', $parts[1]) . "', '" . storeEsc(isset($v['by']) ? $v['by'] : '') . "', " . intval(isset($v['time']) ? $v['time'] : 0) . ")");
		}
		storeLegacyDone($trust);
	}

	// extra media: "board:id" => records
	if (is_file($media)) {
		foreach (storeLegacyDecode($media) as $key => $records) {
			$parts = explode(':', (string)$key, 2);
			if (count($parts) != 2 || !is_array($records)) {
				continue;
			}
			$pos = 0;
			foreach ($records as $r) {
				$pos++;
				storeQuery("INSERT IGNORE INTO `" . storeTable('media') . "` (`board`, `post_id`, `position`, `file`, `orig`, `file_hex`, `size_text`, `w`, `h`, `thumb`, `tw`, `th`, `spoiler`) VALUES ('" . storeEsc($parts[0]) . "', " . intval($parts[1]) . ", " . $pos . ", '" . storeEsc(isset($r['file']) ? $r['file'] : '') . "', '" . storeEsc(isset($r['orig']) ? $r['orig'] : '') . "', '" . storeEsc(isset($r['hex']) ? $r['hex'] : '') . "', '" . storeEsc(isset($r['size']) ? $r['size'] : '') . "', " . intval(isset($r['w']) ? $r['w'] : 0) . ", " . intval(isset($r['h']) ? $r['h'] : 0) . ", '" . storeEsc(isset($r['thumb']) ? $r['thumb'] : '') . "', " . intval(isset($r['tw']) ? $r['tw'] : 0) . ", " . intval(isset($r['th']) ? $r['th'] : 0) . ", " . (!empty($r['spoiler']) ? 1 : 0) . ")");
			}
		}
		storeLegacyDone($media);
	}

	// pending: id => array(media, links, board, time)
	if (is_file($pending)) {
		foreach (storeLegacyDecode($pending) as $id => $e) {
			if (!is_array($e) || empty($e['board'])) {
				continue;
			}
			storeQuery("INSERT IGNORE INTO `" . storeTable('pending') . "` (`board`, `post_id`, `media`, `links`, `created`) VALUES ('" . storeEsc($e['board']) . "', " . intval($id) . ", " . (!empty($e['media']) ? 1 : 0) . ", " . (!empty($e['links']) ? 1 : 0) . ", " . intval(isset($e['time']) ? $e['time'] : 0) . ")");
		}
		storeLegacyDone($pending);
	}

	// spoilers: "board:id" => 1, or old plain "id" => 1
	if (is_file($spoiler)) {
		foreach (storeLegacyDecode($spoiler) as $key => $v) {
			$key = (string)$key;
			if (ctype_digit($key)) {
				$owners = storeOwnerBoards((int)$key, true);
				if (count($owners) !== 1) {
					continue;
				}
				$board = $owners[0];
				$id = (int)$key;
			} else {
				$parts = explode(':', $key, 2);
				if (count($parts) != 2) {
					continue;
				}
				$board = $parts[0];
				$id = intval($parts[1]);
			}
			storeQuery("INSERT IGNORE INTO `" . storeTable('spoilers') . "` (`board`, `post_id`) VALUES ('" . storeEsc($board) . "', " . $id . ")");
		}
		storeLegacyDone($spoiler);
	}

	// polls
	foreach ($poll_files as $pf) {
		list($file, $board) = $pf;
		$id = intval(basename($file, '.php'));
		if ($board === '') {
			$owners = storeOwnerBoards($id, false);
			if (count($owners) !== 1) {
				continue; // can't tell whose poll this is; leave the file alone
			}
			$board = $owners[0];
		}
		$poll = storeLegacyDecode($file);
		if (empty($poll['options']) || !is_array($poll['options'])) {
			storeLegacyDone($file);
			continue;
		}
		storeQuery("INSERT IGNORE INTO `" . storeTable('polls') . "` (`board`, `post_id`, `options`, `created`) VALUES ('" . storeEsc($board) . "', " . $id . ", '" . storeEsc(json_encode(array_values($poll['options']))) . "', " . intval(isset($poll['created']) ? $poll['created'] : 0) . ")");
		if (!empty($poll['voters']) && is_array($poll['voters'])) {
			foreach ($poll['voters'] as $voter => $opt) {
				storeQuery("INSERT IGNORE INTO `" . storeTable('poll_votes') . "` (`board`, `post_id`, `voter`, `opt`) VALUES ('" . storeEsc($board) . "', " . $id . ", '" . storeEsc(substr((string)$voter, 0, 40)) . "', " . intval($opt) . ")");
			}
		}
		storeLegacyDone($file);
	}

	storeSettingSet('legacy_imported', '1');
}

// one-time import of the remaining flat files (.boardflags.json, .pages.php, .ipmap.php ...)
// older installs wrote some of them to inc/ and some to the install root, so both are read:
// the folder the code really used wins, the other one only fills in what is missing
function storeImportFlatFiles() {
	if (storeSettingGet('legacy_flat_imported') === '1') {
		return;
	}
	$root = dirname(dirname(__DIR__));
	$inc = dirname(__DIR__);

	// name => array(file, primary folder, secondary folder, has a "<?php exit" first line)
	$blobs = array(
		'boardflags'    => array('.boardflags.json', $inc, $root, false),
		'boardfeatures' => array('.boardfeatures.php', $inc, $root, true),
		'extensions'    => array('.extensions.php', $inc, $root, true),
		'pages'         => array('.pages.php', $inc, $root, true),
		'home'          => array('.home.php', $root, $inc, true),
		'news_cache'    => array('news_cache.json', $inc, $root, false),
	);
	$maps = array(
		'ipmap'    => array('.ipmap.php', $inc, $root),
		'appeals'  => array('.appeals.php', $inc, $root),
		'watching' => array('.watching.php', $inc, $root),
		'postedits' => array('.postedits.php', $inc, $root),
	);
	$flood = array($root . '/flood.dat.php', $inc . '/flood.dat.php');

	$present = array();
	foreach ($blobs as $b) {
		$present[] = $b[1] . '/' . $b[0];
		$present[] = $b[2] . '/' . $b[0];
	}
	foreach ($maps as $m) {
		$present[] = $m[1] . '/' . $m[0];
		$present[] = $m[2] . '/' . $m[0];
	}
	$present = array_merge($present, $flood);
	$any = false;
	foreach ($present as $f) {
		if (is_file($f)) {
			$any = true;
			break;
		}
	}
	if (!$any) {
		storeSettingSet('legacy_flat_imported', '1');
		return;
	}
	// post edits need the boards/posts tables to find which board an id belongs to
	if (!function_exists('allBoards') || !function_exists('postByID')) {
		return; // try again on a later request
	}

	// JSON blobs
	foreach ($blobs as $name => $b) {
		$a = $b[1] . '/' . $b[0];
		$o = $b[2] . '/' . $b[0];
		$data = array();
		if (is_file($o)) {
			$data = storeLegacyDecode($o, $b[3]);
		}
		if (is_file($a)) {
			$data = array_merge($data, storeLegacyDecode($a, $b[3]));
		}
		if (!empty($data) && storeSettingGet($name, null) === null) {
			storeJsonSet($name, $data, JSON_UNESCAPED_UNICODE);
		}
		foreach (array($a, $o) as $f) {
			if (is_file($f)) {
				storeLegacyDone($f);
			}
		}
	}

	// IP map: hash => real ip
	$m = $maps['ipmap'];
	foreach (array($m[2] . '/' . $m[0], $m[1] . '/' . $m[0]) as $f) {
		if (!is_file($f)) {
			continue;
		}
		foreach (storeLegacyDecode($f) as $hash => $ip) {
			if (is_string($hash) && is_string($ip) && $hash !== '' && $ip !== '') {
				storeQuery("INSERT IGNORE INTO `" . storeTable('ipmap') . "` (`hash`, `ip`) VALUES ('" . storeEsc($hash) . "', '" . storeEsc($ip) . "')");
			}
		}
		storeLegacyDone($f);
	}

	// appeals: ban id => array(ts, time, text)
	$m = $maps['appeals'];
	foreach (array($m[2] . '/' . $m[0], $m[1] . '/' . $m[0]) as $f) {
		if (!is_file($f)) {
			continue;
		}
		foreach (storeLegacyDecode($f) as $id => $e) {
			if (!is_array($e) || !isset($e['text'])) {
				continue;
			}
			storeQuery("INSERT IGNORE INTO `" . storeTable('appeals') . "` (`ban_id`, `ts`, `created`, `text`) VALUES (" . intval($id) . ", " . intval(isset($e['ts']) ? $e['ts'] : 0) . ", " . intval(isset($e['time']) ? $e['time'] : 0) . ", '" . storeEsc($e['text']) . "')");
		}
		storeLegacyDone($f);
	}

	// watching: "board:thread" => array(viewer => time)  (only lives 90 seconds, still imported so nothing is lost)
	$m = $maps['watching'];
	foreach (array($m[2] . '/' . $m[0], $m[1] . '/' . $m[0]) as $f) {
		if (!is_file($f)) {
			continue;
		}
		foreach (storeLegacyDecode($f) as $key => $viewers) {
			$parts = explode(':', (string)$key, 2);
			if (count($parts) != 2 || !is_array($viewers)) {
				continue;
			}
			foreach ($viewers as $v => $t) {
				storeQuery("INSERT IGNORE INTO `" . storeTable('watching') . "` (`board`, `thread`, `viewer`, `seen`) VALUES ('" . storeEsc($parts[0]) . "', " . intval($parts[1]) . ", '" . storeEsc(substr((string)$v, 0, 64)) . "', " . intval($t) . ")");
			}
		}
		storeLegacyDone($f);
	}

	// post edits: post id => array(name, subject, deleted). ids repeat between boards, so work out who owns each one
	$m = $maps['postedits'];
	foreach (array($m[2] . '/' . $m[0], $m[1] . '/' . $m[0]) as $f) {
		if (!is_file($f)) {
			continue;
		}
		foreach (storeLegacyDecode($f) as $id => $e) {
			if (!is_array($e)) {
				continue;
			}
			foreach (storeOwnerBoards((int)$id, false) as $board) {
				$cols = array();
				$vals = array();
				if (array_key_exists('name', $e)) {
					$cols[] = '`name`';
					$vals[] = "'" . storeEsc($e['name']) . "'";
				}
				if (array_key_exists('subject', $e)) {
					$cols[] = '`subject`';
					$vals[] = "'" . storeEsc($e['subject']) . "'";
				}
				if (!empty($e['deleted'])) {
					$cols[] = '`deleted`';
					$vals[] = '1';
				}
				if (empty($cols)) {
					continue;
				}
				storeQuery("INSERT IGNORE INTO `" . storeTable('postedits') . "` (`board`, `post_id`, " . implode(', ', $cols) . ") VALUES ('" . storeEsc($board) . "', " . intval($id) . ", " . implode(', ', $vals) . ")");
			}
		}
		storeLegacyDone($f);
	}

	// anti-flood state is short lived, so the old file is just retired
	foreach ($flood as $f) {
		if (is_file($f)) {
			storeLegacyDone($f);
		}
	}

	storeSettingSet('legacy_flat_imported', '1');
}

// removes everything the extras stored for a board (called when a board is deleted)
function storePurgeBoard($slug) {
	if (!storeSupported()) {
		return;
	}
	storeEnsure();
	foreach (array('pending', 'spoilers', 'trusted', 'media', 'polls', 'poll_votes', 'postedits', 'watching') as $t) {
		storeQuery("DELETE FROM `" . storeTable($t) . "` WHERE `board` = '" . storeEsc($slug) . "'");
	}
}
