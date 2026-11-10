<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// board links bar, loaded by html.php
// links bar at the top
function boardSwitcher($current = null) {
	global $schan_boardbar_links;

	if ($current === null) {
		$current = current_board();
	}

	$install_root = installRootURL();

	$board_links = array();
	if (defined('SCHAN_OVERBOARD') && function_exists('rebuildOverboard')) {
		$ocls = ($current === SCHAN_OVERBOARD) ? ' class="current"' : '';
		$board_links[] = '<a href="' . htmlentities($install_root . '/' . SCHAN_OVERBOARD . '/', ENT_QUOTES) . '"' . $ocls . ' title="overboard">' . htmlentities(SCHAN_OVERBOARD) . '</a>';
	}
	if (function_exists('allBoards')) {
		foreach (allBoards() as $b) {
			$url = $install_root . '/' . rawurlencode($b['slug']) . '/';
			$cls = ($b['slug'] === $current) ? ' class="current"' : '';
			$title = $b['title'] != '' ? ' title="' . htmlentities($b['title'], ENT_QUOTES) . '"' : '';
			$board_links[] = '<a href="' . htmlentities($url, ENT_QUOTES) . '"' . $cls . $title . '>' . htmlentities($b['slug']) . '</a>';
		}
	}

	$custom_links = array();
	if (!empty($schan_boardbar_links) && is_array($schan_boardbar_links)) {
		foreach ($schan_boardbar_links as $link) {
			if (!is_array($link) || count($link) < 2) {
				continue;
			}
			$custom_links[] = '<a href="' . htmlentities($link[1], ENT_QUOTES) . '">' . htmlentities($link[0]) . '</a>';
		}
	}

	$groups = array();
	if (!empty($board_links)) {
		$groups[] = '[ <span class="boardlinks">' . implode(' / ', $board_links) . '</span> ]';
	}
	if (!empty($custom_links)) {
		$groups[] = '[ <span class="customlinks">' . implode(' / ', $custom_links) . '</span> ]';
	}
	if (empty($groups)) {
		return '';
	}

	return '<div class="boardbar" style="' . htmlentities(schanOpt('SCHAN_BOARDBARSTYLE', 'position:absolute;'), ENT_QUOTES) . '">' . implode(' ', $groups) . '</div>';
}
