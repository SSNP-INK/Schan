<?php
/*
 * mod.php is the whole staff area, replaces imgboard.php?manage
 *
 *   mod.php                     login, dashboard, every manage page (bans, reports, boards...)
 *   mod.php/<board>/            the board as visitors see it, with mod links on every post
 *   mod.php/<board>/2.html      page 2
 *   mod.php/<board>/res/5.html  thread 5
 *
 * "?manage" never shows in the address bar. a request to mod.php with a query string (or a form post)
 * is a panel page (mod.php?board=b&bans, mod.php/b/?board=b&reports ...), the plain board address
 * (mod.php/b/, mod.php/b/2.html, mod.php/b/res/5.html) is the moderated board view
 *
 * the board page has the staff post form instead of the public one, log in with your normal staff account
 * imgboard.php does the actual work, this file only reads the board and page from the address
 * old imgboard.php?manage... addresses redirect here
 */

chdir(__DIR__);

$schan_mod_path = '';
if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] !== '') {
	$schan_mod_path = $_SERVER['PATH_INFO'];
} else if (isset($_SERVER['REQUEST_URI'])) {
	// no PATH_INFO on this server, take whatever follows "mod.php/"
	$uri = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$marker = '/' . basename(__FILE__) . '/';
	$pos = strpos($uri, $marker);
	if ($pos !== false) {
		$schan_mod_path = substr($uri, $pos + strlen($marker) - 1);
	}
}

$schan_mod_parts = array_values(array_filter(explode('/', $schan_mod_path), 'strlen'));
$schan_mod_board = isset($schan_mod_parts[0]) ? strtolower(rawurldecode($schan_mod_parts[0])) : '';

// moderated pages live at mod.php/<board>/..., so relative urls in their scripts and forms
// ("imgboard.php?preview=5", "../imgboard.php?posts=...", the delete form) land on mod.php/<board>/imgboard.php
// pass those to imgboard.php as normal public requests so hover previews, live updates and deleting work here too
if (!empty($schan_mod_parts) && strtolower(end($schan_mod_parts)) === 'imgboard.php') {
	if (!isset($_GET['board']) && count($schan_mod_parts) > 1 && preg_match('/^[a-z0-9_-]{1,64}$/', $schan_mod_board)) {
		$_GET['board'] = $schan_mod_board;
	}
	require __DIR__ . '/imgboard.php';
	exit;
}

// keep "manage" out of the address bar: a GET like mod.php?manage&board=b&keywords (old bookmark, or a
// link that still adds it) redirects to mod.php?board=b&keywords. "?manage=KEY" with a value is left alone
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['manage']) && $_GET['manage'] === '') {
	$schan_q = $_GET;
	unset($schan_q['manage']);
	$schan_uri_path = (string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
	$schan_self_pos = strpos($schan_uri_path, '/' . basename(__FILE__) . '/');
	if (!$schan_q && $schan_self_pos !== false && preg_match('/^[a-z0-9_-]{1,64}$/', $schan_mod_board)) {
		// mod.php/<board>/?manage alone = that board's dashboard
		$schan_q = array('board' => $schan_mod_board);
		$schan_uri_path = substr($schan_uri_path, 0, $schan_self_pos + strlen(basename(__FILE__)) + 1);
	}
	header('Location: ' . $schan_uri_path . ($schan_q ? '?' . http_build_query($schan_q) : ''));
	exit;
}

// board links in staff pages: anything pointing at a board's front page (/b/, /b/index.html, b/, ../b/,
// or the full address) becomes mod.php/b/ so staff stay in mod.php. covers the board bar and every
// other board link. threads, files, other pages and the overboard are left alone
function schanModBoardLinks($html) {
	if (!function_exists('allBoards') || !function_exists('installRootURL') || !function_exists('modBoardURL')) {
		return $html;
	}

	$slugs = array();
	foreach (allBoards() as $b) {
		$slug = strtolower(is_array($b) ? (isset($b['slug']) ? $b['slug'] : '') : (string)$b);
		if ($slug === '' || !preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) {
			continue;
		}
		if (defined('SCHAN_OVERBOARD') && $slug === strtolower(SCHAN_OVERBOARD)) {
			continue;
		}
		$slugs[$slug] = true;
	}
	if (!$slugs) {
		return $html;
	}

	$root = installRootURL();
	$req_path = (string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
	$base_dir = substr($req_path, -1) === '/' ? $req_path : (rtrim(dirname($req_path), '/') . '/');
	$host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
	$index = defined('SCHAN_INDEX') ? preg_quote(SCHAN_INDEX, '#') : 'index\.html';

	$convert = function ($href) use ($slugs, $root, $base_dir, $host, $index) {
		$href = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8');
		if ($href === '' || strpbrk($href, '?#') !== false) {
			return null;
		}
		if (preg_match('#^https?://([^/]+)(/.*)?$#i', $href, $m)) {
			if (strtolower($m[1]) !== $host) {
				return null;
			}
			$path = isset($m[2]) && $m[2] !== '' ? $m[2] : '/';
		} else if (preg_match('#^(//|[a-z][a-z0-9+.-]*:)#i', $href)) {
			return null;
		} else if ($href[0] === '/') {
			$path = $href;
		} else {
			$path = $base_dir . $href;
		}

		$out = array();
		foreach (explode('/', $path) as $seg) {
			if ($seg === '..') {
				if (count($out) > 1) {
					array_pop($out);
				}
			} else if ($seg !== '.') {
				$out[] = $seg;
			}
		}
		$path = implode('/', $out);

		if ($root !== '' && strpos($path, $root . '/') !== 0) {
			return null;
		}
		$rest = substr($path, strlen($root) + 1);
		if (!preg_match('#^([a-z0-9_-]{1,64})(?:/(?:' . $index . ')?)?$#i', $rest, $m)) {
			return null;
		}
		$slug = strtolower($m[1]);
		return isset($slugs[$slug]) ? modBoardURL($slug) : null;
	};

	return preg_replace_callback('~(<a\b[^>]*?\shref=|<option\b[^>]*?\svalue=)(["\'])(.*?)\2~is', function ($m) use ($convert) {
		$to = $convert($m[3]);
		return $to === null ? $m[0] : $m[1] . $m[2] . htmlentities($to, ENT_QUOTES) . $m[2];
	}, $html);
}

// panel pages are built with "?manage&..." links, strip "manage" from every link on the way out so the
// address bar only shows mod.php ("?manage=KEY" with a value stays)
ob_start(function ($html) {
	$html = preg_replace('~\\?manage=?(?=["\'&])(?:&amp;|&)?~', '?', $html);
	try {
		return schanModBoardLinks($html);
	} catch (Throwable $e) {
		return $html;
	}
});

$schan_mod_query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
$schan_mod_is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

if (!preg_match('/^[a-z0-9_-]{1,64}$/', $schan_mod_board)) {
	if ($schan_mod_board !== '') {
		// not a board name after mod.php/, back to the plain address
		$root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
		header('Location: ' . $root . '/mod.php');
		exit;
	}

	// no board, so mod.php itself is the manage panel
	define('SCHAN_MODHOME', true);
	if (!isset($_GET['manage'])) {
		$_GET['manage'] = '';
	}
	require __DIR__ . '/imgboard.php';
	exit;
}

$_GET['board'] = $schan_mod_board;
$schan_mod_rest = array_slice($schan_mod_parts, 1);
if (isset($schan_mod_rest[0]) && $schan_mod_rest[0] === 'res' && isset($schan_mod_rest[1]) && ctype_digit(preg_replace('/\.html$/', '', $schan_mod_rest[1]))) {
	$_GET['mthread'] = (int)preg_replace('/\.html$/', '', $schan_mod_rest[1]);
} else if (isset($schan_mod_rest[0]) && ctype_digit(preg_replace('/\.html$/', '', $schan_mod_rest[0]))) {
	$_GET['mpage'] = (int)preg_replace('/\.html$/', '', $schan_mod_rest[0]);
}

// query string or form post = panel page, imgboard.php runs those through the manage code
if ($schan_mod_query !== '' || $schan_mod_is_post) {
	if (!isset($_GET['manage'])) {
		$_GET['manage'] = '';
	}
}

define('SCHAN_MODVIEW', true);
$GLOBALS['schan_modboard_requested'] = $schan_mod_board;

require __DIR__ . '/imgboard.php';
