<?php

/*
 * Board resolution
 * ----------------
 * current_board() returns the slug of the active board for this request.
 * The resolution order is:
 *   1. $GLOBALS['schan_forced_board'] if set  (used by rebuild loops)
 *   2. $_POST['board']  (post form submissions, delete actions)
 *   3. $_GET['board']   (pretty URLs, .htaccess rewrites, direct links)
 *   4. SCHAN_DEFAULTBOARD
 *
 * The result is validated against the boards table and cached for the
 * lifetime of the request. If the resolved board does not exist, the request
 * dies with an error page.
 *
 * When $_GET['manage'] is set AND no explicit board is given, the board
 * falls back to the default without dying on validation failure. This lets
 * the manage panel load even before any boards exist.
 */
function current_board() {
	static $board = null;

	// Callers that iterate multiple boards in one request can temporarily
	// override which board is active.
	if (isset($GLOBALS['schan_forced_board']) && $GLOBALS['schan_forced_board'] !== null) {
		return $GLOBALS['schan_forced_board'];
	}

	if ($board !== null) {
		return $board;
	}

	$slug = '';
	if (isset($_POST['board']) && $_POST['board'] !== '') {
		$slug = $_POST['board'];
	} elseif (isset($_GET['board']) && $_GET['board'] !== '') {
		$slug = $_GET['board'];
	}

	if ($slug !== '') {
		$slug = strtolower(trim($slug));
		if (!preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) {
			fancyDie('Invalid board identifier.');
		}
	}

	if ($slug === '') {
		$slug = SCHAN_DEFAULTBOARD;
	}

	if (function_exists('boardBySlug')) {
		$row = boardBySlug($slug);
		if (empty($row)) {
			if (isset($_GET['manage'])) {
				$slug = '';
			} else {
				fancyDie('Board not found: ' . htmlentities($slug));
			}
		}
	}

	$board = $slug;
	return $board;
}

if (SCHAN_ADMINPASS != '') {
	$admin = accountByUsername('admin');
	if (!empty($admin)) {
		$admin['password'] = SCHAN_ADMINPASS;
		if (!isset($admin['board']) || $admin['board'] === '') {
			$admin['board'] = '*';
		}
		updateAccount($admin);
	} else {
		$admin = array('username' => 'admin', 'password' => SCHAN_ADMINPASS, 'role' => SCHAN_SUPER_ADMINISTRATOR, 'board' => '*');
		insertAccount($admin);
	}
}

if (SCHAN_MODPASS != '') {
	$mod = accountByUsername('mod');
	if (!empty($mod)) {
		$mod['password'] = SCHAN_MODPASS;
		if (!isset($mod['board']) || $mod['board'] === '') {
			$mod['board'] = '*';
		}
		updateAccount($mod);
	} else {
		$mod = array('username' => 'mod', 'password' => SCHAN_MODPASS, 'role' => SCHAN_MODERATOR, 'board' => '*');
		insertAccount($mod);
	}
}

// Post caches. Keyed by board + thread id so two boards never collide.
$cache_all = array();
$cache_moderated = array();
function postsInThreadByID($id, $moderated_only = true) {
	global $cache_all, $cache_moderated;

	if ($moderated_only) {
		$cache = &$cache_moderated;
	} else {
		$cache = &$cache_all;
	}

	$id = intval($id);
	$key = current_board() . ':' . $id;
	if (!isset($cache[$key])) {
		$cache[$key] = _postsInThreadByID($id, $moderated_only);
	}
	return $cache[$key];
}

function clearPostCache() {
	global $cache_all, $cache_moderated;
	$cache_all = array();
	$cache_moderated = array();
}
