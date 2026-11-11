<?php
/*
 * Board search (MySQL / mysqli only).
 *
 * Page:    search.php?search=PHRASE&board=SLUG[&page=N]   (search.php is a small wrapper around imgboard.php)
 * Syntax:  words are matched case-insensitively and ALL must appear in the post text or subject.
 *          "quoted phrase" matches an exact phrase, * is a wildcard.
 *          Filters: id:123  thread:123  subject:word  name:Anonymous   (e.g. name:Anonymous subject:"Some Thread")
 *
 * Only approved posts are searched, and results are text only (no thumbnails),
 * so pending / spoilered media is never exposed here.
 */

if (!defined('SCHAN_SEARCH_PERPAGE')) {
	define('SCHAN_SEARCH_PERPAGE', 20);   // Results per page
}
if (!defined('SCHAN_SEARCH_MAXROWS')) {
	define('SCHAN_SEARCH_MAXROWS', 2000); // Newest posts examined per search
}

function searchParseQuery($q) {
	$out = array('terms' => array(), 'filters' => array());
	$q = trim($q);
	if ($q === '') {
		return $out;
	}
	preg_match_all('/(?:(id|thread|subject|name):)?(?:"([^"]*)"|(\S+))/iu', $q, $matches, PREG_SET_ORDER);
	foreach ($matches as $m) {
		$filter = (isset($m[1]) && $m[1] !== '') ? strtolower($m[1]) : '';
		if (isset($m[3]) && $m[3] !== '') {
			$value = trim($m[3], '"');
		} elseif (isset($m[2]) && $m[2] !== '') {
			$value = $m[2];
		} else {
			continue;
		}
		if ($value === '') {
			continue;
		}
		if (($filter === 'id' || $filter === 'thread') && !ctype_digit($value)) {
			// Not a number: treat the whole thing as an ordinary word.
			$out['terms'][] = $filter . ':' . $value;
		} elseif ($filter !== '') {
			$out['filters'][$filter] = $value;
		} else {
			$out['terms'][] = $value;
		}
	}
	$out['terms'] = array_slice($out['terms'], 0, 8);
	return $out;
}

function searchLike($s) {
	global $link;
	$s = addcslashes($s, '%_\\');
	$s = str_replace('*', '%', $s);
	return "'%" . mysqli_real_escape_string($link, $s) . "%'";
}

function searchPlainText($html) {
	$t = preg_replace('/<br\s*\/?>/i', ' ', $html);
	$t = strip_tags($t);
	return html_entity_decode($t, ENT_QUOTES, 'UTF-8');
}

function searchTermRegex($term) {
	return '/' . str_replace('\*', '.*?', preg_quote($term, '/')) . '/iu';
}

// Returns the matching posts, newest first.
function searchPosts($board, $parsed) {
	global $link;
	$where = array("`board` = '" . mysqli_real_escape_string($link, $board) . "'", '`moderated` = 1');
	$f = $parsed['filters'];
	if (isset($f['id'])) {
		$where[] = '`id` = ' . intval($f['id']);
	}
	if (isset($f['thread'])) {
		$where[] = '(`id` = ' . intval($f['thread']) . ' OR `parent` = ' . intval($f['thread']) . ')';
	}
	if (isset($f['name'])) {
		$where[] = '`name` LIKE ' . searchLike($f['name']);
	}
	if (isset($f['subject'])) {
		$where[] = '`subject` LIKE ' . searchLike($f['subject']);
	}
	// Plain words narrow the query in SQL. Anything with punctuation is only checked
	// afterwards (stored text contains HTML entities, so a SQL match could miss it).
	foreach ($parsed['terms'] as $t) {
		if (preg_match('/^[\p{L}\p{N}*]+$/u', $t)) {
			$like = searchLike($t);
			$where[] = '(`message` LIKE ' . $like . ' OR `subject` LIKE ' . $like . ')';
		}
	}

	$sql = "SELECT * FROM `" . SCHAN_DBPOSTS . "` WHERE " . implode(' AND ', $where) . " ORDER BY `id` DESC LIMIT " . intval(SCHAN_SEARCH_MAXROWS);
	$rows = array();
	$result = mysqli_query($link, $sql);
	if (!$result) {
		return $rows;
	}
	$regexes = array();
	foreach ($parsed['terms'] as $t) {
		$regexes[] = searchTermRegex($t);
	}
	while ($row = mysqli_fetch_assoc($result)) {
		if (!empty($regexes)) {
			$text = html_entity_decode($row['subject'], ENT_QUOTES, 'UTF-8') . ' ' . searchPlainText($row['message']);
			$ok = true;
			foreach ($regexes as $re) {
				if (!preg_match($re, $text)) {
					$ok = false;
					break;
				}
			}
			if (!$ok) {
				continue;
			}
		}
		$rows[] = $row;
	}
	return $rows;
}

function searchEsc($s) {
	return htmlentities($s, ENT_QUOTES, 'UTF-8');
}

function searchPage($board) {
	if (function_exists('extensionEnabled') && !extensionEnabled('search')) {
		fancyDie(__('Search is not installed.'));
	}
	if (SCHAN_DBMODE != 'mysqli') {
		fancyDie(__('Search requires the mysqli database mode.'));
	}

	$query = (isset($_GET['search']) && is_string($_GET['search'])) ? trim($_GET['search']) : '';
	$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
	$self = basename($_SERVER['SCRIPT_NAME']);

	$options = '';
	foreach (allBoards() as $b) {
		$sel = ($b['slug'] === $board) ? ' selected="selected"' : '';
		$options .= '<option value="' . searchEsc($b['slug']) . '"' . $sel . '>/' . searchEsc($b['slug']) . '/</option>';
	}

	$txt_search = __('Search');
	$txt_phrase = __('Phrase');
	$help1 = __('Search is case-insensitive and based on keywords. To match exact phrases, use "quotes". Use an asterisk (*) for wildcard.');
	$help2 = __('You may apply the following filters to your searches: <b>id</b>, <b>thread</b>, <b>subject</b>, and <b>name</b>. To apply a filter, simply add to your query, for example, <i>name:Anonymous</i> or <i>subject:"Some Thread"</i>. Wildcards cannot be used in filters.');

	$form = '<form action="' . searchEsc($self) . '" method="get">'
		. '<table class="postform" style="margin:0 auto;width:700px;max-width:96%;border:1px solid;">'
		. '<tr><td class="postblock" style="padding:4px;text-align:left;"><b>' . $txt_search . '</b></td></tr>'
		. '<tr><td style="padding:6px;">' . $txt_phrase . ': '
		. '<input type="text" name="search" value="' . searchEsc($query) . '" size="40" maxlength="200"> '
		. '<select name="board">' . $options . '</select> '
		. '<input type="submit" value="' . $txt_search . '"></td></tr>'
		. '<tr><td style="padding:6px;font-size:smaller;"><p>' . $help1 . '</p><p>' . $help2 . '</p></td></tr>'
		. '</table></form>';

	$results_html = '';
	if ($query !== '') {
		$parsed = searchParseQuery($query);
		if (empty($parsed['terms']) && empty($parsed['filters'])) {
			$matches = array();
		} else {
			$matches = searchPosts($board, $parsed);
		}
		$total = count($matches);
		$pages = max(1, (int)ceil($total / SCHAN_SEARCH_PERPAGE));
		if ($page > $pages) {
			$page = $pages;
		}

		if ($total == 0) {
			$results_html = '<p style="text-align:center;"><b>' . __('No results found.') . '</b></p>';
		} else {
			$results_html = '<p style="text-align:center;">' . sprintf(__('%d result(s) on /%s/'), $total, searchEsc($board)) . '</p>';
			$slice = array_slice($matches, ($page - 1) * SCHAN_SEARCH_PERPAGE, SCHAN_SEARCH_PERPAGE);
			foreach ($slice as $p) {
				$thread = ($p['parent'] == SCHAN_NEWTHREAD) ? $p['id'] : $p['parent'];
				$url = installRootURL() . '/' . rawurlencode($board) . '/res/' . intval($thread) . '.html#' . intval($p['id']);
				$name = html_entity_decode($p['name'], ENT_QUOTES, 'UTF-8');
				if ($name === '') {
					$name = 'Anonymous';
				}
				$subject = html_entity_decode($p['subject'], ENT_QUOTES, 'UTF-8');
				$text = trim(preg_replace('/\s+/u', ' ', searchPlainText($p['message'])));
				if (function_exists('mb_substr')) {
					if (mb_strlen($text, 'UTF-8') > 300) {
						$text = mb_substr($text, 0, 300, 'UTF-8') . '...';
					}
				} else if (strlen($text) > 300) {
					$text = substr($text, 0, 300) . '...';
				}
				$kind = ($p['parent'] == SCHAN_NEWTHREAD) ? __('Thread') : __('Reply');

				$results_html .= '<div class="reply" style="margin:6px auto;width:760px;max-width:96%;padding:6px;box-sizing:border-box;">'
					. '<span class="filetitle">' . ($subject !== '' ? '<b>' . searchEsc($subject) . '</b> ' : '') . '</span>'
					. '<span class="postername">' . searchEsc($name) . '</span> '
					. searchEsc(formatDate($p['timestamp'])) . ' '
					. $kind . ' <a href="' . searchEsc($url) . '">No.' . intval($p['id']) . '</a>'
					. '<div style="margin-top:4px;overflow-wrap:anywhere;">' . searchEsc($text) . '</div>'
					. '</div>';
			}

			if ($pages > 1) {
				$base = $self . '?search=' . rawurlencode($query) . '&board=' . rawurlencode($board);
				$nav = '<p style="text-align:center;">';
				for ($i = 1; $i <= $pages; $i++) {
					if ($i == $page) {
						$nav .= '&#91;' . $i . '&#93; ';
					} else {
						$nav .= '&#91;<a href="' . searchEsc($base . '&page=' . $i) . '">' . $i . '</a>&#93; ';
					}
				}
				$results_html .= $nav . '</p>';
			}
		}
	}

	$body = '<body>' . boardSwitcher($board)
		. '<div class="adminbar"></div>'
		. '<div class="logo">' . boardHeaderHTML($board) . '</div>'
		. '<hr width="90%">'
		. '<div class="replymode">' . $txt_search . '</div>'
		. $form . $results_html . '<hr>';

	return pageHeader($board) . $body . pageFooter();
}
