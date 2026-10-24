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
		return '[<a href="?' . $bq . '">' . __('Post') . '</a>] &middot; [<a href="?' . $bq . '&changepassword">' . __('Change Password') . '</a>] [<a href="?logout">' . __('Log Out') . '</a>] &middot; ' . $return;
	}

	// dashboard has all the links, every other manage page gets a Return button
	if (!empty($GLOBALS['schan_manage_dashboard'])) {
		return $return;
	}

	$output = manageReturnButton();
	if (current_board() !== '') {
		$output .= ' [<a href="' . htmlentities(modBoardURL(current_board()), ENT_QUOTES) . '">' . __('Moderate Board') . '</a>]';
	}

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

	$output .= ' &middot; [<a href="' . $returnlink . '" style="text-decoration: underline;">' . __('Back to board') . '</a>]';
	return $output;
}

// Return button, goes back to the dashboard
function manageReturnButton() {
	$url = '?board=' . urlencode(current_board()) . '&dashboard';
	return '<a href="' . htmlentities($url, ENT_QUOTES) . '" class="managebutton" style="display:inline-block;text-decoration:none;">' . __('Return') . '</a>';
}

// dashboard, every manage page grouped
function manageDashboard() {
	global $account, $isadmin;

	$e = function ($s) {
		return htmlentities((string) $s, ENT_QUOTES);
	};
	$is_super = isset($account['role']) && $account['role'] == SCHAN_SUPER_ADMINISTRATOR;
	$acc_board = (isset($account['board']) && $account['board'] !== '') ? $account['board'] : '*';
	$current = current_board();
	$bq = 'board=' . urlencode($current);

	$boards = array();
	foreach (allBoards() as $b) {
		if ($is_super || $acc_board === '*' || $acc_board === $b['slug']) {
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
		$out .= '<li><a href="' . $e(installRootURL() . '/' . rawurlencode($slug) . '/') . '">/' . $e($slug) . '/</a>'
			. ($b['title'] !== '' ? ' - ' . $e($b['title']) : '')
			. (!empty($b['locked']) ? ' <small>(' . __('locked') . ')</small>' : '');
		$links = array();
		$links[] = '<a href="' . $e(modBoardURL($slug)) . '">' . __('moderate') . '</a>';
		if ($isadmin) {
			if ($is_super) {
				$links[] = '<a href="?' . $sq . '&boards=' . intval($b['id']) . '">' . __('edit') . '</a>';
			}
			$links[] = '<a href="?' . $sq . '&pages">' . __('pages') . '</a>';
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
		$items[] = '<a href="?' . $bq . '&rebuildall">' . __('Rebuild') . '</a>';
		if ($is_super) {
			$items[] = '<a href="?' . $bq . '&extensions">' . __('Extensions') . '</a>';
			$items[] = '<a href="?' . $bq . '&config">' . __('Configuration') . '</a>';
			$items[] = '<a href="?' . $bq . '&import">' . __('Import a vichan / Schan database') . '</a>';
			if (SCHAN_DBMIGRATE) {
				$items[] = '<a href="?' . $bq . '&dbmigrate"><b>' . __('Migrate Database') . '</b></a>';
			}
		}
		$out .= '<fieldset><legend>' . __('Administration') . '</legend><ul><li>' . implode('</li><li>', $items) . '</li></ul></fieldset><br>';
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
	$out = '<div class="inlinemod" style="margin:4px 0 8px 0;">';

	if (function_exists('roleIsJanitor') && roleIsJanitor(isset($GLOBALS['account']) ? $GLOBALS['account'] : array())) {
		if ($post['file'] != '' || (function_exists('mediaExtrasFor') && !empty(mediaExtrasFor($post_board, $id)))) {
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

	$return .= '<tr><td><label for="account_board">' . $txt_board . '</label></td><td><select name="account_board" id="account_board">';
	$return .= '<option value="*"' . ($a['board'] === '*' ? ' selected' : '') . '>' . __('All boards') . '</option>';
	if (function_exists('allBoards')) {
		foreach (allBoards() as $b) {
			$sel = ($a['board'] === $b['slug']) ? ' selected' : '';
			$return .= '<option value="' . htmlentities($b['slug'], ENT_QUOTES) . '"' . $sel . '>' . htmlentities($b['slug']) . '</option>';
		}
	}
	$return .= '</select></td></tr>';
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
			$board = isset($account['board']) && $account['board'] !== '' && $account['board'] !== null ? $account['board'] : '*';
			$text .= '</td><td>' . ($board === '*' ? __('All') : htmlentities($board)) . '</td><td>' . $lastactive . '</td><td><a href="?accounts=' . $account['id'] . '">' . __('update') . '</a></td></tr>';
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
			$text .= '<tr><td>' . $ip_cell . '</td><td>' . ($board === '*' ? __('All') : htmlentities($board)) . '</td><td>' . formatDate($ban['timestamp']) . '</td><td>' . $expire . '</td><td>' . $reason . '</td><td>' . $appeal_html . '</td><td><a href="?bans&lift=' . $ban['id'] . '">' . __('lift') . '</a></td></tr>';
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

// static pages (rules, faq...), written as <name>.html in the install dir
// same head / style list / board bar / banner / options / footer as the boards
// stored in .pages.php (exits right away so it can't be downloaded), edit from ?pages
