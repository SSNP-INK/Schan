<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * Spoiler thumbnails.
 *
 * When the Spoiler box is ticked on a file, the post is remembered here and every page
 * shows /spoiler.png instead of the real thumbnail. The real thumbnail and file stay on
 * disk (clicking the thumbnail still expands the real file). Staff views show the real one.
 *
 * Which posts are spoilered is kept in spoiler-data.php in the install root. The file starts
 * with exit, so it can't be read over the web.
 */

function spoilerFile() {
	return dirname(dirname(__FILE__)) . '/spoiler-data.php';
}

function spoilerParse($raw) {
	$nl = strpos($raw, "\n");
	if ($nl === false) {
		return array();
	}
	$data = json_decode(substr($raw, $nl + 1), true);
	return is_array($data) ? $data : array();
}

// post id => 1
function spoilerAll($reset = false) {
	static $cache = null;
	if ($reset) {
		$cache = null;
	}
	if ($cache !== null) {
		return $cache;
	}

	$cache = array();
	$file = spoilerFile();
	if (is_readable($file)) {
		$raw = @file_get_contents($file);
		if ($raw !== false && $raw !== '') {
			$cache = spoilerParse($raw);
		}
	}
	return $cache;
}

// Post ids are only unique within a board, so a spoilered post is remembered as "board:id".
function spoilerKey($id, $board = null) {
	if ($board === null || $board === '') {
		$board = function_exists('current_board') ? current_board() : '';
	}
	return $board . ':' . intval($id);
}

// The board a post row belongs to, or null (= the board currently being worked on)
function spoilerBoardOf($post) {
	return (is_array($post) && isset($post['board']) && $post['board'] !== '') ? $post['board'] : null;
}

// Old versions stored plain post ids ("5"), which made a post on one board spoiler the post with the same
// number on every other board. Move each one to the board whose post it was. If several boards have a file
// under that number there is no way to know which one was spoilered, so it is dropped.
function spoilerMigrateLegacy() {
	static $done = false;
	if ($done) {
		return;
	}
	$legacy = array();
	foreach (spoilerAll() as $key => $v) {
		if (ctype_digit((string)$key)) {
			$legacy[] = (int)$key;
		}
	}
	if (empty($legacy)) {
		$done = true;
		return;
	}
	if (!function_exists('allBoards') || !function_exists('postByID')) {
		return; // database not ready yet; try again on a later call
	}
	$done = true;

	$slugs = array();
	foreach (allBoards() as $b) {
		if (!defined('SCHAN_OVERBOARD') || $b['slug'] !== SCHAN_OVERBOARD) {
			$slugs[] = $b['slug'];
		}
	}
	$prev = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$assign = array();
	foreach ($legacy as $id) {
		$owners = array();
		foreach ($slugs as $slug) {
			$GLOBALS['schan_forced_board'] = $slug;
			if (function_exists('clearPostCache')) {
				clearPostCache();
			}
			$p = postByID($id);
			if (!empty($p) && isset($p['file']) && $p['file'] != '') {
				$owners[] = $slug;
			}
		}
		if (count($owners) === 1) {
			$assign[$id] = $owners[0];
		}
	}
	$GLOBALS['schan_forced_board'] = $prev;
	if (function_exists('clearPostCache')) {
		clearPostCache();
	}

	spoilerUpdate(function ($data) use ($legacy, $assign) {
		foreach ($legacy as $id) {
			unset($data[(string)$id]);
			if (isset($assign[$id])) {
				$data[$assign[$id] . ':' . $id] = 1;
			}
		}
		return $data;
	});
}

function spoilerIs($id, $board = null) {
	spoilerMigrateLegacy();
	$all = spoilerAll();
	return isset($all[spoilerKey($id, $board)]);
}

// Is this post row's file spoilered? (uses the row's own board when it has one)
function spoilerIsPost($post) {
	return is_array($post) && isset($post['id']) && spoilerIs($post['id'], spoilerBoardOf($post));
}

function spoilerUpdate($fn) {
	$fh = @fopen(spoilerFile(), 'c+');
	if (!$fh) {
		return false;
	}
	flock($fh, LOCK_EX);
	$raw = stream_get_contents($fh);
	$data = ($raw !== false && $raw !== '') ? spoilerParse($raw) : array();
	$data = $fn($data);
	ftruncate($fh, 0);
	rewind($fh);
	fwrite($fh, "<?php exit; ?>\n" . json_encode((object)$data));
	fflush($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	spoilerAll(true);
	return true;
}

function spoilerSet($id, $board = null) {
	spoilerMigrateLegacy();
	$key = spoilerKey($id, $board);
	return spoilerUpdate(function ($data) use ($key) {
		$data[$key] = 1;
		return $data;
	});
}

function spoilerClear($id, $board = null) {
	spoilerMigrateLegacy();
	$key = spoilerKey($id, $board);
	$all = spoilerAll();
	if (!isset($all[$key])) {
		return;
	}
	spoilerUpdate(function ($data) use ($key) {
		unset($data[$key]);
		return $data;
	});
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
