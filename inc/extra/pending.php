<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}
require_once __DIR__ . '/storage.php';

/*
 * Pending media / links approval.
 *
 * settings.php options (both default to off if not defined):
 *   define('SCHAN_REQMODMEDIA', true);  // posts show pending.png instead of their media until approved
 *   define('SCHAN_REQMODLINKS', true);  // links show "(link pending approval)" until approved
 *
 * Posts stay visible. Which posts are pending is kept in the database (<posts>_pending,
 * keyed by board + post id). Staff posts are never held.
 */

function pendingExtensionOn() {
	return !function_exists('extensionEnabled') || extensionEnabled('pending');
}

function reqmodMediaEnabled() {
	return pendingExtensionOn() && defined('SCHAN_REQMODMEDIA') && SCHAN_REQMODMEDIA;
}

function reqmodLinksEnabled() {
	return pendingExtensionOn() && defined('SCHAN_REQMODLINKS') && SCHAN_REQMODLINKS;
}

function pendingEntryFromRow($row) {
	return array(
		'media' => intval($row['media']),
		'links' => intval($row['links']),
		'board' => $row['board'],
		'time' => intval($row['created']),
	);
}

// Pending entries of one board: post id => array('media' => 0|1, 'links' => 0|1, 'board' => slug, 'time' => ts)
function pendingBoardMap($board = null, $reset = false) {
	static $cache = array();
	if ($board === null || $board === '') {
		$board = function_exists('current_board') ? current_board() : '';
	}
	if ($reset) {
		unset($cache[$board]);
	}
	if (!pendingExtensionOn()) {
		return array();
	}
	if (!isset($cache[$board])) {
		$cache[$board] = array();
		foreach (storeRows("SELECT * FROM `" . storeTable('pending') . "` WHERE `board` = '" . storeEsc($board) . "'") as $row) {
			$cache[$board][(string)$row['post_id']] = pendingEntryFromRow($row);
		}
	}
	return $cache[$board];
}

// Every pending entry on every board, newest first: array of entries that also carry 'id'.
function pendingRows() {
	if (!pendingExtensionOn()) {
		return array();
	}
	$out = array();
	foreach (storeRows("SELECT * FROM `" . storeTable('pending') . "` ORDER BY `post_id` DESC") as $row) {
		$e = pendingEntryFromRow($row);
		$e['id'] = intval($row['post_id']);
		$out[] = $e;
	}
	return $out;
}

// Compatibility: post id => entry across all boards (if two boards share an id, the last one wins).
function pendingAll($reset = false) {
	$all = array();
	foreach (array_reverse(pendingRows()) as $e) {
		$all[(string)$e['id']] = $e;
	}
	return $all;
}

function pendingGet($id, $board = null) {
	$map = pendingBoardMap($board);
	$key = (string)intval($id);
	return isset($map[$key]) ? $map[$key] : array();
}

function pendingSet($board, $id, $media, $links) {
	$ok = storeExec("INSERT INTO `" . storeTable('pending') . "` (`board`, `post_id`, `media`, `links`, `created`) VALUES ('" . storeEsc($board) . "', " . intval($id) . ", " . ($media ? 1 : 0) . ", " . ($links ? 1 : 0) . ", " . time() . ") ON DUPLICATE KEY UPDATE `media` = VALUES(`media`), `links` = VALUES(`links`), `created` = VALUES(`created`)");
	pendingBoardMap($board, true);
	return $ok > 0;
}

function pendingDeleteRow($board, $id) {
	storeExec("DELETE FROM `" . storeTable('pending') . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id));
	pendingBoardMap($board, true);
}

// $what: 'media', 'links' or 'all'
function pendingClear($id, $what = 'all', $board = null) {
	if ($board === null || $board === '') {
		$board = function_exists('current_board') ? current_board() : '';
	}
	$entry = pendingGet($id, $board);
	if (empty($entry)) {
		return;
	}
	if ($what === 'all') {
		pendingDeleteRow($board, $id);
		return;
	}
	if ($what !== 'media' && $what !== 'links') {
		return;
	}
	storeExec("UPDATE `" . storeTable('pending') . "` SET `" . $what . "` = 0 WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id));
	storeExec("DELETE FROM `" . storeTable('pending') . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id) . " AND `media` = 0 AND `links` = 0");
	pendingBoardMap($board, true);
}

function hasExternalLinks($html) {
	return (bool)preg_match('/<a\s[^>]*href="(?:https?|ftp):\/\//i', $html);
}

// Called right after a new post is inserted. Returns a notice for the poster, or ''.
function pendingRegisterPost($post) {
	$media = reqmodMediaEnabled() && isset($post['file']) && $post['file'] != '';
	$links = reqmodLinksEnabled() && hasExternalLinks(isset($post['message']) ? $post['message'] : '');
	if (!$media && !$links) {
		return '';
	}

	$board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	pendingSet($board, $post['id'], $media, $links);

	if ($media && $links) {
		return __('Your media and links will be shown <b>once they have been approved</b>.');
	}
	if ($media) {
		return __('Your media will be shown <b>once it has been approved</b>.');
	}
	return __('Your links will be shown <b>once they have been approved</b>.');
}

function pendingPostBoard($post) {
	return (isset($post['board']) && $post['board'] !== '') ? $post['board'] : null;
}

function postMediaPending($post) {
	if (!isset($post['id'])) {
		return false;
	}
	$e = pendingGet($post['id'], pendingPostBoard($post));
	return !empty($e['media']);
}

function postLinksPending($post) {
	if (!isset($post['id'])) {
		return false;
	}
	$e = pendingGet($post['id'], pendingPostBoard($post));
	return !empty($e['links']);
}

function maskPendingLinks($html) {
	return preg_replace('/<a\s[^>]*href="(?:https?|ftp):\/\/[^"]*"[^>]*>.*?<\/a>/is', '<span class="linkpending" style="font-style:italic;opacity:0.7;">' . __('(link pending approval)') . '</span>', $html);
}

function pendingImageHTML($width = 150, $height = 150) {
	return '<img src="' . installRootURL() . '/pending.png" alt="' . __('Pending approval') . '" title="' . __('Pending approval') . '" class="thumb" width="' . intval($width) . '" height="' . intval($height) . '">';
}

// Posts waiting for media/links approval, newest first. Drops entries whose post no longer exists.
// In global mode this lists every board (see pendingPostsAllBoards).
function pendingPostsList($limit = 50) {
	if (function_exists('manageGlobalMode') && manageGlobalMode()) {
		return pendingPostsAllBoards($limit);
	}

	$board = current_board();
	$map = pendingBoardMap($board);
	if (empty($map)) {
		return array();
	}

	$ids = array_keys($map);
	rsort($ids, SORT_NUMERIC);

	$posts = array();
	foreach ($ids as $id) {
		if (count($posts) >= $limit) {
			break;
		}
		$post = postByID($id);
		if (empty($post)) {
			pendingDeleteRow($board, $id);
			continue;
		}
		$post['show_boardtag'] = false;
		$posts[] = $post;
	}
	return $posts;
}

// Pending posts from every board (for the manage "Pending approval" page), newest first.
// Each post has ['board'] set, ['show_boardtag'] = true and ['pending'] = the entry.
function pendingPostsAllBoards($limit = 100) {
	$rows = pendingRows();
	if (empty($rows) || !function_exists('allBoards')) {
		return array();
	}

	$slugs = array();
	foreach (allBoards() as $b) {
		$slugs[$b['slug']] = true;
	}

	$previous = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$posts = array();

	foreach ($rows as $entry) {
		if (count($posts) >= $limit) {
			break;
		}
		$slug = $entry['board'];
		if ($slug === '' || !isset($slugs[$slug])) {
			pendingDeleteRow($slug, $entry['id']);
			continue;
		}
		$GLOBALS['schan_forced_board'] = $slug;
		$post = postByID($entry['id']);
		if (empty($post)) {
			pendingDeleteRow($slug, $entry['id']);
			continue;
		}
		$post['board'] = $slug;
		$post['show_boardtag'] = true;
		$post['pending'] = $entry;
		$posts[] = $post;
	}
	$GLOBALS['schan_forced_board'] = $previous;

	return $posts;
}

function pendingCount() {
	$row = storeRow("SELECT COUNT(*) AS c FROM `" . storeTable('pending') . "`");
	return $row === null ? 0 : intval($row['c']);
}
