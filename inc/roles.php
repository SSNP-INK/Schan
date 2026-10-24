<?php
/*
 * Extra staff roles.
 *
 *   Janitor (4)  Can only delete the files (image / media) of posts from the manage panel.
 *                Cannot delete posts, ban, sticky, lock, move, post, or open any other manage page.
 *   Fake    (5)  Can only post from the manage panel, with a capcode of their own choosing.
 *                No moderation tools, no raw HTML, and cannot reply to locked threads.
 *
 * Both are enforced on the server by roleGate(), which refuses every manage request that uses
 * anything other than the few actions listed below, so hiding buttons is only cosmetic.
 */

if (!defined('SCHAN_JANITOR')) {
	define('SCHAN_JANITOR', 4);
}
if (!defined('SCHAN_FAKE')) {
	define('SCHAN_FAKE', 5);
}

function roleOf($account) {
	return (is_array($account) && isset($account['role'])) ? intval($account['role']) : -1;
}

function roleIsJanitor($account) {
	return roleOf($account) === SCHAN_JANITOR;
}

function roleIsFake($account) {
	return roleOf($account) === SCHAN_FAKE;
}

function roleLabel($role) {
	$role = intval($role);
	if ($role === SCHAN_JANITOR) {
		return __('Janitor');
	}
	if ($role === SCHAN_FAKE) {
		return __('Fake');
	}
	return '';
}

// Refuses any manage request that a Janitor / Fake account is not allowed to make.
// Every manage action is chosen by a GET parameter, so allowing only certain parameters is enough.
function roleGate($account) {
	$allowed = null;
	if (roleIsJanitor($account)) {
		$allowed = array('manage', 'board', 'moderate', 'deletefile', 'tpage', 'changepassword', 'logout');
	} else if (roleIsFake($account)) {
		$allowed = array('manage', 'board', 'staffpost', 'changepassword', 'logout');
	}
	if ($allowed === null) {
		return;
	}
	foreach (array_keys($_GET) as $key) {
		if (!in_array($key, $allowed, true)) {
			fancyDie(__('Access denied.'));
		}
	}
}

// The capcode HTML for a Fake account's post: '' means "no capcode".
// Returns null for every other role, so the normal Admin / Mod capcode is used.
function roleCustomCapcode($account) {
	if (!roleIsFake($account)) {
		return null;
	}
	$text = isset($_POST['fake_capcode']) && is_string($_POST['fake_capcode']) ? $_POST['fake_capcode'] : '';
	$text = trim(str_replace(array("\r", "\n", "\0"), '', $text));
	$text = function_exists('mb_substr') ? mb_substr($text, 0, 32, 'UTF-8') : substr($text, 0, 32);
	if ($text === '') {
		return '';
	}
	$color = isset($_POST['fake_capcode_color']) && is_string($_POST['fake_capcode_color']) ? trim($_POST['fake_capcode_color']) : '';
	if (!preg_match('/^(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|[a-zA-Z]{3,20})$/', $color)) {
		$color = 'red';
	}
	return ' <span style="color: ' . $color . ';">## ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

// Extra rows for the staff post form shown to a Fake account.
function roleFakeCapcodeRowsHTML() {
	return '<tr><td class="postblock">' . __('Capcode') . '</td><td><input type="text" name="fake_capcode" size="28" maxlength="32" placeholder="' . htmlentities(__('Text shown after ##'), ENT_QUOTES) . '"></td></tr>'
		. '<tr><td class="postblock">' . __('Capcode color') . '</td><td><input type="text" name="fake_capcode_color" size="28" maxlength="20" value="red"> <small>' . __('A color name (red) or hex code (#ff0000)') . '</small></td></tr>';
}

// What a Janitor sees when moderating a post: the post and one button.
function roleJanitorModeratePost($post) {
	$board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	$has_file = ($post['file'] != '') || (function_exists('mediaExtrasFor') && !empty(mediaExtrasFor($board, $post['id'])));
	$post['show_boardtag'] = true;
	$post_html = buildPost($post, SCHAN_INDEXPAGE, false, true);
	$txt_moderating = sprintf(__('Moderating No.%d'), $post['id']);
	$action = $has_file
		? '<form method="get" action="?"><input type="hidden" name="manage" value=""><input type="hidden" name="board" value="' . htmlentities($board, ENT_QUOTES) . '"><input type="hidden" name="deletefile" value="' . intval($post['id']) . '"><input type="submit" value="' . __('Delete file') . '" class="managebutton"></form>'
		: '<small>' . __('That post has no file.') . '</small>';
	return '<fieldset><legend>' . $txt_moderating . '</legend>' . $post_html . '<br>' . $action . '</fieldset><br>';
}
