<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// rest of the page code is in inc/element/
require_once __DIR__ . '/element/header.php';
require_once __DIR__ . '/element/post.php';
require_once __DIR__ . '/element/liveupdate.php';
require_once __DIR__ . '/element/catalog.php';
require_once __DIR__ . '/element/manage.php';
require_once __DIR__ . '/element/pages.php';
require_once __DIR__ . '/element/boards.php';
require_once __DIR__ . '/element/json.php';
require_once __DIR__ . '/element/post_form.php';
require_once __DIR__ . '/element/boardlinks.php';
require_once __DIR__ . '/element/options.php';
require_once __DIR__ . '/element/hover.php';

// optional setting from settings.php, the constant if it's defined else $default
function schanOpt($name, $default) {
	return defined($name) ? constant($name) : $default;
}

// url path (not filesystem) of the install root, e.g. "/imgb"
function installRootURL() {
	static $cached = null;
	if ($cached !== null) {
		return $cached;
	}

	if (defined('SCHAN_ROOTURL')) {
		$cached = rtrim(SCHAN_ROOTURL, '/');
		return $cached;
	}

	$fs = str_replace('\\', '/', dirname(__FILE__));
	$fs = str_replace('\\', '/', dirname($fs));

	$docroot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';

	if ($docroot !== '' && strpos($fs, $docroot) === 0) {
		$cached = substr($fs, strlen($docroot));
	} else {
		$cached = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
	}

	if ($cached === '/' || $cached === false) {
		$cached = '';
	}

	return $cached;
}

// boards shown in the manage views, always all of them
function manageVisibleBoards() {
	if (!function_exists('allBoards')) {
		return array();
	}
	return allBoards();
}

function boardURLPrefix($res = false) {
	return $res ? '../' : '';
}

function boardOutputDir($res = false) {
	$board = current_board();
	if ($board === '') {
		return $res ? 'res/' : '';
	}
	return $board . '/' . ($res ? 'res/' : '');
}

function boardAssetURL($path, $res = false) {
	$path = ltrim($path, '/');
	if (substr($path, 0, 4) === 'src/') {
		$path = 'src/' . substr($path, 4);
	} else if (substr($path, 0, 6) === 'thumb/') {
		$path = 'thumb/' . substr($path, 6);
	} else if (substr($path, 0, 4) === 'res/') {
		$path = 'res/' . substr($path, 4);
	}
	return boardURLPrefix($res) . $path;
}

function currentBoardRow($board = null) {
	if ($board === null) {
		$board = current_board();
	}

	if ($board === '' && !empty($GLOBALS['schan_page_title'])) {
		return array('slug' => '', 'title' => $GLOBALS['schan_page_title'], 'desc' => '', 'logo' => '', 'locked' => 0);
	}
	if (defined('SCHAN_OVERBOARD') && $board === SCHAN_OVERBOARD) {
		return array('slug' => $board, 'title' => 'overboard', 'desc' => 'Latest threads from every board', 'logo' => '', 'locked' => 0);
	}

	if ($board === '' || !function_exists('boardBySlug')) {
		return array('slug' => $board, 'title' => '', 'desc' => '', 'logo' => '', 'locked' => 0);
	}

	$r = boardBySlug($board);
	if (empty($r)) {
		return array('slug' => $board, 'title' => '', 'desc' => '', 'logo' => '', 'locked' => 0);
	}

	return $r;
}

function buildPage($htmlposts, $parent, $pages = 0, $thispage = 0, $lastpostid = 0, $render_board = null) {
	global $schan_stylesheets;

	if ($render_board === null) {
		$render_board = current_board();
	}

	$prefix = $render_board === '' ? '' : '../';
	$cataloglink = SCHAN_CATALOG ? ('[<a href="catalog.html" style="text-decoration: underline;">' . __('Catalog') . '</a>]') : '';
	$managelink = (SCHAN_MANAGEKEY == '') ? ('[<a href="' . htmlentities(installRootURL() . '/mod.php', ENT_QUOTES) . '" style="text-decoration: underline;">' . __('Manage') . '</a>]') : '';
	if (!empty($GLOBALS['schan_modview'])) {
		$managelink = '[<a href="' . htmlentities(installRootURL() . '/mod.php?board=' . rawurlencode($render_board), ENT_QUOTES) . '" style="text-decoration: underline;">' . __('Manage') . '</a>] '
			. '[<a href="' . htmlentities(modBoardURL($render_board) . '?logout', ENT_QUOTES) . '" style="text-decoration: underline;">' . __('Log Out') . '</a>] <b>[' . __('Moderating') . ']</b>';
	}
	$switcher = boardSwitcher($render_board);
	if ($parent == -3 && isset($GLOBALS['schan_page_opts']) && empty($GLOBALS['schan_page_opts']['boardbar'])) {
		$switcher = '';
	}

	$postingmode = "";
	$pagenavigator = "";
	if ($parent == SCHAN_NEWTHREAD) {
		$pages = max($pages, 0);
		$previous = ($thispage == 1) ? "index" : $thispage - 1;
		$next = $thispage + 1;

		$pagelinks = ($thispage == 0) ? ('<td>' . __('Previous') . '</td>') : ('<td><form method="get" action="' . $previous . '.html"><input value="' . __('Previous') . '" type="submit"></form></td>');

		$pagelinks .= "<td>";
		for ($i = 0; $i <= $pages; $i++) {
			if ($thispage == $i) {
				$pagelinks .= '&#91;' . $i . '&#93; ';
			} else {
				$href = ($i == 0) ? "index" : $i;
				$pagelinks .= '<a class="btnlink" href="' . $href . '.html">' . $i . '</a> ';
			}
		}
		$pagelinks .= "</td>";

		$pagelinks .= ($pages <= $thispage) ? ('<td>' . __('Next') . '</td>') : ('<td><form method="get" action="' . $next . '.html"><input value="' . __('Next') . '" type="submit"></form></td>');

		$pagenavigator = <<<EOF
<table border="1" style="display: inline-block;">
	<tbody>
		<tr>
			$pagelinks
		</tr>
	</tbody>
</table>
EOF;
		if (SCHAN_CATALOG) {
			$txt_catalog = __('Catalog');
			$pagenavigator .= <<<EOF
<table border="1" style="display: inline-block;margin-left: 21px;">
	<tbody>
		<tr>
			<td><form method="get" action="catalog.html"><input value="$txt_catalog" type="submit"></form></td>
		</tr>
	</tbody>
</table>
EOF;
		}
	} else if ($parent == -2 || $parent == -3) {
		$postingmode = '';
	} else if ($parent == -1) {
		$postingmode = '<a class="btnlink" href="index.html">' . __('Return') . '</a><div class="replymode">' . __('Catalog') . '</div> ';
	} else {
		$postingmode = '<a class="btnlink" href="' . $prefix . '">' . __('Return') . '</a><div class="replymode">' . __('Posting mode: Reply') . '</div> ';
	}

	$postform = '';
	if ($parent >= 0) {
		$locked_row = currentBoardRow($render_board);
		if (!empty($GLOBALS['schan_modview'])) {
			// staff can post on locked and news boards
			$mod_account = isset($GLOBALS['account']) ? $GLOBALS['account'] : array();
			$postform = (function_exists('roleIsJanitor') && roleIsJanitor($mod_account)) ? '<hr>' : buildPostForm($parent, true, $render_board) . '<hr>';
		} else if (!empty($locked_row['locked'])) {
			$postform = '<div class="boardlocked" style="text-align:center;font-weight:bold;padding:6px;">' . __('This board is locked and is not accepting new posts.') . '</div><hr>';
		} else if ($parent == SCHAN_NEWTHREAD && function_exists('newsBoardFor') && newsBoardFor($render_board)) {
			$postform = '<hr>';
		} else {
			$postform = buildPostForm($parent, false, $render_board) . '<hr>';
		}
	}

	$globalmessage = (function_exists('globalMessageHTML') && empty($GLOBALS['schan_no_globalmessage'])) ? globalMessageHTML() : '';

	$js = '<script type="text/javascript">';
	$js .= 'var enablebacklinks = ' . (SCHAN_BACKLINKS ? 'true' : 'false') . ';';
	$live_delay = liveUpdateDelay();
	if ($parent != SCHAN_NEWTHREAD && $parent != -2 && $parent != -3 && SCHAN_AUTOREFRESH > 0 && $live_delay == 0) {
		$js .= 'var autoRefreshDelay = ' . SCHAN_AUTOREFRESH . ';';
		$js .= 'var autoRefreshThreadID = ' . $parent . ';';
		$js .= 'var autoRefreshPostID = ' . $lastpostid . ';';
	}
	if ($live_delay > 0) {
		$js .= 'var liveUpdateDelay = ' . $live_delay . ';';
	}
	$js .= '</script>';
	if ($parent > 0 && function_exists('threadStatsJS') && extensionEnabled('stats')) {
		$js .= threadStatsJS();
	}
	if ($live_delay > 0) {
		$js .= liveUpdateJS();
	}

	if ($parent >= 0 && schanOpt('SCHAN_HOVERPREVIEW', true)) {
		$js .= hoverPreviewJS($render_board);
	}
	if ($parent >= 0 && schanOpt('SCHAN_CLICKQUOTE', true)) {
		$js .= clickQuoteJS();
	}

	$txt_password = __('Password');
	$txt_delete = __('Delete');
	$txt_delete_post = __('Delete Post');

	$del_action = $prefix . 'imgboard.php?delete';

	list($options_link, $options_modal) = buildOptionsElement();
	if (!schanOpt('SCHAN_OPTIONSLINK', true)) {
		$options_link = '';
		$options_modal = '';
	}

	$searchbar = '';
	if (schanOpt('SCHAN_SEARCHBAR', true) && function_exists('searchPage') && extensionEnabled('search') && $parent == SCHAN_NEWTHREAD && $render_board !== '' && !(defined('SCHAN_OVERBOARD') && $render_board === SCHAN_OVERBOARD)) {
		$searchbar = '<div class="searchbar" style="margin:4px 0 8px 4px;"><form action="' . $prefix . 'search.php" method="get" style="display:inline;">'
			. '<input type="hidden" name="board" value="' . htmlentities($render_board, ENT_QUOTES) . '">'
			. '<input type="text" name="search" size="22" maxlength="200" placeholder="/' . htmlentities($render_board, ENT_QUOTES) . '/ ' . __('search') . '"> '
			. '<input type="submit" value="' . __('Search') . '"></form></div>';
	}

	$userdelete_html = '';
	if (schanOpt('SCHAN_USERDELETE', true)) {
		$userdelete_html = <<<UDEOF
		<hr>
		<table class="userdelete">
			<tbody>
				<tr>
					<td>
						$txt_delete_post <input type="password" name="password" id="deletepostpassword" size="8" placeholder="$txt_password">&nbsp;<input name="deletepost" value="$txt_delete" type="submit">
					</td>
				</tr>
			</tbody>
		</table>
UDEOF;
	}

	if ($parent == -3) {
		$page_css = (isset($GLOBALS['schan_page_opts']['css']) && trim($GLOBALS['schan_page_opts']['css']) !== '') ? '<style>' . str_ireplace('</style', '<\\/style', $GLOBALS['schan_page_opts']['css']) . '</style>' : '';
		$main_html = $page_css . '<div class="staticpage">' . $htmlposts . '</div>';
	} else {
		$main_html = <<<MAINEOF
		$searchbar
		<form id="delform" action="$del_action" method="post">
		<input type="hidden" name="board" value="{$render_board}">
		<div id="posts">
		$htmlposts
		</div>
		$userdelete_html
		</form>
		$pagenavigator
MAINEOF;
	}

	$body = <<<EOF
	<body>
		$switcher
		<div class="adminbar">
			$cataloglink
			$managelink
			$options_link
		</div>
		$options_modal
		<div class="logo">
EOF;
	$body .= boardHeaderHTML($render_board) . <<<EOF
		</div>
		<hr width="90%">
		$postingmode
		$postform
		$globalmessage
		$js
		$main_html
		<br>
EOF;
	return pageHeader($render_board) . $body . pageFooter();
}

function rebuildIndexes($board = null) {
	if ($board === null) {
		$board = current_board();
	}
	if ($board === '') {
		return;
	}
	if (defined('SCHAN_OVERBOARD') && $board === SCHAN_OVERBOARD) {
		if (function_exists('rebuildOverboard')) {
			rebuildOverboard();
		}
		return;
	}

	$GLOBALS['schan_forced_board'] = $board;

	$page = 0;
	$i = 0;
	$htmlposts = '';
	$threads = allThreads();
	$fileboard = function_exists('layoutFileboardFor') && layoutFileboardFor($board);
	$per_page = $fileboard ? max(1, intval(SCHAN_FILEBOARD_PERPAGE)) : SCHAN_THREADSPERPAGE;
	$pages = ceil(count($threads) / $per_page) - 1;
	$outdir = boardOutputDirFor($board, false);
	$custom_index = function_exists('indexCustomFor') && indexCustomFor($board);

	foreach ($threads as $thread) {
		$replies = postsInThreadByID($thread['id']);
		$thread['omitted'] = max(0, count($replies) - SCHAN_PREVIEWREPLIES - 1);

		if ($fileboard) {
			$htmlposts .= fileboardRowHTML($thread, max(0, count($replies) - 1), $i);
		} else {
			$htmlreplies = array();
			for ($j = count($replies) - 1; $j > $thread['omitted']; $j--) {
				$htmlreplies[] = buildPost($replies[$j], SCHAN_INDEXPAGE);
			}

			if ($i > 0) {
				$htmlposts .= "\n<hr>";
			}
			$htmlposts .= buildPost($thread, SCHAN_INDEXPAGE) . implode('', array_reverse($htmlreplies));
		}

		if (++$i >= $per_page) {
			$file = ($page == 0) ? $outdir . SCHAN_INDEX : $outdir . $page . '.html';
			if (!($page == 0 && $custom_index && is_file($file))) {
				writePage($file, buildPage($fileboard ? fileboardTable($htmlposts) : $htmlposts, 0, $pages, $page, 0, $board));
			}

			$page++;
			$i = 0;
			$htmlposts = '';
		}
	}

	if ($page == 0 || $htmlposts != '') {
		$file = ($page == 0) ? $outdir . SCHAN_INDEX : $outdir . $page . '.html';
		if (!($page == 0 && $custom_index && is_file($file))) {
			writePage($file, buildPage($fileboard ? fileboardTable($htmlposts) : $htmlposts, 0, $pages, $page, 0, $board));
		}
	}

	if (SCHAN_CATALOG) {
		rebuildCatalog($board);
	}

	if (SCHAN_JSON) {
		writePage($outdir . 'threads.json', buildIndexJSON());
		writePage($outdir . 'catalog.json', buildCatalogJSON());
	}

	$GLOBALS['schan_forced_board'] = null;

	// a full rebuild does these once at the end
	if (!empty($GLOBALS['schan_rebuild_all'])) {
		return;
	}

	// overboard always follows the boards
	if (defined('SCHAN_OVERBOARD') && function_exists('rebuildOverboard')) {
		rebuildOverboard();
	}
	if (function_exists('newsRebuildCache')) {
		newsRebuildCache();
	}
	if (function_exists('rebuildPages')) {
		rebuildPages(false);
	}
}

// rebuilds everything: all threads and indexes of every board, overboard, news cache,
// every page (home, flags, stats, static), skips the 5 min throttle
function rebuildEverything() {
	$GLOBALS['schan_rebuild_all'] = true;

	foreach (allBoards() as $b) {
		$slug = is_array($b) ? $b['slug'] : $b;
		if ($slug === '' || (defined('SCHAN_OVERBOARD') && $slug === SCHAN_OVERBOARD)) {
			continue;
		}
		$GLOBALS['schan_forced_board'] = $slug;
		foreach (allThreads() as $thread) {
			rebuildThread($thread['id'], $slug);
		}
		rebuildIndexes($slug);
		$GLOBALS['schan_forced_board'] = null;
	}

	$GLOBALS['schan_rebuild_all'] = false;

	if (defined('SCHAN_OVERBOARD') && function_exists('rebuildOverboard')) {
		rebuildOverboard();
	}
	if (function_exists('newsRebuildCache')) {
		newsRebuildCache();
	}
	if (function_exists('rebuildPages')) {
		rebuildPages(true);
	}
}

function rebuildThread($id, $board = null) {
	$id = intval($id);

	if ($board === null) {
		$board = current_board();
	}
	if ($board === '') {
		return;
	}

	$GLOBALS['schan_forced_board'] = $board;

	$post = postByID($id);
	if (empty($post) || $post['moderated'] == 0) {
		@unlink(boardOutputDirFor($board, true) . $id . '.html');
		$GLOBALS['schan_forced_board'] = null;
		return;
	}

	$posts = postsInThreadByID($id);
	if (count($posts) == 0) {
		@unlink(boardOutputDirFor($board, true) . $id . '.html');
		$GLOBALS['schan_forced_board'] = null;
		return;
	}

	$htmlposts = "";
	$lastpostid = 0;
	foreach ($posts as $post) {
		$htmlposts .= buildPost($post, SCHAN_RESPAGE);
		$lastpostid = $post['id'];
	}
	if (function_exists('threadStatsHTML') && extensionEnabled('stats')) {
		$htmlposts .= threadStatsHTML($id, $board, $posts, true);
	}

	$outdir = boardOutputDirFor($board, true);
	writePage($outdir . $id . '.html', fixLinksInRes(buildPage($htmlposts, $id, 0, 0, $lastpostid, $board)));

	if (SCHAN_JSON) {
		writePage($outdir . $id . '.json', buildSingleThreadJSON($id));
	}

	$GLOBALS['schan_forced_board'] = null;
}
