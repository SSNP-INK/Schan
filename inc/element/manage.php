<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// manage panel pages, loaded by html.php

function adminBar() {
	global $account, $loggedin, $isadmin, $returnlink;

	$bq = 'board=' . urlencode(current_board());

	$return = '[<a href="' . $returnlink . '" style="text-decoration: underline;">' . __('Return') . '</a>]';
	if (!$loggedin) {
		return $return;
	}

	if (function_exists('roleIsFake') && roleIsFake($account)) {
		return '[<a href="?' . $bq . '&dashboard">' . __('Dashboard') . '</a>] [<a href="?' . $bq . '&staffpost">' . __('Post') . '</a>] &middot; [<a href="?' . $bq . '&changepassword">' . __('Change Password') . '</a>] [<a href="?logout">' . __('Log Out') . '</a>] &middot; ' . $return;
	}

	// dashboard has all the links, every other manage page gets a Return button
	if (!empty($GLOBALS['schan_manage_dashboard'])) {
		return $return;
	}

	$output = manageReturnButton();

	if ($isadmin && $account['role'] == SCHAN_SUPER_ADMINISTRATOR && function_exists('allBoards') && isset($_GET['staffpost'])) {
		$boards = allBoards();
		$current = current_board();
		if (count($boards) > 1) {
			$output .= ' <select id="manageBoardSelect" onchange="if(this.value){window.location=this.value;}">';
			foreach ($boards as $b) {
				$url = '?board=' . rawurlencode($b['slug']) . '&staffpost';
				$sel = ($b['slug'] === $current) ? ' selected' : '';
				$output .= '<option value="' . htmlentities($url, ENT_QUOTES) . '"' . $sel . '>' . htmlentities($b['slug']) . '</option>';
			}
			$output .= '</select>';
		}
	}

	$back = current_board() !== '' ? htmlentities(modBoardURL(current_board()), ENT_QUOTES) : $returnlink;
	$output .= ' &middot; [<a href="' . $back . '" style="text-decoration: underline;">' . __('Back to board') . '</a>]';
	return $output;
}

// Return button, goes back to the dashboard
function manageReturnButton() {
	$url = '?board=' . urlencode(current_board()) . '&dashboard';
	return '<a href="' . htmlentities($url, ENT_QUOTES) . '" class="managebutton" style="display:inline-block;text-decoration:none;">' . __('Return') . '</a>';
}

// dashboard, every manage page grouped
// accounts keep their boards in the board column: '*' (all) or slugs separated by commas, e.g. 'b,g,v'
function accountBoardList($account) {
	$raw = isset($account['board']) ? trim((string)$account['board']) : '';
	if ($raw === '' || $raw === '*') {
		return array('*');
	}
	$out = array();
	foreach (explode(',', $raw) as $slug) {
		$slug = trim($slug);
		if ($slug === '*') {
			return array('*');
		}
		if ($slug !== '' && !in_array($slug, $out, true)) {
			$out[] = $slug;
		}
	}
	return $out ? $out : array('*');
}

function accountCanBoard($account, $slug) {
	$list = accountBoardList($account);
	return $list[0] === '*' || in_array($slug, $list, true);
}

function manageDashboard() {
	global $account, $isadmin;

	$e = function ($s) {
		return htmlentities((string) $s, ENT_QUOTES);
	};
	$is_super = isset($account['role']) && $account['role'] == SCHAN_SUPER_ADMINISTRATOR;
	$current = current_board();
	$bq = 'board=' . urlencode($current);

	$boards = array();
	foreach (allBoards() as $b) {
		if ($is_super || accountCanBoard($account, $b['slug'])) {
			$boards[] = $b;
		}
	}

	// open reports per board
	$reports = array();
	$report_total = 0;
	$report_board = '';
	if (SCHAN_REPORT && $isadmin) {
		$saved = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
		foreach ($boards as $b) {
			$GLOBALS['schan_forced_board'] = $b['slug'];
			$n = count(allReports());
			$reports[$b['slug']] = $n;
			$report_total += $n;
			if ($n > 0 && $report_board === '') {
				$report_board = $b['slug'];
			}
		}
		$GLOBALS['schan_forced_board'] = $saved;
	}

	// boards
	$out = '<fieldset><legend>' . __('Boards') . '</legend><ul>';
	foreach ($boards as $b) {
		$slug = $b['slug'];
		$sq = 'board=' . urlencode($slug);
		$out .= '<li><a href="' . $e(modBoardURL($slug)) . '">/' . $e($slug) . '/</a>'
			. ($b['title'] !== '' ? ' - ' . $e($b['title']) : '')
			. (!empty($b['locked']) ? ' <small>(' . __('locked') . ')</small>' : '');
		$links = array();
		if ($isadmin) {
			if ($is_super) {
				$links[] = '<a href="?' . $sq . '&boards=' . intval($b['id']) . '">' . __('edit') . '</a>';
			}
			$links[] = '<a href="?' . $sq . '&bans">' . __('bans') . '</a>';
			if (SCHAN_REPORT) {
				$links[] = '<a href="?' . $sq . '&reports">' . __('reports') . ' (' . intval($reports[$slug]) . ')</a>';
			}
		}
		$out .= ' <small>[' . implode('] [', $links) . ']</small></li>';
	}
	if (empty($boards)) {
		$out .= '<li><i>' . __('No boards yet.') . '</i></li>';
	}
	if ($is_super) {
		$out .= '<br><li><a href="?' . $bq . '&boards&new"><b>' . __('Create new board') . '</b></a></li>';
	}
	$out .= '</ul></fieldset><br>';

	// admin
	if ($isadmin) {
		$rb = ($report_board !== '') ? $report_board : ($current !== '' ? $current : (isset($boards[0]) ? $boards[0]['slug'] : ''));
		$items = array();
		if (SCHAN_REPORT) {
			$items[] = '<a href="?board=' . urlencode($rb) . '&reports">' . __('Report queue') . ' (' . $report_total . ')</a>';
		}
		if (function_exists('pendingCount') && extensionEnabled('pending') && (reqmodMediaEnabled() || reqmodLinksEnabled() || pendingCount() > 0)) {
			$items[] = '<a href="?' . $bq . '&pending">' . __('Pending approval') . ' (' . pendingCount() . ')</a>';
		}
		$items[] = '<a href="?' . $bq . '&bans">' . __('Ban list') . '</a>';
		$items[] = '<a href="?' . $bq . '&keywords">' . __('Keywords') . '</a>';
		if (countriesAvailable()) {
			$items[] = '<a href="?' . $bq . '&countries">' . __('Countries') . '</a>';
		}
		if ($is_super) {
			$items[] = '<a href="?' . $bq . '&accounts">' . __('Manage users') . '</a>';
		}
		$items[] = '<a href="?' . $bq . '&modlog">' . __('Moderation log') . '</a>';
		$items[] = '<a href="?' . $bq . '&pages">' . __('Static pages') . '</a>';
		if (extensionEnabled('home')) {
			$items[] = '<a href="?' . $bq . '&home">' . __('Home page') . '</a>';
		}
		if (function_exists('manageEmojiPage') && extensionEnabled('emoji')) {
			$items[] = '<a href="?' . $bq . '&emoji">' . __('Emoji') . '</a>';
		}
		$items[] = '<a href="' . (function_exists('manageCsrfAppendToURL') ? manageCsrfAppendToURL('?' . $bq . '&rebuildall', '&') : '?' . $bq . '&rebuildall') . '">' . __('Rebuild') . '</a>';
		if ($is_super) {
			$items[] = '<a href="?' . $bq . '&extensions">' . __('Extensions') . '</a>';
			$items[] = '<a href="?' . $bq . '&config">' . __('Configuration') . '</a>';
			$items[] = '<a href="?' . $bq . '&import">' . __('Import a vichan / Tinyib database') . '</a>';
			if (SCHAN_DBMIGRATE) {
				$items[] = '<a href="?' . $bq . '&dbmigrate"><b>' . __('Migrate Database') . '</b></a>';
			}
		}
		$out .= '<fieldset><legend>' . __('Administration') . '</legend><ul><li>' . implode('</li><li>', $items) . '</li></ul></fieldset><br>';
	}

	// janitors: approval queue
	if (!$isadmin && function_exists('roleIsJanitor') && roleIsJanitor($account) && function_exists('pendingCount') && extensionEnabled('pending')) {
		$out .= '<fieldset><legend>' . __('Moderation') . '</legend><ul><li><a href="?' . $bq . '&pending">' . __('Pending approval') . ' (' . pendingCount() . ')</a></li></ul></fieldset><br>';
	}

	// search
	if (!empty($boards) && function_exists('searchPage') && extensionEnabled('search')) {
		$opts = '';
		foreach ($boards as $b) {
			$opts .= '<option value="' . $e($b['slug']) . '"' . ($b['slug'] === $current ? ' selected' : '') . '>/' . $e($b['slug']) . '/</option>';
		}
		$out .= '<fieldset><legend>' . __('Search') . '</legend><ul><li>'
			. '<form action="search.php" method="get" style="display:inline;">' . __('Phrase') . ': '
			. '<input type="text" name="search" size="60" maxlength="200"> '
			. '<select name="board">' . $opts . '</select> '
			. '<input type="submit" value="' . __('Search') . '" class="managebutton"></form>'
			. '</li></ul></fieldset><br>';
	}

	// account
	$out .= '<fieldset><legend>' . __('User account') . '</legend><ul>'
		. '<li>' . __('Logged in as') . ' <b>' . $e($account['username']) . '</b></li>'
		. '<li><a href="?' . $bq . '&changepassword">' . __('Change Password') . '</a></li>'
		. '<li><a href="?logout">' . __('Log Out') . '</a></li>'
		. '</ul></fieldset><br>';

	return $out;
}

function managePage($text, $onload = '') {
	$adminbar = adminBar();
	$txt_manage_mode = __('Manage mode');

	$switcher = boardSwitcher();

	$body = <<<EOF
	<body$onload>
		$switcher
		<div class="adminbar">
			$adminbar
		</div>
		<div class="logo">
EOF;
	$body .= bannerHTML() . <<<EOF
		</div>
		<hr width="90%">
		<div class="replymode">$txt_manage_mode</div>
		$text
EOF;
	global $loggedin;
	if (!empty($loggedin) && empty($GLOBALS['schan_manage_dashboard']) && !(function_exists('roleIsFake') && isset($GLOBALS['account']) && roleIsFake($GLOBALS['account']))) {
		$body .= '<div style="margin:8px 0;">' . manageReturnButton() . '</div>';
	}
	$body .= '<hr>';
	return pageHeader() . $body . manageQuickActionsJS() . pageFooter();
}

// delete / sticky / approve etc run in the background and the page reloads in place
// (no "Deleted ..." page). EDIT and B open forms so those still navigate. scroll position is kept
function manageQuickActionsJS() {
	return <<<'JS'
<script type="text/javascript">
(function(){
	var QUICK=['delete','deletefile','spoiler','approve','approvemedia','approvelinks','trustip','sticky','lock','clearreports','move'];
	try{
		var y=sessionStorage.getItem('schanScrollY');
		if(y!==null){sessionStorage.removeItem('schanScrollY');window.addEventListener('load',function(){window.scrollTo(0,parseInt(y,10)||0);});}
	}catch(e){}
	function isQuick(a){
		var q=(a.getAttribute('href')||'').split('?')[1]||'',parts=q.split('&');
		for(var i=0;i<parts.length;i++){
			var k=decodeURIComponent(parts[i].split('=')[0].replace(/^amp;/,''));
			if(QUICK.indexOf(k)!==-1){return true;}
		}
		return false;
	}
	document.addEventListener('click',function(e){
		if(e.defaultPrevented||e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey){return;}
		var a=e.target;
		while(a&&a.nodeName!=='A'){a=a.parentNode;}
		if(!a||!a.classList||!a.classList.contains('managebtn')||!isQuick(a)||!window.fetch){return;}
		e.preventDefault();
		var href=a.href;
		a.style.opacity='.5';
		fetch(href,{credentials:'same-origin'}).then(function(r){
			try{sessionStorage.setItem('schanScrollY',String(window.pageYOffset||0));}catch(e2){}
			window.location.replace(window.location.href);
		}).catch(function(){window.location.href=href;});
	});
})();
</script>
JS;
}

function manageOnLoad($page) {
	switch ($page) {
		case 'accounts':
		case 'login':
			return ' onload="document.schan.username.focus();"';
		case 'bans':
			return ' onload="document.schan.ip.focus();"';
		case 'keywords':
			return ' onload="document.schan.text.focus();"';
		case 'staffpost':
			return ' onload="document.schan.message.focus();"';
		case 'boards':
			return ' onload="document.schan.slug.focus();"';
		case 'countries':
			return ' onload="document.schan.codes.focus();"';
	}
}

function manageLogInForm() {
	$txt_login = __('Log In');
	$txt_login_prompt = __('Enter a username and password');
	$captcha_inner_html = '';
	if (SCHAN_MANAGECAPTCHA === 'hcaptcha') {
		$captcha_inner_html = '
<br>
<div style="min-height: 82px;">
	<div class="h-captcha" data-sitekey="' . SCHAN_HCAPTCHA_SITE . '"></div>
</div><br><br>';
	} else if (SCHAN_MANAGECAPTCHA === 'recaptcha') {
		$captcha_inner_html = '
<br>
<div style="min-height: 80px;">
	<div class="g-recaptcha" data-sitekey="' . SCHAN_RECAPTCHA_SITE . '"></div>
	<noscript>
		<div>
			<div style="width: 302px; height: 422px; position: relative;">
				<div style="width: 302px; height: 422px; position: absolute;">
					<iframe src="https://www.google.com/recaptcha/api/fallback?k=' . SCHAN_RECAPTCHA_SITE . '" frameborder="0" scrolling="no" style="width: 302px; height:422px; border-style: none;"></iframe>
				</div>
			</div>
			<div style="width: 300px; height: 60px; border-style: none;bottom: 12px; left: 25px; margin: 0px; padding: 0px; right: 25px;background: #f9f9f9; border: 1px solid #c1c1c1; border-radius: 3px;">
				<textarea id="g-recaptcha-response" name="g-recaptcha-response" class="g-recaptcha-response" style="width: 250px; height: 40px; border: 1px solid #c1c1c1; margin: 10px 25px; padding: 0px; resize: none;"></textarea>
			</div>
		</div>
	</noscript>
</div><br><br>';
	} else if (SCHAN_MANAGECAPTCHA) {
		$captcha_inner_html = '
<br>
<input type="text" name="captcha" id="captcha" size="6" accesskey="c" autocomplete="off">&nbsp;&nbsp;' . __('(enter the text below)') . '<br>
<img id="captchaimage" src="' . installRootURL() . '/inc/captcha.php" width="175" height="55" alt="CAPTCHA" onclick="javascript:reloadCAPTCHA()" style="margin-top: 5px;cursor: pointer;"><br><br>';
	}
	$managekey = isset($_GET['manage']) ? htmlentities($_GET['manage'], ENT_QUOTES) : '';
	// post back to the current address, only a manage key typed in the url needs carrying over
	$manageaction = $managekey === '' ? '' : '?manage=' . $managekey;
	return <<<EOF
	<form id="schan" name="schan" method="post" action="$manageaction">
	<fieldset>
	<legend align="center">$txt_login_prompt</legend>
	<div class="login">
	<input type="text" id="username" name="username" placeholder="Username"><br>
	<input type="password" id="managepassword" name="managepassword" placeholder="Password"><br>
	$captcha_inner_html
	<input type="submit" value="$txt_login" class="managebutton">
	</div>
	</fieldset>
	</form>
	<br>
EOF;
}

function manageModerationLog($offset) {
	$offset = intval($offset);
	$limit = 50;

	$logs = array();
	$previous = current_board();
	foreach (manageVisibleBoards() as $b) {
		$GLOBALS['schan_forced_board'] = $b['slug'];
		$chunk = getLogs($offset, $limit);
		foreach ($chunk as $row) {
			$row['board'] = $b['slug'];
			$logs[] = $row;
		}
	}
	$GLOBALS['schan_forced_board'] = $previous;

	usort($logs, function ($a, $b) {
		return $b['timestamp'] - $a['timestamp'];
	});
	$logs = array_slice($logs, 0, $limit);

	$u = array();
	$text = '';
	foreach ($logs as $log) {
		if (!isset($u[$log['account']])) {
			$username = '';
			if ($log['account'] > 0) {
				$a = accountByID($log['account']);
				if (!empty($a)) {
					$username = $a['username'];
				}
			}
			$u[$log['account']] = $username;
		}
		$board_tag = isset($log['board']) && $log['board'] !== '' ? '<span class="boardtag">[' . htmlentities($log['board']) . ']</span> ' : '';
		$text .= '<tr><td>' . formatDate($log['timestamp']) . '</td><td>' . htmlentities($u[$log['account']]) . '</td><td>' . $board_tag . $log['message'] . '</td></tr>';
	}

	if ($text == '') {
		$text = '<i>' . __('No logs.') . '</i>';
	}

	$txt_moderation_log = __('Moderation log');
	$nav = '';
	if ($offset > 0) {
		$nav .= '<a href="?modlog=' . ($offset - $limit) . '">Previous ' . $limit . '</a> ';
	}
	if (count($logs) == $limit) {
		$nav .= '<a href="?modlog=' . ($offset + $limit) . '">Next ' . $limit . '</a> ';
	}
	$nav_top = '';
	$nav_bottom = '';
	if ($nav != '') {
		$nav_top = $nav . '<br><br>';
		$nav_bottom = '<br><br>' . $nav;
	}
	return <<<EOF
		$nav_top
		<fieldset>
		<legend>$txt_moderation_log</legend>
		<table border="0" cellspacing="0" cellpadding="0" width="100%">
		<tr><th align="left">Date/time</th><th align="left">Account</th><th align="left">Action</th></tr>
		$text
		</table>
		</fieldset>
		$nav_bottom
EOF;
}

// text mod link with a [label]
function manageTextLink($params, $label, $disabled = false) {
	$text = '[' . $label . ']';
	if ($disabled) {
		return '<span class="managebtn managebtn-disabled" style="margin:0 6px 0 0;opacity:.5;">' . htmlentities($text) . '</span>';
	}
	$url = (defined('SCHAN_MODVIEW') ? modBoardURL(isset($params['board']) ? $params['board'] : current_board()) : '') . '?';
	$sep = '';
	foreach ($params as $k => $v) {
		$url .= $sep . rawurlencode($k) . '=' . rawurlencode($v);
		$sep = '&amp;';
	}
	if (function_exists('manageCsrfAppendToURL')) {
		$url = manageCsrfAppendToURL($url, $sep);
	}
	return '<a href="' . $url . '" class="managebtn" style="margin:0 6px 0 0;text-decoration:none;">' . htmlentities($text) . '</a>';
}

function manageInlineButton($params, $label, $disabled = false) {
	return manageTextLink($params, $label, $disabled);
}

function manageEditPostForm($post) {
	$board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	$id = intval($post['id']);
	$message = preg_replace('#<br\s*/?>\n?#i', "\n", (string)$post['message']);
	$action = '?edit=' . $id . '&amp;board=' . rawurlencode($board);

	$out = '<fieldset><legend>' . sprintf(__('Edit post %s'), '&gt;&gt;' . $id) . '</legend>';
	$out .= buildPost($post, SCHAN_INDEXPAGE, false, true);
	$out .= '<form method="post" action="' . $action . '" style="margin-top:8px;">';
	$ep = function_exists('postEditsApply') ? postEditsApply($post) : $post;
	$cur_name = html_entity_decode((string)$ep['name'], ENT_QUOTES, 'UTF-8');
	$cur_subject = html_entity_decode((string)$ep['subject'], ENT_QUOTES, 'UTF-8');
	$out .= '<div style="margin-bottom:6px;"><b>' . __('Name') . '</b> <input type="text" name="name" value="' . htmlspecialchars($cur_name, ENT_QUOTES) . '" size="30" maxlength="' . intval(SCHAN_MAXNAME) . '"> '
		. '<b>' . __('Subject') . '</b> <input type="text" name="subject" value="' . htmlspecialchars($cur_subject, ENT_QUOTES) . '" size="40" maxlength="' . intval(SCHAN_MAXSUBJECT) . '"></div>';
	$out .= '<div style="margin-bottom:4px;"><b>' . __('Message') . '</b> <small>(' . __('stored HTML: line breaks are shown as new lines') . ')</small></div>';
	$out .= '<textarea name="message" rows="14" style="width:100%;box-sizing:border-box;font-family:monospace;">' . htmlspecialchars($message, ENT_QUOTES) . '</textarea><br>';
	$out .= '<label><input type="checkbox" name="editednote" value="1"> ' . __('Add an "(edited by staff)" note') . '</label><br><br>';
	$out .= '<input type="submit" value="' . __('Save') . '" class="managebutton"> ';
	$out .= '<a href="?board=' . rawurlencode($board) . '">[' . __('Cancel') . ']</a>';
	$out .= '</form></fieldset>';
	return $out;
}

// MOVE as a text link, clicking cycles to the next board
// target goes in the url so the handler in imgboard.php gets it
function manageMoveControl($post, $compact = false) {
	global $account, $isadmin;

	if (!$isadmin) {
		return '';
	}
	if (!isset($account['role']) || $account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
		return '';
	}
	if ($post['parent'] != SCHAN_NEWTHREAD) {
		return '';
	}

	$source = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	$boards = allBoards();
	if (count($boards) < 2) {
		return '';
	}

	// every board except this one
	$targets = array();
	foreach ($boards as $b) {
		if ($b['slug'] === $source) {
			continue;
		}
		$targets[] = $b['slug'];
	}
	if (empty($targets)) {
		return '';
	}

	// one [MOVE] link per destination, labelled with its slug
	$out = '';
	foreach ($targets as $dest) {
		$out .= '<a href="' . (defined('SCHAN_MODVIEW') ? htmlentities(modBoardURL($source), ENT_QUOTES) : '') . '?move=' . intval($post['id'])
			. '&amp;from=' . rawurlencode($source)
			. '&amp;to=' . rawurlencode($dest)
			. '" class="managebtn" style="margin:0 6px 0 0;text-decoration:none;"'
			. ' onclick="return confirm(\'' . __('Move this thread to the selected board?') . '\');">[MOVE:' . htmlentities($dest) . ']</a>';
	}
	return $out;
}

function manageInlineModeration($post) {
	global $isadmin;
	$id = $post['id'];
	$post_board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	$btn = function ($params, $label, $disabled = false) use ($post_board) {
		return manageTextLink(array_merge(array('board' => $post_board), $params), $label, $disabled);
	};
	if (function_exists('roleIsFake') && roleIsFake(isset($GLOBALS['account']) ? $GLOBALS['account'] : array())) {
		return '';
	}
	$out = '<div class="inlinemod" style="margin:4px 0 8px 0;">';

	if (function_exists('roleIsJanitor') && roleIsJanitor(isset($GLOBALS['account']) ? $GLOBALS['account'] : array())) {
		if (isset($post['moderated']) && $post['moderated'] == 0) {
			$out .= $btn(array('approve' => $id), 'APPROVE');
			$out .= $btn(array('delete' => $id), 'REJECT');
		}
		if (function_exists('pendingGet')) {
			$pend = pendingGet($id);
			if (!empty($pend['media'])) {
				$out .= $btn(array('approvemedia' => $id), 'APPROVE MEDIA');
			}
			if (!empty($pend['links'])) {
				$out .= $btn(array('approvelinks' => $id), 'APPROVE LINKS');
			}
		}
		$out .= $btn(array('delete' => $id), 'D');
		if ($post['file'] != '') {
			$out .= $btn(array('deletefile' => $id), 'F');
			if (function_exists('spoilerIs') && function_exists('spoilerSet')) {
				$out .= $btn(array('spoiler' => $id, 'setspoiler' => 1), 'S');
			}
		} else if (function_exists('mediaExtrasFor') && !empty(mediaExtrasFor($post_board, $id))) {
			$out .= $btn(array('deletefile' => $id), 'F');
		}
		return $out . '</div>';
	}

	$ban = banByIP($post['ip']);

	if (isset($post['moderated']) && $post['moderated'] == 0) {
		$out .= $btn(array('approve' => $id), 'APPROVE');
	}

	if (function_exists('pendingGet')) {
		$pend = pendingGet($id);
		if (!empty($pend['media'])) {
			$out .= $btn(array('approvemedia' => $id), 'APPROVE MEDIA');
		}
		if (!empty($pend['links'])) {
			$out .= $btn(array('approvelinks' => $id), 'APPROVE LINKS');
		}
	}

	$out .= $btn(array('delete' => $id), 'D');

	if ($post['file'] != '') {
		$out .= $btn(array('deletefile' => $id), 'F');
		if (function_exists('spoilerIs') && function_exists('spoilerSet')) {
			$out .= $btn(array('spoiler' => $id, 'setspoiler' => 1), 'S');
		}
	}

	if (!(function_exists('roleIsFake') && roleIsFake(isset($GLOBALS['account']) ? $GLOBALS['account'] : array()))) {
		$out .= $btn(array('edit' => $id), 'EDIT');
	}

	$out .= $btn(array('bans' => $post['ip'], 'posts' => $id), 'B', ($ban || !$isadmin));

	if (function_exists('trustedIPCheck') && extensionEnabled('trust')) {
		$out .= $btn(array('trustip' => $id, 'settrust' => 1), 'WHITELIST');
	}

	if ($post['parent'] == SCHAN_NEWTHREAD) {
		$out .= $btn(array('sticky' => $id, 'setsticky' => 1), 'STICKY');
		$out .= $btn(array('lock' => $id, 'setlock' => 1), 'LOCK');
	}

	if (SCHAN_REPORT) {
		$reports = reportsByPost($id);
		if (count($reports) > 0) {
			$out .= $btn(array('clearreports' => $id), 'APPROVE (' . count($reports) . ')');
		}
	}

	$out .= manageMoveControl($post);

	return $out . '</div>';
}

function manageReportsPage($ip) {
	$posts = array();
	$report_counts = array();
	$previous = current_board();

	foreach (manageVisibleBoards() as $b) {
		$GLOBALS['schan_forced_board'] = $b['slug'];
		$reports = allReports();
		foreach ($reports as $report) {
			if ($ip != '' && $report['ip'] != $ip && $report['ip'] != hashData($ip)) {
				continue;
			}
			$post = postByID($report['post']);
			if (empty($post)) {
				continue;
			}
			$post['board'] = $b['slug'];
			$post['show_boardtag'] = true;
			if ($ip == '') {
				$post['reportedby'] = $report['ip'];
				if (!isset($report_counts[$report['ip']])) {
					$report_counts[$report['ip']] = 0;
				}
				$report_counts[$report['ip']]++;
			}
			$posts[] = $post;
		}
	}
	$GLOBALS['schan_forced_board'] = $previous;

	$txt_reported = __('Reported posts');
	if ($ip != '') {
		if (count($posts) == 1) {
			$format = __('%1$d report by %2$s');
		} else {
			$format = __('%1$d reports by %2$s');
		}
		$txt_reported = sprintf($format, count($posts), '<a href="?bans=' . htmlentities($ip, ENT_QUOTES) . '">' . htmlentities($ip) . '</a>');
	}

	$post_html = '';
	foreach ($posts as $post) {
		if ($post_html != '') {
			$post_html .= '<tr><td colspan="2"><hr></td></tr>';
		}

		if (isset($post['reportedby'])) {
			$reportedby_html = '<a href="?bans=' . htmlentities($post['reportedby'], ENT_QUOTES) . '">' . ipDisplayHTML($post['reportedby']) . '</a>';
			if ($report_counts[$post['reportedby']] > 1) {
				$reportedby_html .= ' <a href="?reports=' . htmlentities($post['reportedby'], ENT_QUOTES) . '">(' . sprintf(__('%d reports'), $report_counts[$post['reportedby']]) . ')</a>';
			}

			$post_html .= '<tr><td colspan=""><small>' . sprintf(__('Reported by %s'), $reportedby_html) . '</small></td></tr>';
		}

		$post_html .= '<tr><td>' . buildPost($post, SCHAN_INDEXPAGE, false, true) . manageInlineModeration($post) . '</td></tr>';
	}

	if ($post_html == '') {
		$post_html = '<i>' . __('There are currently no reported posts.') . '</i>';
	}

	return <<<EOF
		<fieldset>
		<legend>$txt_reported</legend>
		<table border="0" cellspacing="0" cellpadding="0" width="100%">
		$post_html
		</table>
		</fieldset>
EOF;
}

// pending media / links / posts from all boards, approve or reject
function managePendingPage() {
	$posts = function_exists('pendingPostsAllBoards') ? pendingPostsAllBoards(100) : array();
	$pend_acc = isset($GLOBALS['account']) ? $GLOBALS['account'] : array();
	if (!(isset($pend_acc['role']) && $pend_acc['role'] == SCHAN_SUPER_ADMINISTRATOR)) {
		$posts = array_values(array_filter($posts, function ($p) use ($pend_acc) {
			return accountCanBoard($pend_acc, $p['board']);
		}));
	}
	$e = function ($v) {
		return htmlentities((string) $v, ENT_QUOTES);
	};

	$rows = '';
	foreach ($posts as $post) {
		$id = intval($post['id']);
		$slug = $post['board'];
		$pend = $post['pending'];
		$btn = function ($params, $label, $confirm = '') use ($slug) {
			$params = array_merge(array('board' => $slug), $params);
			$url = (defined('SCHAN_MODVIEW') ? modBoardURL($slug) : '') . '?';
			$sep = '';
			foreach ($params as $k => $v) {
				$url .= $sep . rawurlencode($k) . '=' . rawurlencode($v);
				$sep = '&amp;';
			}
			if (function_exists('manageCsrfAppendToURL')) {
				$url = manageCsrfAppendToURL($url, $sep);
			}
			return '<a href="' . $url . '" class="managebtn" style="margin:0 6px 0 0;text-decoration:none;"'
				. ($confirm !== '' ? ' onclick="return confirm(\'' . $confirm . '\');"' : '') . '>[' . $label . ']</a>';
		};

		$what = array();
		if (!empty($pend['media'])) {
			$what[] = __('media');
		}
		if (!empty($pend['links'])) {
			$what[] = __('links');
		}
		if (isset($post['moderated']) && $post['moderated'] == 0) {
			$what[] = __('post');
		}

		$actions = '';
		if (!empty($pend['media'])) {
			$actions .= $btn(array('pendingreturn' => 1, 'approvemedia' => $id), __('APPROVE MEDIA'));
		}
		if (!empty($pend['links'])) {
			$actions .= $btn(array('pendingreturn' => 1, 'approvelinks' => $id), __('APPROVE LINKS'));
		}
		if (isset($post['moderated']) && $post['moderated'] == 0) {
			$actions .= $btn(array('pendingreturn' => 1, 'approve' => $id), __('APPROVE POST'));
		}
		if (!empty($pend['media']) && !empty($pend['links'])) {
			$actions .= $btn(array('pendingreturn' => 1, 'approve' => $id), __('APPROVE ALL'));
		}
		$actions .= $btn(array('pendingreturn' => 1, 'delete' => $id), __('REJECT'), __('Reject and delete this post?'));

		$rows .= '<tr><td><small>/' . $e($slug) . '/ No.' . $id . ' &mdash; ' . __('waiting for') . ': <b>' . $e(implode(', ', $what)) . '</b>'
			. (!empty($pend['time']) ? ' &mdash; ' . $e(formatDate($pend['time'])) : '') . '</small></td></tr>'
			. '<tr><td>' . buildPost($post, SCHAN_INDEXPAGE, false, true)
			. '<div class="inlinemod" style="margin:4px 0 8px 0;">' . $actions . '</div></td></tr>'
			. '<tr><td><hr></td></tr>';
	}

	if ($rows == '') {
		$rows = '<tr><td><i>' . __('Nothing is waiting for approval.') . '</i></td></tr>';
	}

	return '<fieldset><legend>' . __('Pending approval') . ' (' . count($posts) . ')</legend>'
		. '<p><small>' . __('Posts from every board that have media, links or the post itself waiting for approval. Approve shows it to everyone. Reject deletes the whole post.') . '</small></p>'
		. '<table border="0" cellspacing="0" cellpadding="0" width="100%">' . $rows . '</table></fieldset>';
}

function manageChangePasswordForm() {
	$txt_header = __('Change Password');
	$txt_submit = __('Submit');
	return <<<EOF
	<form id="schan" name="schan" method="post" action="?changepassword">
	<fieldset>
	<legend>$txt_header</legend>
	<table border="0">
	<tr><td>New password</td><td><input type="password" name="password" id="password" value=""></td></tr>
	<tr><td>Confirm</td><td><input type="password" name="confirm" id="confirm" value=""></td></tr>
	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
	</table>
	<legend>
	</fieldset>
	</form><br>
EOF;
}

function manageAccountForm($id = 0) {
	$a = array(
		'id' => 0,
		'username' => '',
		'password' => '',
		'role' => 0,
		'board' => '*',
	);
	$txt_header = __('Add an account');
	$txt_password_hint = '';
	if ($id > 0) {
		$txt_header = __('Update an account');
		$txt_password_hint = '(' . __('Leave blank to maintain current password') . ')';
		$a = accountByID($id);
		if (!isset($a['board']) || $a['board'] === '') {
			$a['board'] = '*';
		}
	}
	$chosen = accountBoardList($a);
	$all_chosen = ($chosen[0] === '*');

	$a['id'] = htmlentities($a['id'], ENT_QUOTES);
	$a['username'] = htmlentities($a['username'], ENT_QUOTES);

	$txt_username = __('Username');
	$txt_password = __('Password');
	$txt_role = __('Role');
	$txt_board = __('Board');
	$return = <<<EOF
	<form id="schan" name="schan" method="post" action="?accounts">
	<input type="hidden" name="id" value="{$a['id']}">
	<fieldset>
	<legend>$txt_header</legend>
	<table border="0">
	<tr><td><label for="username">$txt_username</label></td><td><input type="text" name="username" id="username" value="{$a['username']}"></td></tr>
	<tr><td><label for="password">$txt_password</label></td><td><input type="password" name="password" id="password" value=""> <small>$txt_password_hint</small></td></tr>
	<tr><td><label for="role">$txt_role</label></td><td><select name="role" id="role">
EOF;
	$return .= '<option value="0" ' . ($a['role'] == 0 ? ' selected' : '') . '>' . __('Choose a role') . '</option>';
	$return .= '<option value="1" ' . ($a['role'] == 1 ? ' selected' : '') . '>' . __('Super-administrator') . '</option>';
	$return .= '<option value="2" ' . ($a['role'] == 2 ? ' selected' : '') . '>' . __('Administrator') . '</option>';
	$return .= '<option value="3" ' . ($a['role'] == 3 ? ' selected' : '') . '>' . __('Moderator') . '</option>';
	$return .= '<option value="' . SCHAN_JANITOR . '" ' . ($a['role'] == SCHAN_JANITOR ? ' selected' : '') . '>' . __('Janitor') . '</option>';
	$return .= '<option value="' . SCHAN_FAKE . '" ' . ($a['role'] == SCHAN_FAKE ? ' selected' : '') . '>' . __('Fake') . '</option>';
	$return .= '<option value="99" ' . ($a['role'] == 99 ? ' selected' : '') . '>' . __('Disabled') . '</option>';
	$return .= '</select></td></tr>';

	$return .= '<tr><td style="vertical-align:top;">' . $txt_board . '</td><td id="accountBoards">';
	$return .= '<label><input type="checkbox" name="account_board_all" id="account_board_all" value="1"' . ($all_chosen ? ' checked' : '') . '> <b>' . __('All boards') . '</b></label><br>';
	if (function_exists('allBoards')) {
		foreach (allBoards() as $b) {
			$on = !$all_chosen && in_array($b['slug'], $chosen, true);
			$return .= '<label style="display:inline-block;margin-right:10px;"><input type="checkbox" name="account_boards[]" class="account_board_one" value="' . htmlentities($b['slug'], ENT_QUOTES) . '"' . ($on ? ' checked' : '') . ($all_chosen ? ' disabled' : '') . '> /' . htmlentities($b['slug']) . '/</label>';
		}
	}
	$return .= '</td></tr>';
	$return .= '<script type="text/javascript">(function(){var all=document.getElementById("account_board_all");if(!all){return;}var one=document.querySelectorAll(".account_board_one");function sync(){for(var i=0;i<one.length;i++){one[i].disabled=all.checked;if(all.checked){one[i].checked=false;}}}all.addEventListener("change",sync);})();</script>';
	$txt_submit = __('Submit');
	$return .= <<<EOF
	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
	</table>
	</fieldset>
	</form><br>
EOF;
	return $return;
}

function manageAccountsTable() {
	$text = '';
	$allaccounts = allAccounts();
	if (count($allaccounts) > 0) {
		$text .= '<table border="1"><tr><th>' . __('Username') . '</th><th>' . __('Role') . '</th><th>' . __('Board') . '</th><th>' . __('Last active') . '</th><th>&nbsp;</th></tr>';
		foreach ($allaccounts as $account) {
			$lastactive = ($account['lastactive'] > 0) ? formatDate($account['lastactive']) : __('Never');
			$text .= '<tr><td>' . htmlentities($account['username']) . '</td><td>';
			switch (intval($account['role'])) {
				case SCHAN_SUPER_ADMINISTRATOR:
					$text .= __('Super-administrator');
					break;
				case SCHAN_ADMINISTRATOR:
					$text .= __('Administrator');
					break;
				case SCHAN_MODERATOR:
					$text .= __('Moderator');
					break;
				case SCHAN_JANITOR:
					$text .= __('Janitor');
					break;
				case SCHAN_FAKE:
					$text .= __('Fake');
					break;
				case SCHAN_DISABLED:
					$text .= __('Disabled');
					break;
			}
			$board_list = accountBoardList($account);
			$text .= '</td><td>' . ($board_list[0] === '*' ? __('All') : htmlentities(implode(', ', $board_list))) . '</td><td>' . $lastactive . '</td><td><a href="?accounts=' . $account['id'] . '">' . __('update') . '</a></td></tr>';
		}
		$text .= '</table>';
	}
	return $text;
}

function manageBanForm() {
	$txt_ban = __('Add a ban');
	$txt_ban_help = __('Multiple IP addresses may be banned at once by separating each address with a comma.');
	$txt_ban_ip = __('IP Address');
	$txt_ban_expire = __('Expire(sec)');
	$txt_ban_reason = __('Reason');
	$txt_ban_never = __('never');
	$txt_ban_optional = __('Optional.');
	$txt_submit = __('Submit');
	$txt_1h = __('1 hour');
	$txt_1d = __('1 day');
	$txt_2d = __('2 days');
	$txt_1w = __('1 week');
	$txt_2w = __('2 weeks');
	$txt_1m = __('1 month');
	$banmessage_html = '';
	$post_ids = '';
	if (SCHAN_BANMESSAGE && isset($_GET['posts']) && $_GET['posts'] != '') {
		$post_ids = htmlentities($_GET['posts'], ENT_QUOTES);
		$banmessage_html = '<tr><td><label for="message">' . __('Message') . '</label></td><td><input type="text" name="message" id="message"></td><td><small>' . __("Append a message to the post. Optional.") . '</small></td></tr>';
	}
	$ip = isset($_GET['bans']) ? htmlentities($_GET['bans'], ENT_QUOTES) : '';
	return <<<EOF
	<form id="schan" name="schan" method="post" action="?bans&posts=$post_ids">
	<fieldset>
	<legend>$txt_ban</legend>
	<table border="0">
	<tr><td><label for="ip">$txt_ban_ip</label></td><td><input type="text" name="ip" id="ip" value="$ip"></td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
	<tr><td><label for="expire">$txt_ban_expire</label></td><td><input type="text" name="expire" id="expire" value="0"></td><td><small><a href="#" onclick="document.schan.expire.value='3600';return false;">$txt_1h</a>&nbsp;<a href="#" onclick="document.schan.expire.value='86400';return false;">$txt_1d</a>&nbsp;<a href="#" onclick="document.schan.expire.value='172800';return false;">$txt_2d</a>&nbsp;<a href="#" onclick="document.schan.expire.value='604800';return false;">$txt_1w</a>&nbsp;<a href="#" onclick="document.schan.expire.value='1209600';return false;">$txt_2w</a>&nbsp;<a href="#" onclick="document.schan.expire.value='2592000';return false;">$txt_1m</a>&nbsp;<a href="#" onclick="document.schan.expire.value='0';return false;">$txt_ban_never</a></small></td></tr>
	<tr><td><label for="reason">$txt_ban_reason</label></td><td><input type="text" name="reason" id="reason"></td><td><small>$txt_ban_optional</small></td></tr>
	$banmessage_html
	</table><br>
	<small>$txt_ban_help</small>
	<legend>
	</fieldset>
	</form><br>
EOF;
}

function manageBansTable() {
	$text = '';
	$allbans = allBans();
	if (count($allbans) > 0) {
		$appeals = array();
		foreach ($allbans as $ban) {
			$appeals[$ban['id']] = function_exists('appealForBan') ? appealForBan($ban) : null;
		}
		$pending = count(array_filter($appeals));
		if ($pending > 0) {
			$text .= '<p><b>' . sprintf(__('%d ban appeal(s) waiting for review.'), $pending) . '</b></p>';
		}
		$text .= '<table border="1"><tr><th>' . __('IP Address') . '</th><th>' . __('Board') . '</th><th>' . __('Set At') . '</th><th>' . __('Expires') . '</th><th>' . __('Reason') . '</th><th>' . __('Appeal') . '</th><th>&nbsp;</th></tr>';
		foreach ($allbans as $ban) {
			$expire = ($ban['expire'] > 0) ? formatDate($ban['expire']) : __('Does not expire');
			$reason = ($ban['reason'] == '') ? '&nbsp;' : htmlentities($ban['reason']);
			$board = isset($ban['board']) && $ban['board'] !== '' ? $ban['board'] : '*';
			$appeal = $appeals[$ban['id']];
			if ($appeal) {
				$appeal_html = '<div style="max-width:320px;white-space:pre-wrap;word-wrap:break-word;">' . htmlentities($appeal['text'], ENT_QUOTES) . '</div><small>' . formatDate($appeal['time']) . '</small>';
			} else {
				$appeal_html = '&nbsp;';
			}
			$ip_cell = ipDisplayHTML($ban['ip']) . (function_exists('manageCountryTag') ? manageCountryTag($ban['ip']) : '');
			$text .= '<tr><td>' . $ip_cell . '</td><td>' . ($board === '*' ? __('All') : htmlentities($board)) . '</td><td>' . formatDate($ban['timestamp']) . '</td><td>' . $expire . '</td><td>' . $reason . '</td><td>' . $appeal_html . '</td><td><a href="' . (function_exists('manageCsrfAppendToURL') ? manageCsrfAppendToURL('?bans&lift=' . $ban['id'], '&') : '?bans&lift=' . $ban['id']) . '">' . __('lift') . '</a></td></tr>';
		}
		$text .= '</table>';
	}
	return $text;
}

function manageEditKeyword($id) {
	$id = intval($id);

	$v_text = '';
	$v_action = '';
	$v_regexp_checked = '';
	if ($id > 0) {
		$keyword = keywordByID($id);
		if (empty($keyword)) {
			fancyDie(__("Sorry, there doesn't appear to be a keyword with that ID."));
		}
		$v_text = htmlentities($keyword['text'], ENT_QUOTES);
		$v_action = $keyword['action'];

		if (substr($v_text, 0, 7) == 'REGEXP:') {
			$v_regexp_checked = 'selected';
			$v_text = substr($v_text, 7);
		}
	}

	$txt_keyword = __('Keyword');
	$txt_keywords = __('Keywords');
	$txt_action = __('Action');
	$txt_submit = $id > 0 ? __('Update') : __('Add');

	$return = <<<EOF
	<form id="schan" name="schan" method="post" action="?keywords=$id">
	<fieldset>
	<legend>$txt_keywords</legend>
	<table border="0">
	<tr><td><label for="keyword">$txt_keyword</label></td><td><input type="text" name="text" id="text" value="$v_text"> <label for="regexp">&nbsp; <input type="checkbox" name="regexp" value="1" $v_regexp_checked> Regular expression</label></td></tr>
	<tr><td><label for="action">$txt_action</label></td><td><select name="action">
EOF;
	if (SCHAN_REPORT && SCHAN_REQMOD != 'all') {
		$return .= '<option value="report"' . ($v_action == 'report' ? ' selected' : '') . '>' . __('Report') . '</option>';
	}
	$return .= '<option value="delete"' . ($v_action == 'delete' ? ' selected' : '') . '>' . __('Delete') . '</option>';
	$return .= '<option value="hide"' . ($v_action == 'hide' ? ' selected' : '') . '>' . __('Hide until approved') . '</option>';
	$return .= '<option value="ban1h"' . ($v_action == 'ban1h' ? ' selected' : '') . '>' . __('Delete and ban for 1 hour') . '</option>';
	$return .= '<option value="ban1d"' . ($v_action == 'ban1d' ? ' selected' : '') . '>' . __('Delete and ban for 1 day') . '</option>';
	$return .= '<option value="ban2d"' . ($v_action == 'ban2d' ? ' selected' : '') . '>' . __('Delete and ban for 2 days') . '</option>';
	$return .= '<option value="ban1w"' . ($v_action == 'ban1w' ? ' selected' : '') . '>' . __('Delete and ban for 1 week') . '</option>';
	$return .= '<option value="ban2w"' . ($v_action == 'ban2w' ? ' selected' : '') . '>' . __('Delete and ban for 2 weeks') . '</option>';
	$return .= '<option value="ban1m"' . ($v_action == 'ban1m' ? ' selected' : '') . '>' . __('Delete and ban for 1 month') . '</option>';
	$return .= '<option value="ban0"' . ($v_action == 'ban0' ? ' selected' : '') . '>' . __('Delete and ban permanently') . '</option>';
	return $return . <<<EOF
	</select></td></tr>
	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
	</table>
	</fieldset>
	</form><br>
EOF;
}

function manageKeywordsTable() {
	$text = '';
	$keywords = allKeywords();
	if (count($keywords) > 0) {
		$text .= '<table border="1"><tr><th>' . __('Keyword') . '</th><th>' . __('Action') . '</th><th>' . __('Board') . '</th><th>&nbsp;</th></tr>';
		foreach ($keywords as $keyword) {
			$action = '';
			switch ($keyword['action']) {
				case 'report':
					$action = __('Report');
					break;
				case 'hide':
					$action = __('Hide until approved');
					break;
				case 'delete':
					$action = __('Delete');
					break;
				case 'ban0':
					$action = __('Delete and ban permanently');
					break;
				case 'ban1h':
					$action = __('Delete and ban for 1 hour');
					break;
				case 'ban1d':
					$action = __('Delete and ban for 1 day');
					break;
				case 'ban2d':
					$action = __('Delete and ban for 2 days');
					break;
				case 'ban1w':
					$action = __('Delete and ban for 1 week');
					break;
				case 'ban2w':
					$action = __('Delete and ban for 2 weeks');
					break;
				case 'ban1m':
					$action = __('Delete and ban for 1 month');
					break;
			}
			$board = isset($keyword['board']) && $keyword['board'] !== '' ? $keyword['board'] : '*';
			$text .= '<tr><td>' . htmlentities($keyword['text']) . '</td><td>' . $action . '</td><td>' . ($board === '*' ? __('All') : htmlentities($board)) . '</td><td><a href="?keywords=' . $keyword['id'] . '">' . __('Edit') . '</a> <a href="?keywords&deletekeyword=' . $keyword['id'] . '">' . __('Delete') . '</a></td></tr>';
		}
		$text .= '</table>';
	}
	return $text;
}

// mod view url: mod.php/<board>/, mod.php/<board>/<page>, mod.php/<board>/res/<id>
function modBoardURL($board, $page = 0, $thread = 0) {
	$url = installRootURL() . '/mod.php/' . rawurlencode($board) . '/';
	if ($thread > 0) {
		return $url . 'res/' . intval($thread) . '.html';
	}
	return $page > 0 ? $url . intval($page) . '.html' : $url;
}

// the real board page as staff see it at mod.php/<board>/ (also N.html, res/<id>.html)
// same as the public page but with mod links in posts and the staff post form
// false if the thread doesn't exist
function modRenderPage($board, $thread_id = 0, $page = 0) {
	$GLOBALS['schan_modview'] = true;
	$thread_id = intval($thread_id);

	if ($thread_id > 0) {
		$op = postByID($thread_id);
		if (empty($op) || $op['parent'] != SCHAN_NEWTHREAD) {
			return false;
		}
		$posts = postsInThreadByID($thread_id);
		if (count($posts) == 0) {
			return false;
		}
		$htmlposts = '';
		$lastpostid = 0;
		foreach ($posts as $post) {
			$htmlposts .= buildPost($post, SCHAN_RESPAGE, false, true);
			$lastpostid = $post['id'];
		}
		if (function_exists('threadStatsHTML') && extensionEnabled('stats')) {
			$htmlposts .= threadStatsHTML($thread_id, $board, $posts, true);
		}
		$html = fixLinksInRes(buildPage($htmlposts, $thread_id, 0, 0, $lastpostid, $board));
		$base = installRootURL() . '/' . rawurlencode($board) . '/res/';
	} else {
		$per_page = max(1, intval(SCHAN_THREADSPERPAGE));
		$threads = allThreads();
		$pages = max(0, (int)ceil(count($threads) / $per_page) - 1);
		$page = max(0, min(intval($page), $pages));
		$htmlposts = '';
		$i = 0;
		foreach (array_slice($threads, $page * $per_page, $per_page) as $thread) {
			$replies = postsInThreadByID($thread['id']);
			$thread['omitted'] = max(0, count($replies) - SCHAN_PREVIEWREPLIES - 1);
			$htmlreplies = array();
			for ($j = count($replies) - 1; $j > $thread['omitted']; $j--) {
				$htmlreplies[] = buildPost($replies[$j], SCHAN_INDEXPAGE, false, true);
			}
			if ($i++ > 0) {
				$htmlposts .= "\n<hr>";
			}
			$htmlposts .= buildPost($thread, SCHAN_INDEXPAGE, false, true) . implode('', array_reverse($htmlreplies));
		}
		$html = buildPage($htmlposts, 0, $pages, $page, 0, $board);
		$base = installRootURL() . '/' . rawurlencode($board) . '/';
	}

	// Janitor / Fake accounts: make sure the staff post form is on the page
	$mod_acc = isset($GLOBALS['account']) ? $GLOBALS['account'] : array();
	if (((function_exists('roleIsJanitor') && roleIsJanitor($mod_acc)) || (function_exists('roleIsFake') && roleIsFake($mod_acc))) && strpos($html, 'name="staffpost"') === false) {
		$staff_form = buildPostForm($thread_id, true, $board);
		$n = 0;
		$html = preg_replace_callback('#<div class="postarea">\s*<form\b[^>]*name="postform".*?</form>\s*</div>#is', function ($m) use ($staff_form) {
			return $staff_form;
		}, $html, 1, $n);
		if ($n === 0) {
			// no public form on this page at all, put it above the posts
			$html = preg_replace_callback('#<form\b[^>]*(?:name|id)="delform"#i', function ($m) use ($staff_form) {
				return $staff_form . $m[0];
			}, $html, 1, $n);
		}
	}

	// base href so images/scripts/forms load from the public page, nav stays in the mod view
	$html = preg_replace('/<head>/i', '<head><base href="' . htmlentities($base, ENT_QUOTES) . '">', $html, 1);
	$in_thread = $thread_id > 0;
	$html = preg_replace_callback('/\b(href|action)="([^"#]*)(#[^"]*)?"/', function ($m) use ($board, $in_thread) {
		$u = $m[2];
		$frag = isset($m[3]) ? $m[3] : '';
		$to = null;
		if ($in_thread) {
			if (preg_match('#^(?:res/)?(\d+)\.html$#', $u, $x)) {
				$to = modBoardURL($board, 0, $x[1]);
			} else if (preg_match('#^\.\./(\d+)\.html$#', $u, $x)) {
				$to = modBoardURL($board, $x[1]);
			} else if ($u === '../' || $u === '..' || $u === '../index.html') {
				$to = modBoardURL($board);
			}
		} else {
			if (preg_match('#^res/(\d+)\.html$#', $u, $x)) {
				$to = modBoardURL($board, 0, $x[1]);
			} else if (preg_match('#^(\d+)\.html$#', $u, $x)) {
				$to = modBoardURL($board, $x[1]);
			} else if ($u === 'index.html' || $u === './') {
				$to = modBoardURL($board);
			}
		}
		return $to === null ? $m[0] : $m[1] . '="' . htmlentities($to, ENT_QUOTES) . $frag . '"';
	}, $html);

	// #123 links have to stay on this page even with the base href
	$inject = <<<'JS'
<script type="text/javascript">
document.addEventListener('click', function (e) {
	var a = e.target;
	while (a && a.nodeName !== 'A') { a = a.parentNode; }
	if (!a) { return; }
	var h = a.getAttribute('href');
	if (h && h.length > 1 && h.charAt(0) === '#') { e.preventDefault(); window.location.hash = h; }
}, true);
</script>
JS;
	$inject .= manageQuickActionsJS();
	$pos = strripos($html, '</body>');
	if ($pos !== false) {
		$html = substr($html, 0, $pos) . $inject . substr($html, $pos);
	} else {
		$html .= $inject;
	}
	return $html;
}

function manageInfo($text) {
	return '<div class="manageinfo">' . $text . '</div>';
}

// $edit: null = list, '' = new page form, a name = edit that page
function manageStaticPagesPage($edit = null) {
	$pages = pagesLoad();
	$h = function ($v) {
		return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
	};

	if ($edit !== null) {
		$is_new = ($edit === '');
		$p = $is_new ? array('title' => '', 'content' => '', 'css' => '', 'header' => 1, 'banner' => 1, 'boardbar' => 1) : $pages[$edit];
		if (!empty($GLOBALS['schan_page_form']) && is_array($GLOBALS['schan_page_form'])) {
			$p = array_merge($p, $GLOBALS['schan_page_form']);
		}
		$slug_value = $is_new ? (isset($p['slug']) ? $p['slug'] : '') : $edit;
		$chk = function ($name, $label, $on) use ($h) {
			return '<label style="display:block;margin:2px 0;"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . $h($label) . '</label>';
		};
		$out = '<fieldset><legend>' . ($is_new ? __('New page') : sprintf(__('Edit page: %s'), $h($p['title']))) . '</legend>'
			. '<form id="schan" name="schan" method="post" action="?manage&amp;pages">'
			. '<input type="hidden" name="page_action" value="save"><input type="hidden" name="page_new" value="' . ($is_new ? '1' : '0') . '">'
			. '<table>'
			. '<tr><td><b>' . __('Title') . '</b></td><td><input type="text" name="page_title" value="' . $h($p['title']) . '" size="40" maxlength="100"></td></tr>'
			. '<tr><td><b>' . __('Name') . '</b></td><td><input type="text" name="page_slug" value="' . $h($slug_value) . '" size="20" maxlength="40"' . ($is_new ? '' : ' readonly') . '> .html'
			. '<br><small>' . ($is_new ? __('Used for the address, e.g. "faq" becomes faq.html. Lowercase letters, numbers, - and _.') : __('The name cannot be changed. Make a new page and delete this one to rename it.')) . '</small></td></tr>'
			. '<tr><td valign="top"><b>' . __('Show') . '</b></td><td>'
			. $chk('page_header', __('Page title at the top'), !empty($p['header']))
			. $chk('page_banner', __('Banner'), !empty($p['banner']))
			. $chk('page_boardbar', __('Board bar'), !empty($p['boardbar']))
			. '</td></tr>'
			. '</table>'
			. '<p><b>' . __('Content') . '</b> <small>(' . __('HTML is allowed. The head, style list, options window and footer are added automatically.') . ')</small></p>'
			. '<textarea name="page_content" rows="22" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px;" spellcheck="false">' . $h($p['content']) . '</textarea>'
			. '<p><b>' . __('Custom CSS') . '</b> <small>(' . __('optional, applies to this page only') . ')</small></p>'
			. '<textarea name="page_css" rows="7" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px;" spellcheck="false">' . $h($p['css']) . '</textarea><br><br>'
			. '<input type="submit" value="' . __('Save') . '" class="managebutton"> <a href="?manage&amp;pages">[' . __('Cancel') . ']</a>'
			. '</form>';
		if (!$is_new) {
			$out .= '<form method="post" action="?manage&amp;pages" style="margin-top:10px;" onsubmit="return confirm(\'' . __('Delete this page?') . '\');">'
				. '<input type="hidden" name="page_action" value="delete"><input type="hidden" name="page_slug" value="' . $h($edit) . '">'
				. '<input type="submit" value="' . __('Delete page') . '" class="managebutton"></form>';
		}
		return $out . '</fieldset>';
	}

	$rows = '';
	foreach ($pages as $slug => $page) {
		$slug = (string)$slug;
		$url = installRootURL() . '/' . $slug . '.html';
		$rows .= '<tr><td>' . $h($page['title']) . '</td><td><a href="' . $h($url) . '" target="_blank">' . $h($slug) . '.html</a></td>'
			. '<td>' . (isset($page['updated']) ? formatDate($page['updated']) : '') . '</td>'
			. '<td><a href="?manage&amp;pages&amp;pageedit=' . rawurlencode($slug) . '">[' . __('Edit') . ']</a></td></tr>';
	}
	if ($rows === '') {
		$rows = '<tr><td colspan="4"><i>' . __('No pages yet.') . '</i></td></tr>';
	}
	return '<fieldset><legend>' . __('Pages') . '</legend>'
		. '<p>' . __('Static pages such as rules or an FAQ. Each one is written as <name>.html with the same header, board bar, style list and footer as the boards.') . '</p>'
		. '<table border="1"><tr><th>' . __('Title') . '</th><th>' . __('Address') . '</th><th>' . __('Updated') . '</th><th>&nbsp;</th></tr>' . $rows . '</table><br>'
		. '<a href="?manage&amp;pages&amp;pagenew=1" class="managebtn" style="text-decoration:none;">[' . __('New page') . ']</a> '
		. '<form method="post" action="?manage&amp;pages" style="display:inline;"><input type="hidden" name="page_action" value="rebuild"><input type="submit" value="' . __('Rebuild all pages') . '" class="managebutton"></form>'
		. '</fieldset>';
}

function manageCountriesPage() {
	$blocked = blockedCountries();

	$rows = '';
	foreach ($blocked as $c) {
		$rows .= '<tr><td>' . flagImageHTML(strtolower($c)) . '</td><td>' . htmlentities($c, ENT_QUOTES) . '</td><td>' . htmlentities(countryNameFor($c), ENT_QUOTES) . '</td>'
			. '<td><form method="post" action="?manage&amp;countries" style="display:inline;">'
			. '<input type="hidden" name="country_action" value="remove">'
			. '<input type="hidden" name="country_code" value="' . htmlentities($c, ENT_QUOTES) . '">'
			. '<input type="submit" value="' . __('Unblock') . '" class="managebutton"></form></td></tr>';
	}
	if ($rows === '') {
		$rows = '<tr><td colspan="4"><i>' . __('No countries are blocked.') . '</i></td></tr>';
	}

	$options = '';
	$dir = dirname(__DIR__) . '/static/flags/';
	if (is_dir($dir)) {
		$seen = array();
		foreach ((array)@scandir($dir) as $f) {
			if (preg_match('/^([A-Za-z]{2})\.(png|gif|svg)$/', $f, $m)) {
				$seen[strtoupper($m[1])] = true;
			}
		}
		ksort($seen);
		foreach (array_keys($seen) as $c) {
			$options .= '<option value="' . $c . '">' . htmlentities(countryNameFor($c), ENT_QUOTES) . '</option>';
		}
	}

	$notice = '';
	if (!is_readable(flagDatabasePath()) && !(defined('SCHAN_CLOUDFLARE') && SCHAN_CLOUDFLARE)) {
		$notice = '<p><b>' . __('Warning: no GeoIP database was found, so countries cannot be detected and nothing will be blocked.') . '</b></p>';
	}

	return '<fieldset><legend>' . __('Blocked countries') . '</legend>'
		. '<p>' . __('Visitors from these countries cannot post (new threads or replies) on any board. The country comes from the GeoIP database, or from Cloudflare when that is enabled. Visitors whose country cannot be determined are allowed. Logged-in staff are never blocked.') . '</p>'
		. $notice
		. '<table border="1"><tr><th>&nbsp;</th><th>' . __('Code') . '</th><th>' . __('Country') . '</th><th>&nbsp;</th></tr>' . $rows . '</table><br>'
		. '<form id="schan" name="schan" method="post" action="?manage&amp;countries">'
		. '<input type="hidden" name="country_action" value="add">'
		. '<datalist id="countrycodes">' . $options . '</datalist>'
		. __('Add country codes') . ': <input type="text" name="codes" list="countrycodes" size="30" autocomplete="off" placeholder="RU, CN, ..."> '
		. '<input type="submit" value="' . __('Block') . '" class="managebutton">'
		. '<br><small>' . __('Two-letter ISO codes (e.g. US, DE, RU), separated by commas or spaces.') . '</small>'
		. '</form></fieldset>';
}
