<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}
require_once __DIR__ . '/storage.php';

/*
 * Spoiler thumbnails.
 *
 * When the Spoiler box is ticked on a file, the post is remembered here and every page
 * shows /spoiler.png instead of the real thumbnail. The real thumbnail and file stay on
 * disk (clicking the thumbnail still expands the real file). Staff views show the real one.
 *
 * Which posts are spoilered is kept in the database (<posts>_spoilers, keyed by board + post id).
 */

function spoilerBoardOf($post) {
	return (is_array($post) && isset($post['board']) && $post['board'] !== '') ? $post['board'] : null;
}

function spoilerBoardName($board = null) {
	if ($board === null || $board === '') {
		$board = function_exists('current_board') ? current_board() : '';
	}
	return $board;
}

// Post ids are only unique within a board, so a spoilered post is remembered as "board:id".
function spoilerKey($id, $board = null) {
	return spoilerBoardName($board) . ':' . intval($id);
}

// post id => 1 for one board (loaded with one query, cached for the request)
function spoilerBoardSet($board = null, $reset = false) {
	static $cache = array();
	$board = spoilerBoardName($board);
	if ($reset) {
		unset($cache[$board]);
	}
	if (!isset($cache[$board])) {
		$cache[$board] = array();
		foreach (storeRows("SELECT `post_id` FROM `" . storeTable('spoilers') . "` WHERE `board` = '" . storeEsc($board) . "'") as $row) {
			$cache[$board][(string)$row['post_id']] = 1;
		}
	}
	return $cache[$board];
}

// Compatibility: "board:id" => 1 for every spoilered post
function spoilerAll($reset = false) {
	$all = array();
	foreach (storeRows("SELECT `board`, `post_id` FROM `" . storeTable('spoilers') . "`") as $row) {
		$all[$row['board'] . ':' . $row['post_id']] = 1;
	}
	return $all;
}

// Legacy plain-id entries are converted by the one-time import in storage.php; kept so old callers still work.
function spoilerMigrateLegacy() {
	storeEnsure();
}

function spoilerIs($id, $board = null) {
	$set = spoilerBoardSet($board);
	return isset($set[(string)intval($id)]);
}

// Is this post row's file spoilered? (uses the row's own board when it has one)
function spoilerIsPost($post) {
	return is_array($post) && isset($post['id']) && spoilerIs($post['id'], spoilerBoardOf($post));
}

function spoilerSet($id, $board = null) {
	$board = spoilerBoardName($board);
	$ok = (bool)storeExec("INSERT IGNORE INTO `" . storeTable('spoilers') . "` (`board`, `post_id`) VALUES ('" . storeEsc($board) . "', " . intval($id) . ")") || spoilerIs($id, $board);
	spoilerBoardSet($board, true);
	return $ok;
}

function spoilerClear($id, $board = null) {
	$board = spoilerBoardName($board);
	storeExec("DELETE FROM `" . storeTable('spoilers') . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id));
	spoilerBoardSet($board, true);
}

// Positions (0 = first file, 1 = second, ...) of the files whose Spoiler box was ticked
function spoilerRequestedIndexes() {
	$indexes = array();
	if (isset($_POST['spoiler'])) {
		$indexes[] = 0; // old single checkbox
	}
	if (isset($_POST['spoiler_files']) && is_array($_POST['spoiler_files'])) {
		foreach ($_POST['spoiler_files'] as $index) {
			if (is_scalar($index) && ctype_digit((string)$index)) {
				$indexes[] = intval($index);
			}
		}
	}
	return array_values(array_unique($indexes));
}

// Was the Spoiler box ticked for the first (main) file in the posting form?
function spoilerPostRequested() {
	return in_array(0, spoilerRequestedIndexes(), true);
}

// Where spoiler.png actually is on disk. The page links to it as <site root URL>/spoiler.png, so first
// map that URL onto the web root; then fall back to the install folder, the folder above it, and the web root.
function spoilerImagePath() {
	static $path = null;
	if ($path !== null) {
		return $path;
	}

	$docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : '';
	$install = dirname(dirname(__FILE__));
	$candidates = array();
	if ($docroot !== '' && function_exists('installRootURL')) {
		$url_path = parse_url(installRootURL() . '/spoiler.png', PHP_URL_PATH);
		if (is_string($url_path) && $url_path !== '') {
			$candidates[] = $docroot . $url_path;
		}
	}
	$candidates[] = $install . '/spoiler.png';
	$candidates[] = dirname($install) . '/spoiler.png';
	if ($docroot !== '') {
		$candidates[] = $docroot . '/spoiler.png';
	}

	$path = '';
	foreach ($candidates as $candidate) {
		if (is_file($candidate) && is_readable($candidate)) {
			$path = $candidate;
			break;
		}
	}
	return $path;
}

// The image's own width and height, or array(0, 0) if the file can't be found or read.
function spoilerImageSize() {
	static $size = null;
	if ($size !== null) {
		return $size;
	}
	$size = array(0, 0);
	$path = spoilerImagePath();
	if ($path !== '') {
		$info = @getimagesize($path);
		if ($info && $info[0] > 0 && $info[1] > 0) {
			$size = array((int)$info[0], (int)$info[1]);
		}
	}
	return $size;
}

// width/height attributes for the <img>, always the image's own size. If the size can't be read this is
// empty, so the browser shows the image at its real size instead of a made-up one.
function spoilerImgAttrs() {
	list($w, $h) = spoilerImageSize();
	return ($w > 0 && $h > 0) ? ' width="' . $w . '" height="' . $h . '"' : '';
}
