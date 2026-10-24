<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// per-board features + board manage pages, loaded by html.php

// each board can turn parts of its post form on/off
// only diffs from $schan_hidefieldsop get saved, in .boardfeatures.php
// staff forms show everything. use boardFeatureEnabled('poll', $board) when handling a post
function boardFeatureList() {
	return array(
		'name'     => __('Name'),
		'email'    => __('E-mail'),
		'sage'     => __('Sage'),
		'subject'  => __('Subject'),
		'message'  => __('Message'),
		'emoji'    => __('Emoji picker'),
		'poll'     => __('Polls'),
		'memeflag' => __('Meme flags'),
		'embed'    => __('Embed'),
		'oekaki'   => __('Oekaki'),
		'password' => __('Password'),
	);
}

function boardFeaturesPath() {
	return dirname(__DIR__) . '/.boardfeatures.php';
}

function boardFeaturesLoad($force = false) {
	static $cache = null;
	if ($cache !== null && !$force) {
		return $cache;
	}
	$cache = array();
	$path = boardFeaturesPath();
	if (is_file($path)) {
		$fh = @fopen($path, 'r');
		if ($fh) {
			flock($fh, LOCK_SH);
			$cache = pagesParse(stream_get_contents($fh));
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}
	return $cache;
}

function boardFeaturesSave($data) {
	$flags = JSON_UNESCAPED_UNICODE;
	$fh = @fopen(boardFeaturesPath(), 'c+');
	if (!$fh) {
		return false;
	}
	$ok = false;
	if (flock($fh, LOCK_EX)) {
		$ok = ftruncate($fh, 0) && rewind($fh) && (fwrite($fh, "<?php http_response_code(404); exit; ?>\n" . json_encode($data, $flags)) !== false);
		fflush($fh);
		flock($fh, LOCK_UN);
	}
	fclose($fh);
	boardFeaturesLoad(true);
	return $ok;
}

// config default, for boards with no setting
function boardFeatureDefault($feature) {
	global $schan_hidefieldsop;
	return !in_array($feature, is_array($schan_hidefieldsop) ? $schan_hidefieldsop : array());
}

function boardFeatureEnabled($feature, $board) {
	$all = boardFeaturesLoad();
	if (isset($all[$board]) && is_array($all[$board]) && array_key_exists($feature, $all[$board])) {
		return (bool)$all[$board][$feature];
	}
	return boardFeatureDefault($feature);
}

// board settings on top of the hide-fields list buildPostForm uses
function boardApplyFeatureOverrides($hide_fields, $board) {
	$all = boardFeaturesLoad();
	if ($board === '' || !isset($all[$board]) || !is_array($all[$board])) {
		return $hide_fields;
	}
	foreach ($all[$board] as $feature => $on) {
		if ($feature === 'file') {
			continue; // file is never hidden
		}
		$hide_fields = array_values(array_diff($hide_fields, array($feature)));
		if (!$on) {
			$hide_fields[] = $feature;
		}
	}
	return $hide_fields;
}

// form is baked into every index/thread page so rebuild them
function boardFeaturesRebuild($slug) {
	$GLOBALS['schan_forced_board'] = $slug;
	$threads = allThreads();
	foreach ($threads as $thread) {
		rebuildThread($thread['id'], $slug);
	}
	rebuildIndexes($slug);
	$GLOBALS['schan_forced_board'] = null;
}

// handles the form from manageBoardFeaturesForm(), returns a notice or ''
function boardFeaturesHandlePost() {
	global $account, $isadmin;
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!isset($_POST['features_save']) && !isset($_POST['features_reset']))) {
		return '';
	}
	if (!$isadmin || !isset($account['role']) || $account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
		return '';
	}

	$old = boardFeaturesLoad();
	$new = array();
	$changed = array();
	$posted = (isset($_POST['feat']) && is_array($_POST['feat'])) ? $_POST['feat'] : array();
	$reset = isset($_POST['features_reset']);

	// features_board limits save/reset to one board
	$only = isset($_POST['features_board']) ? strtolower(trim($_POST['features_board'])) : '';

	foreach (allBoards() as $b) {
		$slug = $b['slug'];
		if ($only !== '' && $slug !== $only) {
			if (isset($old[$slug])) {
				$new[$slug] = $old[$slug];
			}
			continue;
		}
		$over = array();
		if (!$reset) {
			foreach (boardFeatureList() as $key => $label) {
				$on = isset($posted[$slug]) && is_array($posted[$slug]) && isset($posted[$slug][$key]);
				if ($on !== boardFeatureDefault($key)) {
					$over[$key] = $on;
				}
			}
		}
		if (!empty($over)) {
			$new[$slug] = $over;
		}
		$before = isset($old[$slug]) ? $old[$slug] : array();
		if ($before != $over) {
			$changed[] = $slug;
		}
	}

	if (!boardFeaturesSave($new)) {
		return manageInfo(__('Could not save the post form settings. Check that the install directory is writable.'));
	}
	foreach ($changed as $slug) {
		boardFeaturesRebuild($slug);
	}
	return manageInfo(sprintf(__('Post form settings saved. %d board(s) rebuilt.'), count($changed)));
}

// feature checkboxes on the board edit page
function manageBoardFeaturesForm($b) {
	$features = boardFeatureList();
	$id = intval($b['id']);
	$slug = htmlentities($b['slug'], ENT_QUOTES);

	$boxes = '';
	foreach ($features as $key => $label) {
		$boxes .= '<label style="display:inline-block;margin:0 16px 6px 0;"><input type="checkbox" name="feat[' . $slug . '][' . $key . ']" value="1"'
			. (boardFeatureEnabled($key, $b['slug']) ? ' checked' : '') . '> ' . htmlentities($label) . '</label>';
	}

	return '<fieldset><legend>' . __('Post form features') . '</legend>'
		. '<p>' . __('Tick what this board\'s post form should offer. Only changes from the config defaults are stored. Staff posting forms always show everything. Features that are unavailable site-wide (no polls, no wPaint, ...) stay hidden even when ticked.') . '</p>'
		. '<form method="post" action="?manage&amp;boards=' . $id . '">'
		. '<input type="hidden" name="features_board" value="' . $slug . '">'
		. '<div>' . $boxes . '</div><br>'
		. '<input type="submit" name="features_save" value="' . __('Save') . '" class="managebutton"> '
		. '<input type="submit" name="features_reset" value="' . __('Reset to defaults') . '" class="managebutton" onclick="return confirm(\'' . __('Reset this board to the config defaults?') . '\');">'
		. '</form></fieldset><br>';
}

// ?manage&boards
// $view: 'list' (table), 'new' (create form, $prefill refills on error), 'edit' (one board, $id)
function manageBoardsPage($view = 'list', $id = 0, $prefill = null) {
	$text = boardFeaturesHandlePost();

	if ($view === 'new') {
		return $text . manageBoardCreatePage($prefill);
	}
	if ($view === 'edit') {
		$b = boardByID(intval($id));
		if (!empty($b)) {
			return $text . manageBoardEditPage($b);
		}
		$text .= manageInfo(__('Board not found.'));
	}
	return $text . manageBoardListPage();
}

function manageBoardListPage() {
	$boards = allBoards();

	$rows = '';
	foreach ($boards as $b) {
		$slug = htmlentities($b['slug'], ENT_QUOTES);
		$flags_on = function_exists('flagsEnabledFor') && flagsEnabledFor($b['slug']);
		$custom_on = function_exists('indexCustomFor') && indexCustomFor($b['slug']);
		$file_on = function_exists('layoutFileboardFor') && layoutFileboardFor($b['slug']);
		$news_on = function_exists('newsBoardFor') && newsBoardFor($b['slug']);

		$rows .= '<tr>'
			. '<td><a href="../' . $slug . '/">/' . $slug . '/</a></td>'
			. '<td>' . htmlentities($b['title']) . '</td>'
			. '<td style="text-align:right;">' . countBoardThreads($b['slug']) . '</td>'
			. '<td style="text-align:right;">' . countBoardPosts($b['slug']) . '</td>'
			. '<td>' . (!empty($b['locked']) ? __('Yes') : __('No')) . '</td>'
			. '<td>' . ($flags_on ? __('Yes') : __('No')) . '</td>'
			. '<td>' . ($custom_on ? __('Custom') : __('Auto')) . '</td>'
			. '<td>' . ($file_on ? __('Fileboard') : __('Standard')) . '</td>'
			. '<td>' . ($news_on ? __('Yes') : __('No')) . '</td>'
			. '<td>' . formatDate($b['created']) . '</td>'
			. '<td><a href="?manage&amp;boards=' . intval($b['id']) . '" class="managebutton" style="text-decoration:none;">' . __('Edit') . '</a></td>'
			. '</tr>';
	}
	if ($rows == '') {
		$rows = '<tr><td colspan="11"><i>' . __('No boards yet.') . '</i></td></tr>';
	}

	$text = function_exists('globalMessageFormHTML') ? globalMessageFormHTML() : '';
	$text .= '<fieldset><legend>' . __('Boards') . '</legend>'
		. '<p><a href="?manage&amp;boards&amp;new" class="managebutton" style="text-decoration:none;">' . __('Create board') . '</a></p>'
		. (function_exists('flagsStatusNote') ? flagsStatusNote() : '')
		. '<div style="overflow-x:auto;max-width:100%;"><table border="1" cellspacing="0" cellpadding="4" width="100%">'
		. '<tr><th>' . __('Slug') . '</th><th>' . __('Title') . '</th><th>' . __('Threads') . '</th><th>' . __('Posts') . '</th><th>' . __('Locked') . '</th><th>' . __('Flags') . '</th><th>' . __('Index') . '</th><th>' . __('Layout') . '</th><th>' . __('News') . '</th><th>' . __('Created') . '</th><th></th></tr>'
		. $rows
		. '</table></div></fieldset><br>';

	return $text;
}

function manageBoardCreatePage($prefill = null) {
	return '<p>[<a href="?manage&amp;boards">' . __('Back to boards') . '</a>]</p>' . manageBoardForm(0, $prefill);
}

function manageBoardEditPage($b) {
	$id = intval($b['id']);
	$slug = htmlentities($b['slug'], ENT_QUOTES);
	$action = '?manage&amp;boards=' . $id;

	$locked = !empty($b['locked']);
	$flags_on = function_exists('flagsEnabledFor') && flagsEnabledFor($b['slug']);
	$custom_on = function_exists('indexCustomFor') && indexCustomFor($b['slug']);
	$file_on = function_exists('layoutFileboardFor') && layoutFileboardFor($b['slug']);
	$news_on = function_exists('newsBoardFor') && newsBoardFor($b['slug']);

	// one little POST form per toggle
	$toggle = function ($board_action, $field, $new_value, $label, $confirm = '') use ($id, $action) {
		return '<form method="post" action="' . $action . '" style="display:inline;"'
			. ($confirm !== '' ? ' onsubmit="return confirm(\'' . $confirm . '\');"' : '') . '>'
			. '<input type="hidden" name="board_action" value="' . $board_action . '">'
			. '<input type="hidden" name="board_id" value="' . $id . '">'
			. '<input type="hidden" name="' . $field . '" value="' . ($new_value ? '1' : '0') . '">'
			. '<input type="submit" value="' . $label . '" class="managebutton">'
			. '</form>';
	};

	$settings = array(
		array(__('Locked'), $locked ? __('Yes') : __('No'), $toggle('lock', 'board_locked', !$locked, $locked ? __('Unlock') : __('Lock'))),
	);
	if (function_exists('flagsSetEnabled')) {
		$settings[] = array(__('Country flags'), $flags_on ? __('Yes') : __('No'), $toggle('flags', 'board_flags', !$flags_on, $flags_on ? __('Disable flags') : __('Enable flags')));
	}
	if (function_exists('newsBoardSet')) {
		$settings[] = array(__('News board'), $news_on ? __('Yes') : __('No'), $toggle('news', 'board_news', !$news_on, $news_on ? __('Remove news') : __('Make news')));
	}
	if (function_exists('layoutFileboardSet')) {
		$settings[] = array(__('Layout'), $file_on ? __('Fileboard') : __('Standard'), $toggle('layout', 'board_layout', !$file_on, $file_on ? __('Standard layout') : __('Fileboard layout')));
	}
	if (function_exists('indexCustomSet')) {
		$settings[] = array(__('Index'), $custom_on ? __('Custom') : __('Auto'), $toggle('customindex', 'board_customindex', !$custom_on, $custom_on ? __('Auto index') : __('Custom index'),
			$custom_on ? 'Switch back to an automatic index? Your custom index.html is saved as index.html.bak and then regenerated.' : ''));
	}
	$rows = '';
	foreach ($settings as $s) {
		$rows .= '<tr><td>' . $s[0] . '</td><td>' . $s[1] . '</td><td>' . $s[2] . '</td></tr>';
	}

	$remove = ($b['slug'] === SCHAN_DEFAULTBOARD)
		? '<p><i>' . __('The default board cannot be removed.') . '</i></p>'
		: '<form method="post" action="?manage&amp;boards=' . $id . '" onsubmit="return confirm(\'Remove board ' . $slug . ' and all of its posts?\');">'
			. '<input type="hidden" name="board_action" value="remove">'
			. '<input type="hidden" name="board_id" value="' . $id . '">'
			. '<input type="submit" value="' . __('Remove board') . '" class="managebutton">'
			. '</form>';

	return '<p>[<a href="?manage&amp;boards">' . __('Back to boards') . '</a>] [<a href="?manage&amp;extensions">' . __('Extensions') . '</a>] [<a href="../' . $slug . '/">' . __('View board') . '</a>]</p>'
		. '<p><b>/' . $slug . '/</b> &mdash; ' . countBoardThreads($b['slug']) . ' ' . __('threads') . ', ' . countBoardPosts($b['slug']) . ' ' . __('posts') . ', ' . __('created') . ' ' . formatDate($b['created']) . '</p>'
		. manageBoardForm($id)
		. '<fieldset><legend>' . __('Settings') . '</legend>'
		. (function_exists('flagsStatusNote') ? flagsStatusNote() : '')
		. '<table border="1" cellspacing="0" cellpadding="4"><tr><th>' . __('Setting') . '</th><th>' . __('Current') . '</th><th></th></tr>' . $rows . '</table>'
		. '</fieldset><br>'
		. manageBoardFeaturesForm($b)
		. '<fieldset><legend>' . __('Remove board') . '</legend>' . $remove . '</fieldset><br>';
}

// $id 0 = create, otherwise edit slug/title/desc/logo. $prefill is for failed creates
function manageBoardForm($id = 0, $prefill = null) {
	$b = array('id' => 0, 'slug' => '', 'title' => '', 'desc' => '', 'logo' => '', 'locked' => 0);
	$txt_header = __('Create board');
	$board_action = 'add';
	$action = '?manage&amp;boards&amp;new';
	if ($id > 0) {
		$txt_header = __('Edit board');
		$board_action = 'rename';
		$action = '?manage&amp;boards=' . intval($id);
		$found = boardByID($id);
		if (!empty($found)) {
			$b = $found;
		}
	} else if (is_array($prefill)) {
		foreach (array('slug', 'title', 'desc', 'logo') as $k) {
			if (isset($prefill[$k])) {
				$b[$k] = $prefill[$k];
			}
		}
	}
	$b['id'] = intval($b['id']);
	$b['slug'] = htmlentities($b['slug'], ENT_QUOTES);
	$b['title'] = htmlentities($b['title'], ENT_QUOTES);
	$b['desc'] = htmlentities($b['desc'], ENT_QUOTES);
	$b['logo'] = htmlentities($b['logo'], ENT_QUOTES);

	$txt_slug = __('Slug');
	$txt_title = __('Title');
	$txt_desc = __('Description');
	$txt_logo = __('Logo HTML');
	$txt_submit = ($id > 0) ? __('Update') : __('Create board');

	$slug_input = ($id > 0)
		? '<input type="text" name="board_slug" id="slug" value="' . $b['slug'] . '" size="20" maxlength="64" readonly>'
		: '<input type="text" name="board_slug" id="slug" value="' . $b['slug'] . '" size="20" maxlength="64"> <small>' . __('letters, numbers, underscore, hyphen') . '</small>';

	return <<<EOF
	<form id="schan" name="schan" method="post" action="$action">
	<input type="hidden" name="board_action" value="$board_action">
	<input type="hidden" name="board_id" value="{$b['id']}">
	<fieldset>
	<legend>$txt_header</legend>
	<table border="0">
	<tr><td><label for="slug">$txt_slug</label></td><td>$slug_input</td></tr>
	<tr><td><label for="board_title">$txt_title</label></td><td><input type="text" name="board_title" id="board_title" size="40" maxlength="75" value="{$b['title']}"></td></tr>
	<tr><td><label for="board_desc">$txt_desc</label></td><td><input type="text" name="board_desc" id="board_desc" size="60" maxlength="255" value="{$b['desc']}"></td></tr>
	<tr><td><label for="board_logo">$txt_logo</label></td><td><input type="text" name="board_logo" id="board_logo" size="60" value="{$b['logo']}"></td></tr>
	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
	</table>
	</fieldset>
	</form><br>
EOF;
}
