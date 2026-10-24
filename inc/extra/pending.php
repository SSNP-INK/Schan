<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * Pending media / links approval.
 *
 * settings.php options (both default to off if not defined):
 *   define('SCHAN_REQMODMEDIA', true);  // posts show pending.png instead of their media until approved
 *   define('SCHAN_REQMODLINKS', true);  // links show "(link pending approval)" until approved
 *
 * Posts stay visible. Which posts are pending is kept in pending-data.php in the
 * install root (a PHP file that starts with exit, so it can't be read over the web).
 * Staff posts are never held.
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

function pendingFile() {
	return dirname(dirname(__FILE__)) . '/pending-data.php';
}

function pendingParse($raw) {
	$nl = strpos($raw, "\n");
	if ($nl === false) {
		return array();
	}
	$data = json_decode(substr($raw, $nl + 1), true);
	return is_array($data) ? $data : array();
}

// All pending entries: post id => array('media' => 0|1, 'links' => 0|1, 'board' => slug, 'time' => ts)
function pendingAll($reset = false) {
	static $cache = null;
	if ($reset) {
		$cache = null;
	}
	if (!pendingExtensionOn()) {
		return array();
	}
	if ($cache !== null) {
		return $cache;
	}

	$cache = array();
	$file = pendingFile();
	if (is_readable($file)) {
		$raw = @file_get_contents($file);
		if ($raw !== false && $raw !== '') {
			$cache = pendingParse($raw);
		}
	}
	return $cache;
}

function pendingGet($id) {
	$all = pendingAll();
	$key = (string)intval($id);
	return isset($all[$key]) ? $all[$key] : array();
}

// Read-modify-write under an exclusive lock
function pendingUpdate($fn) {
	$fh = @fopen(pendingFile(), 'c+');
	if (!$fh) {
		return false;
	}
	flock($fh, LOCK_EX);

	$raw = stream_get_contents($fh);
	$data = ($raw !== false && $raw !== '') ? pendingParse($raw) : array();
	$data = $fn($data);

	ftruncate($fh, 0);
	rewind($fh);
	fwrite($fh, "<?php exit; ?>\n" . json_encode($data));
	fflush($fh);
	flock($fh, LOCK_UN);
	fclose($fh);

	pendingAll(true);
	return true;
}

// $what: 'media', 'links' or 'all'
function pendingClear($id, $what = 'all') {
	$key = (string)intval($id);
	$entry = pendingGet($id);
	if (empty($entry)) {
		return;
	}
	pendingUpdate(function ($data) use ($key, $what) {
		if (!isset($data[$key])) {
			return $data;
		}
		if ($what === 'all') {
			unset($data[$key]);
			return $data;
		}
		$data[$key][$what] = 0;
		if (empty($data[$key]['media']) && empty($data[$key]['links'])) {
			unset($data[$key]);
		}
		return $data;
	});
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
	$key = (string)intval($post['id']);
	pendingUpdate(function ($data) use ($key, $media, $links, $board) {
		$data[$key] = array('media' => $media ? 1 : 0, 'links' => $links ? 1 : 0, 'board' => $board, 'time' => time());
		return $data;
	});

	if ($media && $links) {
		return __('Your media and links will be shown <b>once they have been approved</b>.');
	}
	if ($media) {
		return __('Your media will be shown <b>once it has been approved</b>.');
	}
	return __('Your links will be shown <b>once they have been approved</b>.');
}

function postMediaPending($post) {
	if (!isset($post['id'])) {
		return false;
	}
	$e = pendingGet($post['id']);
	return !empty($e['media']);
}

function postLinksPending($post) {
	if (!isset($post['id'])) {
		return false;
	}
	$e = pendingGet($post['id']);
	return !empty($e['links']);
}

function maskPendingLinks($html) {
	return preg_replace('/<a\s[^>]*href="(?:https?|ftp):\/\/[^"]*"[^>]*>.*?<\/a>/is', '<span class="linkpending" style="font-style:italic;opacity:0.7;">' . __('(link pending approval)') . '</span>', $html);
}

function pendingImageHTML($width = 150, $height = 150) {
	return '<img src="' . installRootURL() . '/pending.png" alt="' . __('Pending approval') . '" title="' . __('Pending approval') . '" class="thumb" width="' . intval($width) . '" height="' . intval($height) . '">';
}

// Posts waiting for media/links approval, newest first. Drops entries whose post no longer exists.
function pendingPostsList($limit = 50) {
	$all = pendingAll();
	if (empty($all)) {
		return array();
	}

	$ids = array_keys($all);
	rsort($ids, SORT_NUMERIC);

	$posts = array();
	$stale = array();
	$global = function_exists('manageGlobalMode') && manageGlobalMode();
	$board = current_board();

	foreach ($ids as $id) {
		$post = postByID($id);
		if (empty($post)) {
			$stale[] = (string)$id;
			continue;
		}
		if (!$global && $board !== '' && isset($post['board']) && $post['board'] !== '' && $post['board'] !== $board) {
			continue;
		}
		if (count($posts) >= $limit) {
			continue;
		}
		$post['show_boardtag'] = $global;
		$posts[] = $post;
	}

	if (!empty($stale)) {
		pendingUpdate(function ($data) use ($stale) {
			foreach ($stale as $key) {
				unset($data[$key]);
			}
			return $data;
		});
	}

	return $posts;
}

// Pending posts from every board (for the manage "Pending approval" page), newest first.
// Each post has ['board'] set, ['show_boardtag'] = true and ['pending'] = the entry.
function pendingPostsAllBoards($limit = 100) {
	$all = pendingAll();
	if (empty($all) || !function_exists('allBoards')) {
		return array();
	}

	$ids = array_keys($all);
	rsort($ids, SORT_NUMERIC);

	$slugs = array();
	foreach (allBoards() as $b) {
		$slugs[$b['slug']] = true;
	}

	$previous = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$posts = array();
	$stale = array();

	foreach ($ids as $id) {
		$entry = $all[$id];
		$slug = isset($entry['board']) ? $entry['board'] : '';
		if ($slug === '' || !isset($slugs[$slug])) {
			$stale[] = (string)$id;
			continue;
		}
		if (count($posts) >= $limit) {
			continue;
		}
		$GLOBALS['schan_forced_board'] = $slug;
		$post = postByID($id);
		if (empty($post)) {
			$stale[] = (string)$id;
			continue;
		}
		$post['board'] = $slug;
		$post['show_boardtag'] = true;
		$post['pending'] = $entry;
		$posts[] = $post;
	}
	$GLOBALS['schan_forced_board'] = $previous;

	if (!empty($stale)) {
		pendingUpdate(function ($data) use ($stale) {
			foreach ($stale as $key) {
				unset($data[$key]);
			}
			return $data;
		});
	}

	return $posts;
}

function pendingCount() {
	return count(pendingAll());
}
