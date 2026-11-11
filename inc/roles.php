<?php
/*
 * Staff roles: everything about a role is configured in roleConfig() below.
 *
 * For each role:
 *   label    Name shown in the manage panel.
 *   capcode  What is added after the name of that role's posts:
 *              array('name' => 'Admin', 'color' => 'red')  ->  ## Admin   (in that color)
 *              null                                          ->  no capcode
 *   allowed  Which manage actions the role may use (they are the GET parameters of the manage page).
 *              array('delete', ...)  ->  only these, anything else is refused by roleGate() on the server
 *
 * Janitor (4)  Can delete posts, delete / spoiler their files, approve / reject pending posts, and post
 *              (no raw HTML, not into locked threads). Cannot ban, sticky, lock, move or open any other page.
 * Fake    (5)  Can only post, with no form options. Posts carry the capcode set for this role below.
 *              No moderation tools, no raw HTML, cannot reply to locked threads.
 *
 * The post form has no capcode dropdown: a post always gets the capcode of the poster's role.
 */

if (!defined('SCHAN_JANITOR')) {
	define('SCHAN_JANITOR', 4);
}
if (!defined('SCHAN_FAKE')) {
	define('SCHAN_FAKE', 5);
}

function roleConfig() {
	return array(

		// Super-administrator: everything, including accounts, boards, configuration, extensions, import, migration, move
		SCHAN_SUPER_ADMINISTRATOR => array(
			'label' => __('Super-administrator'),
			'capcode' => array('name' => 'Admin', 'color' => 'red'),
			'allowed' => array('manage', 'board', 'moderate', 'staffpost', 'dashboard', 'mthread', 'mpage', 'tpage', 'changepassword', 'logout', 'delete', 'deletefile', 'spoiler', 'setspoiler', 'approve', 'approvemedia', 'approvelinks', 'pending', 'pendingreturn', 'placeholder', 'edit', 'sticky', 'setsticky', 'lock', 'setlock', 'trustip', 'settrust', 'bans', 'lift', 'keywords', 'deletekeyword', 'reports', 'clearreports', 'countries', 'modlog', 'pages', 'pageedit', 'pagenew', 'home', 'emoji', 'rebuildall', 'current', 'move', 'global', 'accounts', 'boards', 'new', 'config', 'extensions', 'import', 'dbmigrate', 'go'),
		),

		// Administrator: all moderation and site tools, no accounts / boards / configuration / extensions / import / migration / move
		SCHAN_ADMINISTRATOR => array(
			'label' => __('Administrator'),
			'capcode' => array('name' => 'Admin', 'color' => 'red'),
			'allowed' => array('manage', 'board', 'moderate', 'staffpost', 'dashboard', 'mthread', 'mpage', 'tpage', 'changepassword', 'logout', 'delete', 'deletefile', 'spoiler', 'setspoiler', 'approve', 'approvemedia', 'approvelinks', 'pending', 'pendingreturn', 'placeholder', 'edit', 'sticky', 'setsticky', 'lock', 'setlock', 'trustip', 'settrust', 'bans', 'lift', 'keywords', 'deletekeyword', 'reports', 'clearreports', 'countries', 'modlog', 'pages', 'pageedit', 'pagenew', 'home', 'emoji', 'rebuildall', 'current'),
		),

		// Moderator: same actions as Administrator
		SCHAN_MODERATOR => array(
			'label' => __('Moderator'),
			'capcode' => array('name' => 'Mod', 'color' => 'purple'),
			'allowed' => array('manage', 'board', 'moderate', 'staffpost', 'dashboard', 'mthread', 'mpage', 'tpage', 'changepassword', 'logout', 'delete', 'deletefile', 'spoiler', 'setspoiler', 'approve', 'approvemedia', 'approvelinks', 'pending', 'pendingreturn', 'placeholder', 'edit', 'sticky', 'setsticky', 'lock', 'setlock', 'trustip', 'settrust', 'bans', 'lift', 'keywords', 'deletekeyword', 'reports', 'clearreports', 'countries', 'modlog', 'pages', 'pageedit', 'pagenew', 'home', 'emoji', 'rebuildall', 'current'),
		),

		// Janitor: delete posts / files, spoiler files, approve or reject pending posts, post without capcode
		SCHAN_JANITOR => array(
			'label' => __('Janitor'),
			'capcode' => null,
			'allowed' => array('manage', 'board', 'moderate', 'staffpost', 'dashboard', 'delete', 'deletefile', 'spoiler', 'setspoiler', 'approve', 'approvemedia', 'approvelinks', 'pending', 'pendingreturn', 'tpage', 'mthread', 'mpage', 'changepassword', 'logout'),
		),

		// Fake: can only post, always with this capcode
		SCHAN_FAKE => array(
			'label' => __('Fake'),
			'capcode' => array('name' => 'Admin', 'color' => 'red'),
			'allowed' => array('manage', 'board', 'staffpost', 'dashboard', 'mthread', 'mpage', 'changepassword', 'logout'),
		),
	);
}

function roleOf($account) {
	return (is_array($account) && isset($account['role'])) ? intval($account['role']) : -1;
}

function roleSettings($account) {
	$all = roleConfig();
	$role = roleOf($account);
	return isset($all[$role]) ? $all[$role] : null;
}

function roleIsJanitor($account) {
	return roleOf($account) === SCHAN_JANITOR;
}

function roleIsFake($account) {
	return roleOf($account) === SCHAN_FAKE;
}

function roleLabel($role) {
	$all = roleConfig();
	$role = intval($role);
	return isset($all[$role]) ? $all[$role]['label'] : '';
}

// Refuses any manage request that the account's role is not allowed to make.
// Every manage action is chosen by a GET parameter, so allowing only certain parameters is enough.
function roleGate($account) {
	$cfg = roleSettings($account);
	if ($cfg === null || $cfg['allowed'] === null) {
		return;
	}
	$allowed = $cfg['allowed'];
	// the CSRF token travels as an extra GET parameter on every mod button, it is not an action
	// so let it through (found by asking the token helper what it appends, whatever it is called)
	if (function_exists('manageCsrfAppendToURL')) {
		$probe = html_entity_decode((string)manageCsrfAppendToURL('?x=1', '&'), ENT_QUOTES, 'UTF-8');
		$qpos = strpos($probe, '?');
		if ($qpos !== false) {
			parse_str(substr($probe, $qpos + 1), $probe_params);
			foreach (array_keys($probe_params) as $k) {
				if ($k !== 'x') {
					$allowed[] = (string)$k;
				}
			}
		}
	}
	foreach (array_keys($_GET) as $key) {
		if (!in_array($key, $allowed, true)) {
			fancyDie(__('Access denied.'));
		}
	}
}

function roleColorOK($color) {
	return (bool)preg_match('/^(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|[a-zA-Z]{3,20})$/', (string)$color);
}

// The capcode HTML for a staff post by this account: '' means "no capcode".
// Returns null if the role is not configured, so the caller can fall back to the old behaviour.
function roleCustomCapcode($account) {
	$cfg = roleSettings($account);
	if ($cfg === null) {
		return null;
	}
	$c = $cfg['capcode'];
	if (!is_array($c) || !isset($c['name']) || $c['name'] === '') {
		return '';
	}
	$color = (isset($c['color']) && roleColorOK($c['color'])) ? $c['color'] : 'red';
	return ' <span style="color: ' . $color . ';">## ' . htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') . '</span>';
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
