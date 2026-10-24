<?php


error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set('pcre.jit', '0');
session_set_cookie_params(array('path' => '/', 'samesite' => 'Lax'));
session_start();
setcookie(session_name(), session_id(), array('expires' => time() + 2592000, 'path' => '/', 'samesite' => 'Lax'));
ob_implicit_flush();
while (ob_get_level() > 0) {
	ob_end_flush();
}

function fancyDie($message, $go_back = 1) {
	$go_back_text = 'Click here to go back';
	if (function_exists('__')) {
		$go_back_text = __('Click here to go back');
	}
	die('<body text="#800000" bgcolor="#FFFFEE" align="center"><br><div style="display: inline-block; background-color: #F0E0D6;font-size: 1.25em;font-family: Tahoma, Geneva, sans-serif;padding: 7px;border: 1px solid #D9BFB7;border-left: none;border-top: none;">' . $message . '</div><br><br>- <a href="javascript:history.go(-' . $go_back . ')">' . $go_back_text . '</a> -</body>');
}

// not installed yet -> installer. a settings.php with a database name counts as installed,
// so old boards keep working without .installed.php
$schan_settings_raw = is_file('settings.php') ? (string)@file_get_contents('settings.php') : '';
$schan_installed = is_file('.installed.php') || preg_match('/define\(\s*\'SCHAN_DBNAME\'\s*,\s*\'[^\']+\'/', $schan_settings_raw);
if (!$schan_installed) {
	if (is_file('install.php')) {
		header('Location: ' . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/install.php');
		die('<a href="install.php">Run the installer</a>');
	}
	fancyDie('Schan is not installed. Upload install.php and open it in your browser.');
}
unset($schan_settings_raw, $schan_installed);
if (!file_exists('settings.php')) {
	fancyDie('settings.php is missing.');
}
require 'settings.php';
require 'inc/defines.php';
global $schan_capcodes, $schan_embeds, $schan_hidefields, $schan_hidefieldsop;

if (!defined('SCHAN_LOCALE') || SCHAN_LOCALE == '') {
	function __($string) {
		return $string;
	}
} else {
	require 'inc/gettext.php';
}

if ((SCHAN_CAPTCHA === 'hcaptcha' || SCHAN_REPLYCAPTCHA === 'hcaptcha' || SCHAN_MANAGECAPTCHA === 'hcaptcha') && (SCHAN_HCAPTCHA_SITE == '' || SCHAN_HCAPTCHA_SECRET == '')) {
	fancyDie(__('SCHAN_HCAPTCHA_SITE and SCHAN_HCAPTCHA_SECRET  must be configured.'));
}

if ((SCHAN_CAPTCHA === 'recaptcha' || SCHAN_REPLYCAPTCHA === 'recaptcha' || SCHAN_MANAGECAPTCHA === 'recaptcha') && (SCHAN_RECAPTCHA_SITE == '' || SCHAN_RECAPTCHA_SECRET == '')) {
	fancyDie(__('SCHAN_RECAPTCHA_SITE and SCHAN_RECAPTCHA_SECRET  must be configured.'));
}

if (SCHAN_TIMEZONE != '') {
	date_default_timezone_set(SCHAN_TIMEZONE);
}

if (SCHAN_TRIPSEED == '') {
	fancyDie(__('SCHAN_TRIPSEED must be configured.'));
}

$bcrypt_salt = '$2y$12$' . str_pad(str_replace('=', '/', str_replace('+', '.', substr(base64_encode(SCHAN_TRIPSEED), 0, 22))), 22, '/');

$database_modes = array('flatfile', 'mysql', 'mysqli', 'sqlite', 'sqlite3', 'pdo');
if (!in_array(SCHAN_DBMODE, $database_modes)) {
	fancyDie(__('Unknown database mode specified.'));
}

$schema_mode = (SCHAN_DBMIGRATE) ? SCHAN_DBMIGRATE : SCHAN_DBMODE;
if ($schema_mode == 'pdo' && SCHAN_DBDRIVER == 'pgsql') {
	$accounts_sql = 'CREATE TABLE "' . SCHAN_DBACCOUNTS . '" (
		"id" bigserial NOT NULL,
		"username" varchar(255) NOT NULL,
		"password" text NOT NULL,
		"role" integer NOT NULL,
		"lastactive" integer NOT NULL,
		"board" varchar(64) NOT NULL default \'*\',
		PRIMARY KEY	("id")
	);
	CREATE INDEX ON "' . SCHAN_DBACCOUNTS . '"("board");';

	$bans_sql = 'CREATE TABLE "' . SCHAN_DBBANS . '" (
		"id" bigserial NOT NULL,
		"ip" varchar(255) NOT NULL,
		"timestamp" integer NOT NULL,
		"expire" integer NOT NULL,
		"reason" text NOT NULL,
		"board" varchar(64) NOT NULL default \'*\',
		PRIMARY KEY	("id")
	);
	CREATE INDEX ON "' . SCHAN_DBBANS . '"("ip");
	CREATE INDEX ON "' . SCHAN_DBBANS . '"("board");';

	$keywords_sql = 'CREATE TABLE "' . SCHAN_DBKEYWORDS . '" (
		"id" bigserial NOT NULL,
		"text" varchar(255) NOT NULL,
		"action" varchar(255) NOT NULL,
		"board" varchar(64) NOT NULL default \'*\',
		PRIMARY KEY	("id")
	);
	CREATE INDEX ON "' . SCHAN_DBKEYWORDS . '"("board");';

	$logs_sql = 'CREATE TABLE "' . SCHAN_DBLOGS . '" (
		"id" bigserial NOT NULL,
		"timestamp" integer NOT NULL,
		"account" integer NOT NULL,
		"message" text NOT NULL,
		"board" varchar(64) NOT NULL default \'\',
		PRIMARY KEY	("id")
	);
	CREATE INDEX ON "' . SCHAN_DBLOGS . '"("account");
	CREATE INDEX ON "' . SCHAN_DBLOGS . '"("board");';

	$posts_sql = 'CREATE TABLE "' . SCHAN_DBPOSTS . '" (
		"id" bigserial NOT NULL,
		"parent" integer NOT NULL,
		"timestamp" integer NOT NULL,
		"bumped" integer NOT NULL,
		"ip" varchar(255) NOT NULL,
		"name" varchar(75) NOT NULL,
		"tripcode" varchar(24) NOT NULL,
		"email" varchar(75) NOT NULL,
		"nameblock" varchar(255) NOT NULL,
		"subject" varchar(75) NOT NULL,
		"message" text NOT NULL,
		"password" varchar(255) NOT NULL,
		"file" text NOT NULL,
		"file_hex" varchar(75) NOT NULL,
		"file_original" varchar(255) NOT NULL,
		"file_size" integer NOT NULL default \'0\',
		"file_size_formatted" varchar(75) NOT NULL,
		"image_width" smallint NOT NULL default \'0\',
		"image_height" smallint NOT NULL default \'0\',
		"thumb" varchar(255) NOT NULL,
		"thumb_width" smallint NOT NULL default \'0\',
		"thumb_height" smallint NOT NULL default \'0\',
		"moderated" smallint NOT NULL default \'1\',
		"stickied" smallint NOT NULL default \'0\',
		"locked" smallint NOT NULL default \'0\',
		"board" varchar(64) NOT NULL default \'\',
		PRIMARY KEY	("id")
	);
	CREATE INDEX ON "' . SCHAN_DBPOSTS . '"("parent");
	CREATE INDEX ON "' . SCHAN_DBPOSTS . '"("bumped");
	CREATE INDEX ON "' . SCHAN_DBPOSTS . '"("stickied");
	CREATE INDEX ON "' . SCHAN_DBPOSTS . '"("moderated");
	CREATE INDEX ON "' . SCHAN_DBPOSTS . '"("board");';

	$reports_sql = 'CREATE TABLE "' . SCHAN_DBREPORTS . '" (
		"id" bigserial NOT NULL,
		"ip" varchar(255) NOT NULL,
		"post" integer NOT NULL,
		"board" varchar(64) NOT NULL default \'\',
		PRIMARY KEY	("id")
	);
	CREATE INDEX ON "' . SCHAN_DBREPORTS . '"("board");';

	$boards_sql = 'CREATE TABLE "' . SCHAN_DBBOARDS . '" (
		"id" bigserial NOT NULL,
		"slug" varchar(64) NOT NULL,
		"title" varchar(255) NOT NULL default \'\',
		"desc" varchar(255) NOT NULL default \'\',
		"logo" text NOT NULL default \'\',
		"locked" smallint NOT NULL default \'0\',
		"created" integer NOT NULL,
		PRIMARY KEY	("id")
	);
	CREATE UNIQUE INDEX ON "' . SCHAN_DBBOARDS . '"("slug");';
} else {
	$accounts_sql = "CREATE TABLE `" . SCHAN_DBACCOUNTS . "` (
		`id` mediumint(7) unsigned NOT NULL auto_increment,
		`username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`role` mediumint(7) unsigned NOT NULL,
		`lastactive` int(20) unsigned NOT NULL,
		`board` varchar(64) NOT NULL default '*',
		PRIMARY KEY	(`id`),
		KEY `board` (`board`)
	)";

	$bans_sql = "CREATE TABLE `" . SCHAN_DBBANS . "` (
		`id` mediumint(7) unsigned NOT NULL auto_increment,
		`ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`timestamp` int(20) NOT NULL,
		`expire` int(20) NOT NULL,
		`reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`board` varchar(64) NOT NULL default '*',
		PRIMARY KEY	(`id`),
		KEY `ip` (`ip`),
		KEY `board` (`board`)
	)";

	$keywords_sql = "CREATE TABLE `" . SCHAN_DBKEYWORDS . "` (
		`id` mediumint(7) unsigned NOT NULL auto_increment,
		`text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`board` varchar(64) NOT NULL default '*',
		PRIMARY KEY	(`id`),
		KEY `board` (`board`)
	)";

	$logs_sql = "CREATE TABLE `" . SCHAN_DBLOGS . "` (
		`id` mediumint(7) unsigned NOT NULL auto_increment,
		`timestamp` int(20),
		`account` mediumint(7) unsigned NOT NULL,
		`message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`board` varchar(64) NOT NULL default '',
		PRIMARY KEY	(`id`),
		KEY `account` (`account`),
		KEY `board` (`board`)
	)";

	$posts_sql = "CREATE TABLE `" . SCHAN_DBPOSTS . "` (
		`id` mediumint(7) unsigned NOT NULL auto_increment,
		`parent` mediumint(7) unsigned NOT NULL,
		`timestamp` int(20) NOT NULL,
		`bumped` int(20) NOT NULL,
		`ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`name` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`tripcode` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`email` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`nameblock` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`subject` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`file` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`file_hex` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`file_original` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`file_size` int(20) unsigned NOT NULL default '0',
		`file_size_formatted` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`image_width` smallint(5) unsigned NOT NULL default '0',
		`image_height` smallint(5) unsigned NOT NULL default '0',
		`thumb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`thumb_width` smallint(5) unsigned NOT NULL default '0',
		`thumb_height` smallint(5) unsigned NOT NULL default '0',
		`stickied` tinyint(1) NOT NULL default '0',
		`moderated` tinyint(1) NOT NULL default '1',
		`locked` tinyint(1) NOT NULL default '0',
		`board` varchar(64) NOT NULL default '',
		PRIMARY KEY	(`id`),
		KEY `parent` (`parent`),
		KEY `bumped` (`bumped`),
		KEY `stickied` (`stickied`),
		KEY `moderated` (`moderated`),
		KEY `board` (`board`)
	)";

	$reports_sql = "CREATE TABLE `" . SCHAN_DBREPORTS . "` (
		`id` mediumint(7) unsigned NOT NULL auto_increment,
		`ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
		`post` int(20) NOT NULL,
		`board` varchar(64) NOT NULL default '',
		PRIMARY KEY	(`id`),
		KEY `board` (`board`)
	)";

	$boards_sql = "CREATE TABLE `" . SCHAN_DBBOARDS . "` (
		`id` mediumint(7) unsigned NOT NULL auto_increment,
		`slug` varchar(64) NOT NULL,
		`title` varchar(255) NOT NULL default '',
		`desc` varchar(255) NOT NULL default '',
		`logo` text NOT NULL,
		`locked` tinyint(1) NOT NULL default '0',
		`created` int(20) NOT NULL,
		PRIMARY KEY	(`id`),
		UNIQUE KEY `slug` (`slug`)
	)";
}

// dirs have to be writable
$writedirs = array();
if (SCHAN_DBMODE == 'flatfile') {
	$writedirs[] = 'inc/database/flatfile';
}
foreach ($writedirs as $dir) {
	if (!file_exists($dir)) {
		@mkdir($dir, 0755, true);
	}
	if (!is_writable($dir)) {
		fancyDie(sprintf(__("Directory '%s' can not be written to.  Please modify its permissions."), $dir));
	}
}
if (!is_writable('.')) {
	fancyDie(__("The install directory can not be written to. Please modify its permissions so boards can be created."));
}

// core includes, extension registry first
// extension files load conditionally below so a disabled extension's code never runs
$includes = array(
	'inc/extensions.php',
	'inc/functions.php',
	'inc/antiflood.php',
	'inc/html.php',
	'inc/extra/spoiler.php',
	'inc/extra/media.php',
	'inc/extra/overboard.php',
	'inc/roles.php',
	'inc/import.php',
	'inc/config.php',
	'inc/database/' . SCHAN_DBMODE . '_link.php',
	'inc/database/' . SCHAN_DBMODE . '.php',
	'inc/database/database.php',
);
foreach ($includes as $include) {
	require $include;
}

// extensions, each is a no-op if not installed
extensionRequireFile('flags');
extensionRequireFile('home');
extensionRequireFile('polls');
extensionRequireFile('emoji');
extensionRequireFile('pending');
extensionRequireFile('trust');
extensionRequireFile('search');
extensionRequireFile('globalmessage');

// current board
$active_board = current_board();

// manage panel is mod.php now. old imgboard.php?manage... urls (GET only, so forms posted to the
// old address still work) redirect to the same address on mod.php without ?manage
if (isset($_GET['manage']) && !defined('SCHAN_MODVIEW') && !defined('SCHAN_MODHOME')
	&& isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
	$q = $_GET;
	if ($q['manage'] === '') {
		unset($q['manage']);
	}
	$qs = http_build_query($q);
	header('Location: ' . installRootURL() . '/mod.php' . ($qs !== '' ? '?' . $qs : ''));
	exit;
}

list($account, $loggedin, $isadmin) = manageCheckLogIn(false);

// board scope: super admins can open any board's manage panel, other accounts only their own
if ($loggedin) {
	$acc_board = isset($account['board']) && $account['board'] !== '' ? $account['board'] : '*';
	$is_super = isset($account['role']) && $account['role'] == SCHAN_SUPER_ADMINISTRATOR;
	if (!$is_super && $acc_board !== '*' && $active_board !== '' && $acc_board !== $active_board) {
		$loggedin = false;
		$isadmin = false;
		$account = array();
	}
}

// janitors only get the manage panel, on public pages (posting, deleting) they're regular visitors
if ($loggedin && function_exists('roleIsJanitor') && roleIsJanitor($account) && !isset($_GET['manage']) && !defined('SCHAN_MODVIEW')) {
	$loggedin = false;
	$isadmin = false;
}

// moderated board view: staff not logged in (or with only the posting role) get the login form
// or the staff post form at the same address
if (defined('SCHAN_MODVIEW') && !isset($_GET['manage'])
	&& (!$loggedin || (function_exists('roleIsFake') && roleIsFake($account)))) {
	$_GET['manage'] = '';
}

if (!$loggedin && !isset($_GET['manage']) && !defined('SCHAN_MODVIEW')) {
	checkBanned();
}

$board_row = $active_board !== '' ? boardBySlug($active_board) : array();
$board_locked = !empty($board_row['locked']);

$redirect = true;

if ($board_locked && !$loggedin
	&& !isset($_GET['delete']) && !isset($_GET['manage'])
	&& (isset($_POST['name']) || isset($_POST['email']) || isset($_POST['subject']) || isset($_POST['message']) || isset($_POST['file']) || isset($_POST['embed']) || isset($_POST['password']))) {
	fancyDie(__('This board is locked and is not accepting new posts.'));
}

// Post handler
if (!isset($_GET['delete']) && !isset($_GET['manage']) && (isset($_POST['name']) || isset($_POST['email']) || isset($_POST['subject']) || isset($_POST['message']) || isset($_POST['file']) || isset($_POST['embed']) || isset($_POST['password']))) {
	$lock = lockDatabase();

	if (SCHAN_DBMIGRATE) {
		fancyDie(__('Posting is currently disabled.<br>Please try again in a few moments.'));
	}

	$staffpost = isStaffPost();
	$capcode = '';
	if (!$staffpost) {
		checkMessageSize();
	}

	$post = newPost(setParent());
	$post['board'] = $active_board;

	// blocked countries can't post (staff exempt), only if the extension is installed
	if (!$loggedin && countriesAvailable() && function_exists('countryBlockCheck')) {
		countryBlockCheck();
	}

	// news boards: only admins (posting from the manage panel) can start threads
	if ($post['parent'] == SCHAN_NEWTHREAD && function_exists('newsBoardFor') && newsBoardFor($active_board) && !($staffpost && !empty($isadmin))) {
		fancyDie(__('Only administrators can start new threads on this board. You can reply to existing threads.'));
	}

	if (!$loggedin) {
		checkCAPTCHA($post['parent'] == SCHAN_NEWTHREAD ? SCHAN_CAPTCHA : SCHAN_REPLYCAPTCHA);
		checkFlood();
		if (function_exists('antiFloodCheck')) {
			antiFloodCheck($post['parent'] == SCHAN_NEWTHREAD, isset($_POST['message']) ? $_POST['message'] : '');
		}
	}

	if (!$loggedin) {
		if ($post['parent'] == SCHAN_NEWTHREAD && SCHAN_DISALLOWTHREADS != '') {
			fancyDie(SCHAN_DISALLOWTHREADS);
		} else if ($post['parent'] != SCHAN_NEWTHREAD && SCHAN_DISALLOWREPLIES != '') {
			fancyDie(SCHAN_DISALLOWREPLIES);
		}
	}

	$hide_fields = $post['parent'] == SCHAN_NEWTHREAD ? $schan_hidefieldsop : $schan_hidefields;
	if (function_exists('boardApplyFeatureOverrides')) {
		$hide_fields = boardApplyFeatureOverrides($hide_fields, $active_board);
	}

	if ($post['parent'] != SCHAN_NEWTHREAD && (!$loggedin || (function_exists('roleIsFake') && roleIsFake($account)))) {
		$parent = postByID($post['parent']);
		if (!isset($parent['locked'])) {
			fancyDie(__('Invalid parent thread ID supplied, unable to create post.'));
		} else if ($parent['locked'] == 1) {
			fancyDie(__('Replies are not allowed to locked threads.'));
		}
	}

	if ($post['name'] == '' && $post['tripcode'] == '') {
		global $schan_anonymous;
		$post['name'] = $schan_anonymous[array_rand($schan_anonymous)];
	}

	$post['ip'] = remoteAddress();

	$spoiler_requested = function_exists('spoilerPostRequested') && spoilerPostRequested();
	$spoiler = false;

	$extra_uploads = function_exists('mediaCollectUploads') ? mediaCollectUploads() : array();
	$extra_media = array();

	if ($staffpost || !in_array('name', $hide_fields)) {
		list($post['name'], $post['tripcode']) = nameAndTripcode($_POST['name']);
		if ($staffpost) {
			$post['tripcode'] = '';
		}
		if (SCHAN_MAXNAME > 0) {
			$post['name'] = _substr($post['name'], 0, SCHAN_MAXNAME);
		}
		$post['name'] = cleanString($post['name']);
	}
	if ($staffpost || !in_array('email', $hide_fields)) {
		$post['email'] = $_POST['email'];
		if (SCHAN_MAXEMAIL > 0) {
			$post['email'] = _substr($post['email'], 0, SCHAN_MAXEMAIL);
		}
		$post['email'] = cleanString(str_replace('"', '&quot;', $post['email']));
	}
	if ($staffpost) {
		$custom_capcode = function_exists('roleCustomCapcode') ? roleCustomCapcode($account) : null;
	}
	if ($staffpost && $custom_capcode !== null) {
		$capcode = $custom_capcode;
	} else if ($staffpost) {
		$capcode = ($isadmin) ? ' <span style="color: ' . $schan_capcodes[0][1] . ' ;">## ' . $schan_capcodes[0][0] . '</span>' : ' <span style="color: ' . $schan_capcodes[1][1] . ';">## ' . $schan_capcodes[1][0] . '</span>';
	}
	if ($staffpost || !in_array('subject', $hide_fields)) {
		$post['subject'] = $_POST['subject'];
		if (SCHAN_MAXSUBJECT > 0) {
			$post['subject'] = _substr($post['subject'], 0, SCHAN_MAXSUBJECT);
		}
		$post['subject'] = cleanString($post['subject']);
	}
	if ($staffpost || !in_array('message', $hide_fields)) {
		$post['message'] = $_POST['message'];
		if ($staffpost && isset($_POST['raw']) && !(function_exists('roleIsFake') && roleIsFake($account))) {
			// Treat message as raw HTML
		} else {
			if (SCHAN_WORDBREAK > 0) {
				$post['message'] = preg_replace('/([^\s]{' . SCHAN_WORDBREAK . '})(?=[^\s])/u', '$1' . SCHAN_WORDBREAK_IDENTIFIER, $post['message']);
			}
			$post['message'] = str_replace("\n", '<br>', makeLinksClickable(colorQuote(postLink(cleanString(rtrim($post['message']))))));

			if (SCHAN_SPOILERTEXT) {
				$post['message'] = preg_replace('/&lt;s&gt;(.*?)&lt;\/s&gt;/i', '<span class="spoiler">$1</span>', $post['message']);
				$post['message'] = preg_replace('/&lt;spoiler&gt;(.*?)&lt;\/spoiler&gt;/i', '<span class="spoiler">$1</span>', $post['message']);
				$post['message'] = preg_replace('/&lt;spoilers&gt;(.*?)&lt;\/spoilers&gt;/i', '<span class="spoiler">$1</span>', $post['message']);
			}

			if (SCHAN_WORDBREAK > 0) {
				$post['message'] = finishWordBreak($post['message']);
			}
		}
	}
	if ($staffpost || !in_array('password', $hide_fields)) {
		$post['password'] = ($_POST['password'] != '') ? hashData($_POST['password']) : '';
	}

	$poll_options = array();
	if (function_exists('pollsEnabled') && pollsEnabled() && isset($_POST['poll']) && ($staffpost || !in_array('poll', $hide_fields))) {
		$poll_options = pollCleanOptions($_POST['poll']);
		if (count($poll_options) == 1) {
			fancyDie(__('A poll needs at least 2 different options.'));
		}
	}

	$hide_post = false;
	$report_post = false;
	foreach (array($post['name'], $post['email'], $post['subject'], $post['message']) as $field) {
		$keyword = checkKeywords($field);
		if (empty($keyword)) {
			continue;
		}

		$expire = -1;
		switch ($keyword['action']) {
			case 'report':
				$report_post = true;
				break;
			case 'hide':
				$hide_post = true;
				break;
			case 'delete':
				fancyDie(__('Your post contains a blocked keyword.'));
			case 'ban0':
				$expire = 0;
				break;
			case 'ban1h':
				$expire = 3600;
				break;
			case 'ban1d':
				$expire = 86400;
				break;
			case 'ban2d':
				$expire = 172800;
				break;
			case 'ban1w':
				$expire = 604800;
				break;
			case 'ban2w':
				$expire = 1209600;
				break;
			case 'ban1m':
				$expire = 2592000;
				break;
		}
		if ($expire >= 0) {
			$ban = array();
			$ban['ip'] = $post['ip'];
			$ban['expire'] = $expire > 0 ? (time() + $expire) : 0;
			$ban['reason'] = __('Keyword') . ': ' . $keyword['text'];
			$ban['board'] = $active_board;
			insertBan($ban);

			if ($ban['expire'] > 0) {
				$bannedText = sprintf(__('Your IP address (%1$s) is banned until %2$s.'), remoteAddress(), formatDate($ban['expire']));
			} else {
				$bannedText = sprintf(__('Your IP address (%s) is permanently banned.'), remoteAddress());
			}
			if ($ban['reason'] != '') {
				$bannedText .= '<br>' . __('Reason') . ': ' . $ban['reason'];
			}
			fancyDie($bannedText);
		}
		break;
	}

	$flag_marker = function_exists('flagsMarkerForPost') ? flagsMarkerForPost($post['board']) : '';
	if (function_exists('memeFlagMarkerForPost') && ($staffpost || !in_array('memeflag', $hide_fields))) {
		$flag_marker .= memeFlagMarkerForPost();
	}
	$post['nameblock'] = nameBlock($post['name'], $post['tripcode'], $post['email'], time(), $capcode, $flag_marker);

	if (isset($_POST['embed']) && trim($_POST['embed']) != '' && ($staffpost || !in_array('embed', $hide_fields))) {
		if (isset($_FILES['file']) && $_FILES['file']['name'] != "") {
			fancyDie(__('Embedding a URL and uploading a file at the same time is not supported.'));
		}

		list($service, $embed) = getEmbed(trim($_POST['embed']));
		if (empty($embed) || !isset($embed['html']) || !isset($embed['title']) || !isset($embed['thumbnail_url'])) {
			if (!SCHAN_UPLOADVIAURL) {
				fancyDie(sprintf(__('Invalid embed URL. Only %s URLs are supported.'), implode('/', array_keys($schan_embeds))));
			}

			$headers = @get_headers(trim($_POST['embed']), true);
			$remote_len = (is_array($headers) && isset($headers['Content-Length'])) ? (is_array($headers['Content-Length']) ? end($headers['Content-Length']) : $headers['Content-Length']) : 0;
			if (SCHAN_MAXKB > 0 && intval($remote_len) > (SCHAN_MAXKB * 1024)) {
				fancyDie(sprintf(__('That file is larger than %s.'), SCHAN_MAXKBDESC));
			}

			$data = url_get_contents(trim($_POST['embed']));
			if (strlen($data) == 0) {
				fancyDie(__('Failed to download file at specified URL.'));
			}

			if (SCHAN_MAXKB > 0 && strlen($data) > (SCHAN_MAXKB * 1024)) {
				fancyDie(sprintf(__('That file is larger than %s.'), SCHAN_MAXKBDESC));
			}

			$filepath = $active_board . '/src/' . time() . substr(microtime(), 2, 3) . rand(1000, 9999) . '.txt';
			if (!file_put_contents($filepath, $data)) {
				@unlink($filepath);
				fancyDie(__('Failed to download file at specified URL.'));
			}

			$post = attachFile($post, $filepath, basename(parse_url(trim($_POST['embed']), PHP_URL_PATH)), false, $spoiler);
		} else {
			$post['file_hex'] = $service;
			$temp_file = time() . substr(microtime(), 2, 3);
			thumbEnsureDir($active_board);
			$file_location = thumbWritePath($temp_file, $active_board);
			file_put_contents($file_location, url_get_contents($embed['thumbnail_url']));

			$file_info = @getimagesize($file_location);
			if (!$file_info) {
				@unlink($file_location);
				fancyDie(__('Error while processing audio/video.'));
			}
			$file_mime = mime_content_type($file_location);
			$post['image_width'] = $file_info[0];
			$post['image_height'] = $file_info[1];

			if ($file_mime == "image/jpeg") {
				$post['thumb'] = $temp_file . '.jpg';
			} else if ($file_mime == "image/gif") {
				$post['thumb'] = $temp_file . '.gif';
			} else if ($file_mime == "image/png") {
				$post['thumb'] = $temp_file . '.png';
			} else {
				@unlink($file_location);
				fancyDie(__('Error while processing audio/video.'));
			}
			$thumb_location = thumbWritePath($post['thumb'], $active_board);

			list($thumb_maxwidth, $thumb_maxheight) = thumbnailDimensions($post);

			if (!createThumbnail($file_location, $thumb_location, $thumb_maxwidth, $thumb_maxheight, $spoiler)) {
				fancyDie(__('Could not create thumbnail.'));
			}

			addVideoOverlay($thumb_location);

			@unlink($file_location);
			$thumb_info = getimagesize($thumb_location);
			$post['thumb_width'] = $thumb_info[0];
			$post['thumb_height'] = $thumb_info[1];

			$post['file_original'] = cleanString($embed['title']);
			$post['file'] = str_ireplace(array('src="https://', 'src="http://'), 'src="//', $embed['html']);
		}
	} else if (isset($_FILES['file']) && $_FILES['file']['name'] != "" && ($staffpost || !in_array('file', $hide_fields))) {
		validateFileUpload();

		$post = attachFile($post, $_FILES['file']['tmp_name'], $_FILES['file']['name'], true, $spoiler);
		if (function_exists('mediaTrackFile')) {
			mediaTrackFile($active_board . '/src/' . $post['file']);
			if ($post['thumb'] != '') {
				mediaTrackFile(thumbPath($active_board, $post['thumb']));
			}
		}
		if (!empty($extra_uploads) && function_exists('mediaAttachExtras')) {
			$extra_media = mediaAttachExtras($post, $extra_uploads, $spoiler);
		}
	}

	if ($post['file'] == '') {
		$file_ok = !empty($schan_uploads) && ($staffpost || !in_array('file', $hide_fields));
		$embed_ok = (!empty($schan_embeds) || SCHAN_UPLOADVIAURL) && ($staffpost || !in_array('embed', $hide_fields));
		$allowed = '';
		if ($file_ok && $embed_ok) {
			$allowed = __('upload a file or embed a URL');
		} else if ($file_ok) {
			$allowed = __('upload a file');
		} else if ($embed_ok) {
			$allowed = __('embed a URL');
		}
		if ($post['parent'] == SCHAN_NEWTHREAD && $allowed != "" && !SCHAN_NOFILEOK) {
			fancyDie(sprintf(__('Please %s to start a new thread.'), $allowed));
		}
		if (!$staffpost && str_replace('<br>', '', $post['message']) == "") {
			$message_ok = !in_array('message', $hide_fields);
			if ($message_ok) {
				if ($allowed != '') {
					fancyDie(sprintf(__('Please enter a message and/or %s.'), $allowed));
				}
				fancyDie(__('Please enter a message.'));
			}
			fancyDie(sprintf(__('Please %s.'), $allowed));
		}
	}

	$trusted_poster = function_exists('trustedIPCheck') && !$loggedin && trustedIPCheck($active_board, remoteAddress());

	if (!$loggedin && !$trusted_poster && (($post['file'] != '' && SCHAN_REQMOD == 'files') || SCHAN_REQMOD == 'all')) {
		$post['moderated'] = '0';
		echo sprintf(__('Your %s will be shown <b>once it has been approved</b>.'), $post['parent'] == SCHAN_NEWTHREAD ? 'thread' : 'post') . '<br>';
		$slow_redirect = true;
	}

	$post['id'] = insertPost($post);
	if (!$loggedin && function_exists('antiFloodRecord')) {
		antiFloodRecord($post['parent'] == SCHAN_NEWTHREAD, isset($_POST['message']) ? $_POST['message'] : '');
	}
	if (function_exists('ipMapRemember')) {
		ipMapRemember($post['ip']);
	}

	if ($spoiler_requested && $post['file'] != '' && function_exists('spoilerSet')) {
		spoilerSet($post['id']);
	}

	if (function_exists('mediaCommit')) {
		mediaCommit();
	}
	if (!empty($extra_media) && function_exists('mediaSaveExtras')) {
		mediaSaveExtras($active_board, $post['id'], $extra_media);
	}

	if (!empty($poll_options)) {
		pollCreate($post['id'], $poll_options, $active_board);
	}

	if (!$loggedin && !$trusted_poster && function_exists('pendingRegisterPost')) {
		$pending_notice = pendingRegisterPost($post);
		if ($pending_notice != '') {
			echo $pending_notice . '<br>';
			$slow_redirect = true;
		}
	}

	if ($report_post) {
		$report = array('ip' => $post['ip'], 'post' => $post['id'], 'board' => $active_board);
		insertReport($report);
		checkAutoHide($post);
	}

	if ($hide_post) {
		approvePostByID($post['id'], 0);
	}

	if ($post['moderated'] == '1') {
		if (SCHAN_ALWAYSNOKO || strtolower($post['email']) == 'noko') {
			$redirect = $active_board . '/res/' . ($post['parent'] == SCHAN_NEWTHREAD ? $post['id'] : $post['parent']) . '.html#' . $post['id'];
		}

		trimThreads();

		echo __('Updating thread...') . '<br>';
		if ($post['parent'] != SCHAN_NEWTHREAD) {
			rebuildThread($post['parent'], $active_board);

			if (strtolower($post['email']) != 'sage') {
				if (SCHAN_MAXREPLIES == 0 || numRepliesToThreadByID($post['parent']) <= SCHAN_MAXREPLIES) {
					bumpThreadByID($post['parent']);
				}
			}
		} else {
			rebuildThread($post['id'], $active_board);
		}

		echo __('Updating index...') . '<br>';
		rebuildIndexes($active_board);
	}

	if ($staffpost) {
		manageLogAction(__('Created staff post') . ' ' . postLink('&gt;&gt;' . $post['id']));

		if ($redirect === true || is_string($redirect)) {
			$redirect = modBoardURL($active_board, 0, $post['parent'] == SCHAN_NEWTHREAD ? 0 : $post['parent']);
		}
	}

	if ($redirect === true && !empty($_POST['from_overboard']) && defined('SCHAN_OVERBOARD')) {
		$redirect = SCHAN_OVERBOARD . '/' . SCHAN_INDEX;
	}
} elseif (isset($_GET['search']) && !isset($_GET['manage'])) {
	if (!function_exists('searchPage') || !extensionEnabled('search')) {
		fancyDie(__('Search is not installed.'));
	}
	echo searchPage($active_board);
	die();
} elseif (isset($_GET['preview']) && !isset($_GET['manage'])) {
	$post = postByID(intval($_GET['preview']));
	if (empty($post)) {
		die(__('This post has been deleted'));
	} else if ($post['moderated'] == 0 && !$isadmin) {
		die(__('This post requires moderation before it can be displayed'));
	}

	$html = buildPost($post, isset($_GET['res']), true);
	if (isset($_GET['res'])) {
		$html = fixLinksInRes($html);
	}

	echo $html;
	die();
} elseif (isset($_GET['posts']) && !isset($_GET['manage'])) {
	if (SCHAN_AUTOREFRESH <= 0) {
		fancyDie(__('Automatic refreshing is disabled.'));
	}

	$thread_id = intval($_GET['posts']);
	$new_since = intval($_GET['since']);
	if ($thread_id <= 0 || $new_since < 0) {
		fancyDie('');
	}

	$json_posts = array();
	$posts = postsInThreadByID($thread_id);
	if ($new_since > 0) {
		foreach ($posts as $i => $post) {
			if ($post['id'] <= $new_since) {
				continue;
			}
			$json_posts[$post['id']] = fixLinksInRes(buildPost($post, true));
		}
	}

	echo json_encode($json_posts);
	die();
} elseif (isset($_GET['vote']) && !isset($_GET['manage'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(function_exists('pollHandleVote') ? pollHandleVote() : array('error' => __('Polls are not installed.')));
	die();
} elseif (isset($_GET['pollresults']) && !isset($_GET['manage'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(function_exists('pollResultsData') ? pollResultsData(intval($_GET['pollresults'])) : array('error' => __('Poll not found.')));
	die();
} elseif (isset($_GET['pollstatus']) && !isset($_GET['manage'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	$poll_ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', $_GET['pollstatus'])))), 0, 60);
	$poll_out = array();
	foreach ($poll_ids as $poll_id) {
		$poll_out[$poll_id] = function_exists('pollResultsData') ? pollResultsData($poll_id) : array('error' => __('Poll not found.'));
	}
	echo json_encode((object)$poll_out);
	die();
} elseif (isset($_GET['watching']) && !isset($_GET['manage'])) {
	// watching stats live in flags.php, only when that extension is installed
	if (!extensionEnabled('stats') || !function_exists('watchingHandle')) {
		header('Content-Type: application/json');
		echo '{}';
		die();
	}
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(watchingHandle());
	die();
} elseif (isset($_GET['report']) && !isset($_GET['manage'])) {
	$lock = lockDatabase();

	if (!SCHAN_REPORT) {
		fancyDie(__('Reporting is disabled.'));
	}

	$post = postByID($_GET['report']);
	if (!$post) {
		fancyDie(__('Sorry, an invalid post identifier was sent. Please go back, refresh the page, and try again.'));
	}

	if ($post['moderated'] == 2) {
		fancyDie(__('Moderators have determined that post does not break any rules.'));
	}

	$report = reportByIP($post['id'], remoteAddress());
	if (!empty($report)) {
		fancyDie(__('You have already submitted a report for that post.'));
	}

	$go_back = 1;
	if (SCHAN_REPORTCAPTCHA != '') {
		if (isset($_GET['verify'])) {
			checkCAPTCHA(SCHAN_REPORTCAPTCHA);
			$go_back = 2;
		} else {
			if (SCHAN_REPORTCAPTCHA === 'hcaptcha') {
				$captcha = '
<br>
<div style="min-height: 82px;">
	<div class="h-captcha" data-sitekey="' . SCHAN_HCAPTCHA_SITE . '"></div>
</div><br><br>';
			} else if (SCHAN_REPORTCAPTCHA === 'recaptcha') {
				$captcha = '
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
			} else {
				$captcha = '
<br>
<input type="text" name="captcha" id="captcha" size="6" accesskey="c" autocomplete="off">&nbsp;&nbsp;' . __('(enter the text below)') . '<br>
<img id="captchaimage" src="inc/captcha.php" width="175" height="55" alt="CAPTCHA" onclick="javascript:reloadCAPTCHA()" style="margin-top: 5px;cursor: pointer;"><br><br>';
			}

			$txt_report = __('Please complete a CAPTCHA to submit your report');
			$txt_submit = __('Submit');
			$board_qs = htmlentities($active_board, ENT_QUOTES);
			$body = <<<EOF
<form id="schan" name="schan" method="post" action="?board=$board_qs&amp;report={$post['id']}&amp;verify">
<fieldset>
<legend align="center">$txt_report</legend>
<div class="login">
$captcha
<input type="submit" value="$txt_submit" class="managebutton">
</div>
</fieldset>
</form>
EOF;

			echo pageHeader($active_board) . $body . pageFooter();
			die();
		}
	}

	$report = array('ip' => remoteAddress(), 'post' => $post['id'], 'board' => $active_board);
	insertReport($report);
	checkAutoHide($post);

	fancyDie(__('Post reported.'), $go_back);
} elseif (isset($_GET['delete']) && !isset($_GET['manage'])) {
	$lock = lockDatabase();

	if (!isset($_POST['delete'])) {
		fancyDie(__('Tick the box next to a post and click "Delete" to delete it.'));
	}

	if (SCHAN_DBMIGRATE) {
		fancyDie(__('Post deletion is currently disabled.<br>Please try again in a few moments.'));
	}

	$post_ids = array();
	if (is_array($_POST['delete'])) {
		$post_ids = $_POST['delete'];
	} else {
		$post_ids = array($_POST['delete']);
	}

	$delete_board = '';
	$clean_ids = array();
	foreach ($post_ids as $pid) {
		$pid = (string)$pid;
		if (strpos($pid, ':') !== false) {
			list($pb, $pn) = explode(':', $pid, 2);
			if ($delete_board === '') {
				$delete_board = trim($pb);
			}
			$clean_ids[] = $pn;
		} else {
			$clean_ids[] = $pid;
		}
	}
	$post_ids = $clean_ids;
	if ($delete_board !== '' && !empty(boardBySlug($delete_board))) {
		$GLOBALS['schan_forced_board'] = $delete_board;
		$active_board = $delete_board;
	}

	list($account, $loggedin, $isadmin) = manageCheckLogIn(false);
	if (!empty($account)) {
		echo '--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="0;url=' . installRootURL() . '/mod.php/' . rawurlencode($active_board) . '/' . '">';
		die();
	}

	$delete_password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
	$post = !empty($post_ids) ? postByID($post_ids[0]) : null;
	if (!$post) {
		fancyDie(__('Sorry, an invalid post identifier was sent. Please go back, refresh the page, and try again.'));
	} else if ($post['password'] != '' && (hashData($delete_password) == $post['password'] || md5(md5($delete_password)) == $post['password'])) {
		removePostFiles($post, $active_board);
		deletePost($post['id']);
		if ($post['parent'] == SCHAN_NEWTHREAD) {
			threadUpdated($post['id']);
		} else {
			threadUpdated($post['parent']);
		}
		fancyDie(__('Post deleted.'));
	} else {
		fancyDie(__('Invalid password.'));
	}

	$redirect = false;
} elseif (defined('SCHAN_MODVIEW') && !isset($_GET['manage'])) {
	$redirect = false;
	$mod_self = modBoardURL($active_board, isset($_GET['mpage']) ? intval($_GET['mpage']) : 0, isset($_GET['mthread']) ? intval($_GET['mthread']) : 0);
	if (!$loggedin || (function_exists('roleIsFake') && roleIsFake($account))) {
		die('--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="0;url=' . $mod_self . '?login">');
	}
	if (function_exists('roleGate')) {
		roleGate($account);
	}
	if ($active_board === '' || empty(boardBySlug($active_board)) || $active_board !== $GLOBALS['schan_modboard_requested']) {
		fancyDie(__('Board not found.'));
	}
	$mod_html = modRenderPage($active_board, isset($_GET['mthread']) ? intval($_GET['mthread']) : 0, isset($_GET['mpage']) ? intval($_GET['mpage']) : 0);
	if ($mod_html === false) {
		fancyDie(__("Sorry, there doesn't appear to be a thread with that ID."));
	}
	echo $mod_html;
} elseif (isset($_GET['manage'])) {
	$lock = lockDatabase();

	$text = '';
	$onload = '';
	$redirect = false;
	$loggedin = false;
	$isadmin = false;
	$returnlink = basename($_SERVER['PHP_SELF']);
	if (defined('SCHAN_MODVIEW') || defined('SCHAN_MODHOME')) {
		// "Return" / "Back to board": moderated board, or the public site if no board is selected
		$returnlink = $active_board !== '' ? modBoardURL($active_board) : (installRootURL() . '/');
	}

	if (isset($_GET["logout"])) {
		$_SESSION['schan'] = '';
		$_SESSION['schan_key'] = '';
		$_SESSION['schan_globalmod'] = 0;
		session_destroy();
		$logout_to = defined('SCHAN_MODVIEW') ? (installRootURL() . '/mod.php/' . rawurlencode($active_board) . '/') : (installRootURL() . '/mod.php');
		die('--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="0;url=' . $logout_to . '">');
	}

	list($account, $loggedin, $isadmin) = manageCheckLogIn(true);

	if ($loggedin) {
		$acc_board = isset($account['board']) && $account['board'] !== '' ? $account['board'] : '*';
		$is_super = isset($account['role']) && $account['role'] == SCHAN_SUPER_ADMINISTRATOR;
		if (!$is_super && $acc_board !== '*' && $active_board !== '' && $acc_board !== $active_board) {
			fancyDie(sprintf(__('Access denied. Your account is bound to board "%s".'), htmlentities($acc_board)));
		}
	}

	if ($loggedin && function_exists('roleGate')) {
		roleGate($account);
	}

	if ($loggedin) {
		if (isset($_GET['global'])) {
			if ($isadmin && $account['role'] == SCHAN_SUPER_ADMINISTRATOR) {
				$_SESSION['schan_globalmod'] = intval($_GET['global']) ? 1 : 0;
			} else {
				$_SESSION['schan_globalmod'] = 0;
			}
		}

		if (isset($_GET['move']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
			if ($account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
				fancyDie(__('Access denied.'));
			}
			$move_id = intval($_GET['move']);
			$target = isset($_POST['to']) ? strtolower(trim($_POST['to'])) : '';
			if ($move_id <= 0 || $target === '') {
				$text .= manageInfo(__('Invalid move request. Choose a target board.'));
			} else if (!function_exists('moveThreadToBoard')) {
				$text .= manageInfo(__('Move is not available: the database driver does not provide moveThreadToBoard(). Upload inc/database/mysqli.php from the multi-board build.'));
			} else {
				$op = postByID($move_id);
				if (empty($op)) {
					$text .= manageInfo(__('Post not found.'));
				} else {
					$source_board = isset($op['board']) && $op['board'] !== '' ? $op['board'] : $active_board;
					list($ok, $err, $new_id) = moveThreadToBoard($move_id, $target);
					if (!$ok) {
						$text .= manageInfo(sprintf(__('Move failed: %s'), htmlentities($err)));
					} else {
						$GLOBALS['schan_forced_board'] = $source_board;
						rebuildIndexes($source_board);
						$GLOBALS['schan_forced_board'] = null;

						$GLOBALS['schan_forced_board'] = $target;
						rebuildThread($new_id, $target);
						rebuildIndexes($target);
						$GLOBALS['schan_forced_board'] = null;

						@unlink($source_board . '/res/' . $move_id . '.html');

						manageLogAction(sprintf(__('Moved thread %1$s from board %2$s to board %3$s as %4$s'), '&gt;&gt;' . $move_id, htmlentities($source_board), htmlentities($target), '&gt;&gt;' . $new_id));
						$text .= manageInfo(sprintf(__('Moved thread %1$s to %2$s as %3$s.'), '&gt;&gt;' . $move_id, htmlentities($target), '&gt;&gt;' . $new_id));
					}
				}
			}
		}

		if ($isadmin) {
			if (isset($_GET['rebuildall'])) {
				if ($account['role'] == SCHAN_SUPER_ADMINISTRATOR && !isset($_GET['current'])) {
					foreach (allBoards() as $b) {
						$GLOBALS['schan_forced_board'] = $b['slug'];
						$allthreads = allThreads();
						foreach ($allthreads as $thread) {
							rebuildThread($thread['id'], $b['slug']);
						}
						rebuildIndexes($b['slug']);
						$GLOBALS['schan_forced_board'] = null;
					}
					$text .= manageInfo(__('Rebuilt all boards.'));
				} else {
					$allthreads = allThreads();
					foreach ($allthreads as $thread) {
						rebuildThread($thread['id'], $active_board);
					}
					rebuildIndexes($active_board);
					$text .= manageInfo(sprintf(__('Rebuilt board %s.'), htmlentities($active_board)));
				}
				if (function_exists('rebuildPages')) {
					rebuildPages(true);
				}
			} else if (isset($_GET['modlog'])) {
				$text .= manageModerationLog($_GET['modlog']);
			} else if (isset($_GET['pending'])) {
				if (!$isadmin || (function_exists('roleIsJanitor') && roleIsJanitor($account))) {
					fancyDie(__('Access denied'));
				}
				$text .= managePendingPage();
			} else if (isset($_GET['reports'])) {
				if (!SCHAN_REPORT) {
					fancyDie(__('Reporting is disabled.'));
				}
				$text .= manageReportsPage($_GET['reports']);
			} else if (isset($_GET['extensions'])) {
				// extension toggles (super admins only), first so uninstalling home/flags
				// works before the other branches
				if ($account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
					fancyDie(__('Access denied'));
				}
				$text .= handleExtensionsManage();
			} else if (isset($_GET['countries'])) {
				// only when countries (and flags, which it uses) are installed
				if (!$isadmin) {
					fancyDie(__('Access denied'));
				}
				if (!countriesAvailable()) {
					fancyDie(__('Country blocking is not installed. Install the Flags and Countries extensions from Manage → Extensions.'));
				}
				if (!function_exists('blockedCountriesSet')) {
					$text .= manageInfo(__('inc/extra/flags.php is missing.'));
				} else if (isset($_POST['country_action'])) {
					$current_blocked = blockedCountries();
					if ($_POST['country_action'] === 'add' && isset($_POST['codes']) && is_string($_POST['codes'])) {
						$added = array();
						foreach (preg_split('/[\s,;]+/', $_POST['codes'], -1, PREG_SPLIT_NO_EMPTY) as $c) {
							$c = strtoupper($c);
							if (preg_match('/^[A-Z]{2}$/', $c) && !in_array($c, $current_blocked, true)) {
								$added[] = $c;
							}
						}
						if (empty($added)) {
							$text .= manageInfo(__('Enter one or more new two-letter country codes.'));
						} else if (blockedCountriesSet(array_merge($current_blocked, $added))) {
							manageLogAction(sprintf(__('Blocked countries: %s'), implode(', ', $added)));
							$text .= manageInfo(sprintf(__('Blocked: %s'), implode(', ', $added)));
						} else {
							$text .= manageInfo(__('Failed to save the blocked countries. The install directory must be writable.'));
						}
					} else if ($_POST['country_action'] === 'remove' && isset($_POST['country_code']) && is_string($_POST['country_code'])) {
						$code = strtoupper($_POST['country_code']);
						if (blockedCountriesSet(array_diff($current_blocked, array($code)))) {
							manageLogAction(sprintf(__('Unblocked country: %s'), htmlentities($code)));
							$text .= manageInfo(sprintf(__('Unblocked: %s'), htmlentities($code)));
						} else {
							$text .= manageInfo(__('Failed to save the blocked countries. The install directory must be writable.'));
						}
					}
				}
				$onload = manageOnLoad('countries');
				$text .= manageCountriesPage();
			} else if (isset($_GET['home'])) {
				// only when home is installed
				if (!$isadmin) {
					fancyDie(__('Access denied'));
				}
				if (!extensionEnabled('home')) {
					fancyDie(__('The home page extension is not installed. Install it from Manage → Extensions.'));
				}
				$text .= manageHomeEntry();
			} elseif (isset($_GET['accounts'])) {
				if ($account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
					fancyDie(__('Access denied'));
				}

				$id = intval($_GET['accounts']);
				if (isset($_POST['id'])) {
					$id = intval($_POST['id']);
				}
				$a = array('id' => 0, 'board' => '*');
				if ($id > 0) {
					$a = accountByID($id);
					if (empty($a)) {
						fancyDie(__('Account not found.'));
					}

					if ($a['username'] == 'admin' && SCHAN_ADMINPASS != '') {
						fancyDie(__('This account may not be updated while SCHAN_ADMINPASS is set.'));
					} else if ($a['username'] == 'mod' && SCHAN_MODPASS != '') {
						fancyDie(__('This account may not be updated while SCHAN_MODPASS is set.'));
					}
				}

				if (isset($_POST['id'])) {
					if ($id == 0 && $_POST['password'] == '') {
						fancyDie(__('A password is required.'));
					}

					$prev = $a;

					$a['username'] = $_POST['username'];
					if ($_POST['password'] != '') {
						$a['password'] = $_POST['password'];
					}
					$a['role'] = intval($_POST['role']);
					if ($a['role'] != SCHAN_SUPER_ADMINISTRATOR && $a['role'] != SCHAN_ADMINISTRATOR && $a['role'] != SCHAN_MODERATOR && $a['role'] != SCHAN_DISABLED && $a['role'] != SCHAN_JANITOR && $a['role'] != SCHAN_FAKE) {
						fancyDie(__('Invalid role.'));
					}
					$posted_board = '';
					if (isset($_POST['account_board'])) {
						$posted_board = trim($_POST['account_board']);
					} else if (isset($_POST['board'])) {
						$posted_board = trim($_POST['board']);
					}
					if ($posted_board === '') {
						$posted_board = '*';
					}
					$a['board'] = $posted_board;
					if ($a['board'] !== '*') {
						if (!preg_match('/^[a-z0-9_-]{1,64}$/', $a['board']) || !boardBySlug($a['board'])) {
							fancyDie(__('Invalid board.'));
						}
					}

					if ($id == 0) {
						insertAccount($a);
						manageLogAction(sprintf(__('Added account %s'), $a['username']));
						$text .= manageInfo(__('Added account'));
					} else {
						updateAccount($a);
						if ($a['username'] != $prev['username']) {
							manageLogAction(sprintf(__('Renamed account %1$s as %2$s'), $prev['username'], $a['username']));
						}
						if ($a['password'] != $prev['password']) {
							manageLogAction(sprintf(__('Changed password of account %s'), $a['username']));
						}
						if ($a['role'] != $prev['role']) {
							$r = '';
							switch ($a['role']) {
								case  SCHAN_SUPER_ADMINISTRATOR:
									$r = __('Super-administrator');
									break;
								case  SCHAN_ADMINISTRATOR:
									$r = __('Administrator');
									break;
								case SCHAN_MODERATOR:
									$r = __('Moderator');
									break;
								case  SCHAN_DISABLED:
									$r = __('Disabled');
									break;
								case SCHAN_JANITOR:
									$r = __('Janitor');
									break;
								case SCHAN_FAKE:
									$r = __('Fake');
									break;
							}
							manageLogAction(sprintf(__('Changed role of account %s to %s'), $a['username'], $r));
						}
						$text .= manageInfo(__('Updated account'));
					}
				}

				$onload = manageOnLoad('accounts');
				$text .= manageAccountForm($_GET['accounts']);
				if (intval($_GET['accounts']) == 0) {
					$text .= manageAccountsTable();
				}
			} elseif (isset($_GET['boards'])) {
				if ($account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
					fancyDie(__('Access denied'));
				}

				$created_id = 0;
				$removed = false;
				$add_prefill = null;

				if (isset($_POST['board_action'])) {
					$action = $_POST['board_action'];

					if ($action === 'add') {
						$slug = isset($_POST['board_slug']) ? strtolower(trim($_POST['board_slug'])) : '';
						$title = isset($_POST['board_title']) ? $_POST['board_title'] : '';
						$desc = isset($_POST['board_desc']) ? $_POST['board_desc'] : '';
						$logo = isset($_POST['board_logo']) ? $_POST['board_logo'] : '';

						global $schan_reserved_slugs;

						$add_prefill = array('slug' => $slug, 'title' => $title, 'desc' => $desc, 'logo' => $logo);

						if (!preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) {
							$text .= manageInfo(__('Invalid slug. Use letters, numbers, underscore and hyphen only.'));
						} else if (in_array($slug, $schan_reserved_slugs)) {
							$text .= manageInfo(__('That slug is reserved.'));
						} else if (boardBySlug($slug)) {
							$text .= manageInfo(__('A board with that slug already exists.'));
						} else if (file_exists($slug) && !is_dir($slug)) {
							$text .= manageInfo(__('A file named that slug already exists in the install directory.'));
						} else {
							insertBoard(array('slug' => $slug, 'title' => $title, 'desc' => $desc, 'logo' => $logo, 'locked' => 0));
							foreach (array('', 'res/', 'src/', 'thumb/') as $sub) {
								$dir = $slug . '/' . $sub;
								if (!file_exists($dir)) {
									@mkdir($dir, 0755, true);
								}
							}
							$GLOBALS['schan_forced_board'] = $slug;
							writePage($slug . '/' . SCHAN_INDEX, buildPage('', 0, 0, 0, 0, $slug));
							$GLOBALS['schan_forced_board'] = null;

							$created_board = boardBySlug($slug);
							$created_id = !empty($created_board) ? intval($created_board['id']) : 0;
							$add_prefill = null;

							manageLogAction(sprintf(__('Created board %s'), htmlentities($slug)));
							$text .= manageInfo(sprintf(__('Created board %s.'), htmlentities($slug)));
						}
					} else if ($action === 'rename') {
						$id = intval($_POST['board_id']);
						$b = boardByID($id);
						if (empty($b)) {
							$text .= manageInfo(__('Board not found.'));
						} else {
							$b['title'] = isset($_POST['board_title']) ? $_POST['board_title'] : $b['title'];
							$b['desc'] = isset($_POST['board_desc']) ? $_POST['board_desc'] : $b['desc'];
							$b['logo'] = isset($_POST['board_logo']) ? $_POST['board_logo'] : $b['logo'];
							updateBoard($b);
							manageLogAction(sprintf(__('Renamed board %s'), htmlentities($b['slug'])));
							$GLOBALS['schan_forced_board'] = $b['slug'];
							rebuildIndexes($b['slug']);
							$GLOBALS['schan_forced_board'] = null;
							$text .= manageInfo(sprintf(__('Updated board %s.'), htmlentities($b['slug'])));
						}
					} else if ($action === 'lock') {
						$id = intval($_POST['board_id']);
						$b = boardByID($id);
						if (empty($b)) {
							$text .= manageInfo(__('Board not found.'));
						} else {
							$new_locked = intval($_POST['board_locked']) ? 1 : 0;
							$b['locked'] = $new_locked;
							updateBoard($b);

							$check = boardByID($id);
							if (intval($check['locked']) !== $new_locked) {
								$text .= manageInfo(sprintf(__('Failed to persist lock on board %s.'), htmlentities($b['slug'])));
							} else {
								manageLogAction(sprintf(__('Set lock on board %1$s to %2$s'), htmlentities($b['slug']), $new_locked ? '1' : '0'));
								$text .= manageInfo(sprintf(__('%1$s board %2$s.'), $new_locked ? __('Locked') : __('Unlocked'), htmlentities($b['slug'])));
							}
						}
					} else if ($action === 'flags') {
						// flags setting lives in flags.php, ignore if that extension is uninstalled
						$id = intval($_POST['board_id']);
						$b = boardByID($id);
						if (empty($b)) {
							$text .= manageInfo(__('Board not found.'));
						} else if (!function_exists('flagsSetEnabled')) {
							$text .= manageInfo(__('The flags extension is not installed. Install it from Manage → Extensions.'));
						} else {
							$new_flags = intval($_POST['board_flags']) ? true : false;
							if (!flagsSetEnabled($b['slug'], $new_flags)) {
								$text .= manageInfo(sprintf(__('Failed to save the flag setting for board %s. The install directory must be writable.'), htmlentities($b['slug'])));
							} else {
								manageLogAction(sprintf(__('Set flags on board %1$s to %2$s'), htmlentities($b['slug']), $new_flags ? '1' : '0'));
								$GLOBALS['schan_forced_board'] = $b['slug'];
								$allthreads = allThreads();
								foreach ($allthreads as $thread) {
									rebuildThread($thread['id'], $b['slug']);
								}
								rebuildIndexes($b['slug']);
								$GLOBALS['schan_forced_board'] = null;
								$text .= manageInfo(sprintf(__('%1$s flags on board %2$s.'), $new_flags ? __('Enabled') : __('Disabled'), htmlentities($b['slug'])));
							}
						}
					} else if ($action === 'news') {
						$id = intval($_POST['board_id']);
						$b = boardByID($id);
						if (empty($b)) {
							$text .= manageInfo(__('Board not found.'));
						} else if (!function_exists('newsBoardSet')) {
							$text .= manageInfo(__('The flags extension is not installed. Install it from Manage → Extensions.'));
						} else {
							$new_news = intval($_POST['board_news']) ? true : false;
							if (!newsBoardSet($b['slug'], $new_news)) {
								$text .= manageInfo(sprintf(__('Failed to save the news setting for board %s. The install directory must be writable.'), htmlentities($b['slug'])));
							} else {
								manageLogAction(sprintf(__('Set news board on %1$s to %2$s'), htmlentities($b['slug']), $new_news ? '1' : '0'));
								rebuildIndexes($b['slug']);
								$text .= manageInfo($new_news
									? sprintf(__('Board %s is now a news board: only administrators can start threads (from Staff Post); everyone can reply.'), htmlentities($b['slug']))
									: sprintf(__('Board %s is a normal board again.'), htmlentities($b['slug'])));
								if (!newsRebuildCache()) {
									$text .= manageInfo(__('news_cache.json could not be written, so news_highlight.php will not show any news. Make sure the install directory is writable by the web server user.'));
								}
							}
						}
					} else if ($action === 'layout') {
						$id = intval($_POST['board_id']);
						$b = boardByID($id);
						if (empty($b)) {
							$text .= manageInfo(__('Board not found.'));
						} else if (!function_exists('layoutFileboardSet')) {
							$text .= manageInfo(__('The flags extension is not installed. Install it from Manage → Extensions.'));
						} else {
							$new_layout = intval($_POST['board_layout']) ? true : false;
							if (!layoutFileboardSet($b['slug'], $new_layout)) {
								$text .= manageInfo(sprintf(__('Failed to save the layout setting for board %s. The install directory must be writable.'), htmlentities($b['slug'])));
							} else {
								manageLogAction(sprintf(__('Set fileboard layout on board %1$s to %2$s'), htmlentities($b['slug']), $new_layout ? '1' : '0'));
								rebuildIndexes($b['slug']);
								$text .= manageInfo(sprintf(__('Board %1$s now uses the %2$s layout.'), htmlentities($b['slug']), $new_layout ? __('fileboard') : __('standard')));
							}
						}
					} else if ($action === 'customindex') {
						$id = intval($_POST['board_id']);
						$b = boardByID($id);
						if (empty($b)) {
							$text .= manageInfo(__('Board not found.'));
						} else if (!function_exists('indexCustomSet')) {
							$text .= manageInfo(__('The flags extension is not installed. Install it from Manage → Extensions.'));
						} else {
							$new_custom = intval($_POST['board_customindex']) ? true : false;
							$index_file = boardOutputDirFor($b['slug'], false) . SCHAN_INDEX;
							if ($new_custom) {
								if (!is_file($index_file)) {
									rebuildIndexes($b['slug']);
								}
							} else if (is_file($index_file)) {
								@copy($index_file, $index_file . '.bak');
							}
							if (!indexCustomSet($b['slug'], $new_custom)) {
								$text .= manageInfo(sprintf(__('Failed to save the index setting for board %s. The install directory must be writable.'), htmlentities($b['slug'])));
							} else {
								manageLogAction(sprintf(__('Set custom index on board %1$s to %2$s'), htmlentities($b['slug']), $new_custom ? '1' : '0'));
								if ($new_custom) {
									$text .= manageInfo(sprintf(__('Board %1$s now has a custom index. %2$s is no longer rebuilt; edit it directly.'), htmlentities($b['slug']), htmlentities($b['slug'] . '/' . SCHAN_INDEX)));
								} else {
									rebuildIndexes($b['slug']);
									$text .= manageInfo(sprintf(__('Board %1$s now has an automatic index again. Your previous index was saved as %2$s.'), htmlentities($b['slug']), htmlentities($b['slug'] . '/' . SCHAN_INDEX . '.bak')));
								}
							}
						}
					} else if ($action === 'globalmessage') {
						if (!function_exists('globalMessageSet') || !extensionEnabled('globalmessage')) {
							$text .= manageInfo(__('The global message extension is not installed. Install it from Manage → Extensions.'));
						} else if (!globalMessageSet(isset($_POST['global_message']) ? $_POST['global_message'] : '')) {
							$text .= manageInfo(__('Failed to save the global message. The install directory must be writable.'));
						} else {
							@set_time_limit(0);
							manageLogAction(__('Updated the global message'));
							foreach (allBoards() as $b) {
								if ($b['slug'] === (defined('SCHAN_OVERBOARD') ? SCHAN_OVERBOARD : '')) {
									continue;
								}
								$GLOBALS['schan_forced_board'] = $b['slug'];
								foreach (allThreads() as $thread) {
									rebuildThread($thread['id'], $b['slug']);
								}
								rebuildIndexes($b['slug']);
								$GLOBALS['schan_forced_board'] = null;
							}
							$text .= manageInfo(globalMessageGet() === '' ? __('Global message removed and all boards rebuilt.') : __('Global message saved and all boards rebuilt.'));
						}
					} else if ($action === 'remove') {
						$id = intval($_POST['board_id']);
						$b = boardByID($id);
						if (empty($b)) {
							$text .= manageInfo(__('Board not found.'));
						} else if ($b['slug'] === SCHAN_DEFAULTBOARD) {
							$text .= manageInfo(__('The default board cannot be removed.'));
						} else {
							$slug = $b['slug'];

							deletePostsByBoard($slug);
							deleteReportsByBoard($slug);
							globalizeBansByBoard($slug);
							globalizeKeywordsByBoard($slug);
							globalizeLogsByBoard($slug);
							deleteBoardByID($id);

							$folder_gone = removeBoardFolder($slug);

							$removed = true;
							manageLogAction(sprintf(__('Removed board %s'), htmlentities($slug)));
							$text .= manageInfo(sprintf(__('Removed board %s.'), htmlentities($slug)));
							if (!$folder_gone) {
								$text .= manageInfo(sprintf(__('The folder "%s" could not be removed completely. Check its permissions and delete it manually.'), htmlentities($slug)));
							}
						}
					}
				}

				$onload = manageOnLoad('boards');

				$view_id = isset($_GET['boards']) ? intval($_GET['boards']) : 0;
				if ($created_id > 0) {
					$text .= manageBoardsPage('edit', $created_id);
				} else if ($removed) {
					$text .= manageBoardsPage('list');
				} else if (isset($_GET['new'])) {
					$text .= manageBoardsPage('new', 0, $add_prefill);
				} else if ($view_id > 0) {
					$text .= manageBoardsPage('edit', $view_id);
				} else {
					$text .= manageBoardsPage('list');
				}
			} elseif (isset($_GET['bans'])) {
				clearExpiredBans();

				if (isset($_POST['ip']) && $_POST['ip'] != '') {
					$ips = explode(',', $_POST['ip']);
					foreach ($ips as $ip) {
						$banexists = banByIP($ip);
						if ($banexists) {
							continue;
						}

						if (SCHAN_REPORT) {
							deleteReportsByIP($ip);
						}

						$ban = array();
						$ban['ip'] = $ip;
						$ban_expire = isset($_POST['expire']) ? intval($_POST['expire']) : 0;
						$ban['expire'] = ($ban_expire > 0) ? (time() + $ban_expire) : 0;
						$ban['reason'] = $_POST['reason'];
						$ban['board'] = '*';

						$until = __('permanently');
						if ($ban['expire'] > 0) {
							$until = sprintf(__('until %s'), formatDate($ban['expire']));
						}
						$action = sprintf(__('Banned %s %s'), htmlentities($ban['ip']), $until);
						if ($ban['reason'] != '') {
							$action = sprintf(__('Banned %s %s: %s'), htmlentities($ban['ip']), $until, htmlentities($ban['reason']));
						}

						insertBan($ban);
						manageLogAction($action);
					}
					if (SCHAN_BANMESSAGE && isset($_POST['message']) && $_POST['message'] != '' && isset($_GET['posts']) && $_GET['posts'] != '') {
						$post_ids = explode(',', $_GET['posts']);
						foreach ($post_ids as $post_id) {
							$post = postByID($post_id);
							if (!$post) {
								continue;
							}
							updatePostMessage($post['id'], $post['message'] . '<br>' . "\n" . '<span class="banmessage">(' . htmlentities($_POST['message']) . ')</span><br>');
							manageLogAction(sprintf(__('Added ban message to %s'), postLink('&gt;&gt;' . $post['id'])));
						}
						clearPostCache();
						foreach ($post_ids as $post_id) {
							$post = postByID($post_id);
							if (!$post) {
								continue;
							}
							threadUpdated(getParent($post));
						}
					}
					if (count($ips) == 1) {
						$text .= manageInfo(__('Banned 1 IP address'));
					} else {
						$text .= manageInfo(sprintf(__('Banned %d IP addresses'), count($ips)));
					}
				} elseif (isset($_GET['lift'])) {
					$ban = banByID($_GET['lift']);
					if ($ban) {
						deleteBanByID($_GET['lift']);
						if (function_exists('appealsRemove')) {
							appealsRemove($_GET['lift']);
						}
						$info = sprintf(__('Lifted ban on %s'), htmlentities($ban['ip']));
						manageLogAction($info);
						$text .= manageInfo($info);
					}
				}

				$onload = manageOnLoad('bans');
				$text .= manageBanForm();
				$text .= manageBansTable();
			} elseif (isset($_GET['pages'])) {
				if (!$isadmin) {
					fancyDie(__('Access denied'));
				}
				$edit_page = null;
				$all_pages = pagesLoad();
				if (isset($_POST['page_action']) && is_string($_POST['page_action'])) {
					$page_action = $_POST['page_action'];
					$slug = isset($_POST['page_slug']) ? strtolower(trim((string)$_POST['page_slug'])) : '';
					if ($page_action === 'save') {
						$is_new = !empty($_POST['page_new']);
						$title = trim(isset($_POST['page_title']) ? (string)$_POST['page_title'] : '');
						$form = array(
							'slug' => $slug, 'title' => $title,
							'content' => str_replace("\r\n", "\n", isset($_POST['page_content']) ? (string)$_POST['page_content'] : ''),
							'css' => str_replace("\r\n", "\n", isset($_POST['page_css']) ? (string)$_POST['page_css'] : ''),
							'header' => empty($_POST['page_header']) ? 0 : 1,
							'banner' => empty($_POST['page_banner']) ? 0 : 1,
							'boardbar' => empty($_POST['page_boardbar']) ? 0 : 1,
						);
						$error = '';
						if ($title === '') {
							$error = __('Please give the page a title.');
						} else if ($is_new && isset($all_pages[$slug])) {
							$error = sprintf(__('A page called "%s" already exists.'), htmlentities($slug));
						} else if (!$is_new && !isset($all_pages[$slug])) {
							$error = __('That page does not exist.');
						} else {
							$error = pageSlugError($slug, !$is_new);
						}
						if ($error !== '') {
							$text .= manageInfo($error);
							$GLOBALS['schan_page_form'] = $form;
							$edit_page = $is_new ? '' : $slug;
						} else {
							$old = $is_new ? array() : $all_pages[$slug];
							$all_pages[$slug] = array(
								'title' => $title, 'content' => $form['content'], 'css' => $form['css'],
								'header' => $form['header'], 'banner' => $form['banner'], 'boardbar' => $form['boardbar'],
								'created' => isset($old['created']) ? $old['created'] : time(), 'updated' => time(), 'built' => 0,
							);
							if (!pagesSave($all_pages)) {
								$text .= manageInfo(__('The page could not be saved. The install directory must be writable.'));
								$GLOBALS['schan_page_form'] = $form;
								$edit_page = $is_new ? '' : $slug;
							} else {
								rebuildPages(true);
								manageLogAction(sprintf(__('Saved page %s'), htmlentities($slug)));
								$text .= manageInfo(sprintf(__('Page saved: %s'), '<a href="' . htmlentities(installRootURL() . '/' . $slug . '.html', ENT_QUOTES) . '" target="_blank">' . htmlentities($slug) . '.html</a>'));
							}
						}
					} else if ($page_action === 'delete' && isset($all_pages[$slug])) {
						unset($all_pages[$slug]);
						if (pagesSave($all_pages)) {
							@unlink($slug . '.html');
							manageLogAction(sprintf(__('Deleted page %s'), htmlentities($slug)));
							$text .= manageInfo(sprintf(__('Page %s deleted.'), htmlentities($slug)));
						} else {
							$text .= manageInfo(__('The page could not be deleted. The install directory must be writable.'));
						}
					} else if ($page_action === 'rebuild') {
						rebuildPages(true);
						$text .= manageInfo(__('Rebuilt all pages.'));
					}
				} else if (isset($_GET['pagenew'])) {
					$edit_page = '';
				} else if (isset($_GET['pageedit']) && is_string($_GET['pageedit']) && isset($all_pages[$_GET['pageedit']])) {
					$edit_page = $_GET['pageedit'];
				}
				$text .= manageStaticPagesPage($edit_page);
			} elseif (isset($_GET['keywords'])) {
				if (isset($_POST['text']) && $_POST['text'] != '') {
					if ($_GET['keywords'] > 0) {
						deleteKeyword($_GET['keywords']);
					}

					$keyword_exists = keywordByText($_POST['text']);
					if ($keyword_exists) {
						fancyDie(__('Sorry, that keyword has already been added.'));
					}

					$keyword = array();
					$keyword['text'] = $_POST['text'];
					$keyword['action'] = $_POST['action'];
					$keyword['board'] = '*';

					$kw = $keyword['text'];

					if (isset($_POST['regexp']) && $_POST['regexp'] == '1') {
						$keyword['text'] = 'regexp:' . $keyword['text'];
					}

					insertKeyword($keyword);
					if ($_GET['keywords'] > 0) {
						manageLogAction(sprintf(__('Updated keyword %s'), htmlentities($kw)));
						$text .= manageInfo(__('Keyword updated.'));
						$_GET['keywords'] = 0;
					} else {
						manageLogAction(sprintf(__('Added keyword %s'), htmlentities($kw)));
						$text .= manageInfo(__('Keyword added.'));
					}
				} elseif (isset($_GET['deletekeyword'])) {
					$keyword = keywordByID($_GET['deletekeyword']);
					if (empty($keyword)) {
						fancyDie(__('That keyword does not exist.'));
					}

					$kw = $keyword['text'];
					if (substr($keyword['text'], 0, 7) == 'regexp:') {
						$kw = substr($keyword['text'], 7);
					}

					deleteKeyword($_GET['deletekeyword']);
					manageLogAction(sprintf(__('Deleted keyword %s'), htmlentities($kw)));
					$text .= manageInfo(__('Keyword deleted.'));
				}

				$onload = manageOnLoad('keywords');
				if ($_GET['keywords'] > 0) {
					$text .= manageEditKeyword($_GET['keywords']);
				} else {
					$text .= manageEditKeyword(0);
					$text .= manageKeywordsTable();
				}
			} elseif (isset($_GET['config'])) {
				if ($account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
					fancyDie(__('Access denied'));
				}
				$text .= handleConfigManage();
				$text .= manageConfigPage();
			} elseif (isset($_GET['emoji'])) {
				if (!function_exists('handleEmojiManage') || !extensionEnabled('emoji')) {
					fancyDie(__('The emoji extension is not installed. Install it from Manage → Extensions.'));
				}
				$text .= handleEmojiManage();
				$text .= manageEmojiPage();
			} elseif (isset($_GET['import'])) {
				$text .= handleImportManage();
				$onload = '';
			} elseif (isset($_GET['dbmigrate'])) {
				if (SCHAN_DBMIGRATE !== '' && SCHAN_DBMIGRATE !== false && SCHAN_DBMODE != SCHAN_DBMIGRATE) {
					$mysql_modes = array('mysql', 'mysqli');
					if (in_array(SCHAN_DBMODE, $mysql_modes) && in_array(SCHAN_DBMIGRATE, $mysql_modes)) {
						fancyDie('SCHAN_DBMODE and SCHAN_DBMIGRATE are both set to MySQL database modes. No migration is necessary.');
					}

					$sqlite_modes = array('sqlite', 'sqlite3');
					if (in_array(SCHAN_DBMODE, $sqlite_modes) && in_array(SCHAN_DBMIGRATE, $sqlite_modes)) {
						fancyDie('SCHAN_DBMODE and SCHAN_DBMIGRATE are both set to SQLite database modes. No migration is necessary.');
					}

					if (!in_array(SCHAN_DBMIGRATE, $database_modes)) {
						fancyDie(__('Unknown database mode specified.'));
					}

					if (isset($_GET['go'])) {
						require 'inc/database/' . SCHAN_DBMIGRATE . '_link.php';

						echo '<p>Migrating accounts...</p>';
						$accounts = allAccounts();
						foreach ($accounts as $account) {
							migrateAccount($account);
						}

						echo '<p>Migrating bans...</p>';
						$bans = allBans();
						foreach ($bans as $ban) {
							migrateBan($ban);
						}

						echo '<p>Migrating keywords...</p>';
						$keywords = allKeywords();
						foreach ($keywords as $keyword) {
							migrateKeyword($keyword);
						}

						echo '<p>Migrating logs...</p>';
						$logs = allLogs();
						foreach ($logs as $log) {
							migrateLog($log);
						}

						echo '<p>Migrating posts...</p>';
						$threads = allThreads();
						foreach ($threads as $thread) {
							$posts = postsInThreadByID($thread['id']);
							foreach ($posts as $post) {
								migratePost($post);
							}
						}

						echo '<p>Migrating reports...</p>';
						$reports = allReports();
						foreach ($reports as $report) {
							migrateReport($report);
						}

						echo '<p><b>Database migration complete</b>.  Set SCHAN_DBMODE to the new database mode and SCHAN_DBMIGRATE to false, then click <b>Rebuild All</b> above and ensure everything looks and works as it should.</p>';
					} else {
						$text .= '<p>Your original database will not be deleted.  If the migration fails, disable the tool and your board will be unaffected.  See the <a href="https://codeberg.org/tslocum/schan/src/branch/master/README.md" target="_blank">README</a> <small>(<a href="README.md" target="_blank">alternate link</a>)</small> for instructions.</a><br><br><a href="?dbmigrate&go"><b>Start the migration</b></a></p>';
					}
				} else {
					fancyDie('Set SCHAN_DBMIGRATE to the desired SCHAN_DBMODE and enter in any database related settings in settings.php before migrating.');
				}
			}
		}

		if (isset($_GET['delete'])) {
			$post_ids = explode(',', $_GET['delete']);
			$posts = array();
			foreach ($post_ids as $post_id) {
				$post = postByID($post_id);
				if (!$post) {
					continue;
				}
				$posts[$post_id] = $post;
			}
			$deleted_count = 0;
			foreach ($post_ids as $post_id) {
				if (!isset($posts[$post_id])) {
					continue;
				}
				$post = $posts[$post_id];
				$deleted_count++;

				removePostFiles($post, $active_board);
				deletePost($post['id']);
				if ($post['parent'] == SCHAN_NEWTHREAD) {
					rebuildThread($post['id'], $active_board);
				} else {
					rebuildThread($post['parent'], $active_board);
				}

				$action = sprintf(__('Deleted %s'),'&gt;&gt;' . $post['id']) . ' - ' . htmlentities($post['ip'], ENT_QUOTES);
				$stripped = strip_tags($post['message']);
				if ($stripped != '') {
					$action .= ' - ' . htmlentities(_substr($stripped, 0, 32));
					if (_strlen($stripped) > 32) {
						$action .= '...';
					}
				}
				manageLogAction($action);
			}
			rebuildIndexes($active_board);
			if ($deleted_count == 1) {
				$text .= manageInfo(__('Deleted 1 post'));
			} else if ($deleted_count > 1) {
				$text .= manageInfo(sprintf(__('Deleted %d posts'), $deleted_count));
			}
			if ($deleted_count < count($post_ids)) {
				$text .= manageInfo(sprintf(__('%1$d post(s) were not found on board %2$s and were not deleted.'), count($post_ids) - $deleted_count, htmlentities($active_board)));
			}
		} elseif (isset($_GET['deletefile'])) {
			$post = postByID(intval($_GET['deletefile']));
			if (!$post) {
				fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
			}
			$file_board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : $active_board;
			$extra_files = function_exists('mediaExtrasFor') ? mediaExtrasFor($file_board, $post['id']) : array();
			if ($post['file'] == '' && empty($extra_files)) {
				$text .= manageInfo(__('That post has no file.'));
			} else {
				$post['board'] = $file_board;
				deletePostImages($post);
				clearPostFileByID($post['id']);
				if (function_exists('pendingClear')) {
					pendingClear($post['id'], 'media');
				}
				if (function_exists('spoilerClear')) {
					spoilerClear($post['id']);
				}
				$use_placeholder = isset($_GET['placeholder']) && $_GET['placeholder'] != '0';
				if (function_exists('postEditsSet')) {
					postEditsSet($post['id'], array('deleted' => $use_placeholder ? 1 : null));
				}
				threadUpdated($post['parent'] == SCHAN_NEWTHREAD ? $post['id'] : $post['parent']);
				manageLogAction(sprintf(__('Deleted file of %s'), '&gt;&gt;' . $post['id']));
				$text .= manageInfo(sprintf($use_placeholder ? __('Deleted the file of post No.%d; deleted.png is shown in its place.') : __('Deleted the file of post No.%d.'), $post['id']));
			}
		} elseif (isset($_GET['spoiler'])) {
			if ((function_exists('roleIsJanitor') && roleIsJanitor($account)) || (function_exists('roleIsFake') && roleIsFake($account))) {
				fancyDie(__('You do not have permission to do that.'));
			}
			$post = postByID(intval($_GET['spoiler']));
			if (!$post) {
				fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
			}
			if ($post['file'] == '' || !function_exists('spoilerSet')) {
				$text .= manageInfo(__('That post has no file.'));
			} else {
				$make_spoiler = isset($_GET['setspoiler']) && $_GET['setspoiler'] != '0';
				if ($make_spoiler) {
					spoilerSet($post['id']);
				} else {
					spoilerClear($post['id']);
				}
				if (function_exists('clearPostCache')) {
					clearPostCache();
				}
				threadUpdated($post['parent'] == SCHAN_NEWTHREAD ? $post['id'] : $post['parent']);
				manageLogAction(sprintf($make_spoiler ? __('Spoilered the file of %s') : __('Removed the spoiler from the file of %s'), '&gt;&gt;' . $post['id']));
				$text .= manageInfo(sprintf($make_spoiler ? __('The file of post No.%d is now spoilered.') : __('The file of post No.%d is no longer spoilered.'), $post['id']));
			}
		} elseif (isset($_GET['approve'])) {
			if ($_GET['approve'] > 0) {
				$post = postByID($_GET['approve']);
				if ($post) {
					approvePostByID($post['id'], 2);
					if (function_exists('pendingClear')) {
						pendingClear($post['id']);
					}
					$thread_id = $post['parent'] == SCHAN_NEWTHREAD ? $post['id'] : $post['parent'];

					if (strtolower($post['email']) != 'sage' && (SCHAN_MAXREPLIES == 0 || numRepliesToThreadByID($thread_id) <= SCHAN_MAXREPLIES)) {
						bumpThreadByID($thread_id);
					}
					threadUpdated($thread_id);

					manageLogAction(__('Approved') . ' ' . postLink('&gt;&gt;' . $post['id']));
					$text .= manageInfo(sprintf(__('Post No.%d approved.'), $post['id']));
				} else {
					fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
				}
			}
		} elseif (isset($_GET['approvemedia']) || isset($_GET['approvelinks'])) {
			$pending_what = isset($_GET['approvemedia']) ? 'media' : 'links';
			$pending_id = intval($pending_what === 'media' ? $_GET['approvemedia'] : $_GET['approvelinks']);
			$post = $pending_id > 0 ? postByID($pending_id) : null;
			if ($post) {
				if (function_exists('pendingClear')) {
					pendingClear($post['id'], $pending_what);
				}
				threadUpdated($post['parent'] == SCHAN_NEWTHREAD ? $post['id'] : $post['parent']);

				$pending_label = $pending_what === 'media' ? __('media') : __('links');
				manageLogAction(sprintf(__('Approved %s on'), $pending_label) . ' ' . postLink('&gt;&gt;' . $post['id']));
				$text .= manageInfo(sprintf(__('Approved %1$s on post No.%2$d.'), $pending_label, $post['id']));
			} else {
				fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
			}
		} elseif (isset($_GET['edit'])) {
			if ((function_exists('roleIsJanitor') && roleIsJanitor($account)) || (function_exists('roleIsFake') && roleIsFake($account))) {
				fancyDie(__('You do not have permission to edit posts.'));
			}
			$post = postByID($_GET['edit']);
			if (!$post) {
				fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
			}
			$post['board'] = current_board();
			if (isset($_POST['name'], $_POST['subject']) && is_string($_POST['name']) && is_string($_POST['subject']) && function_exists('postEditsSet')) {
				$cur_meta = postEditsApply($post);
				$new_name = trim($_POST['name']);
				$new_subject = trim($_POST['subject']);
				if (SCHAN_MAXNAME > 0) {
					$new_name = _substr($new_name, 0, SCHAN_MAXNAME);
				}
				if (SCHAN_MAXSUBJECT > 0) {
					$new_subject = _substr($new_subject, 0, SCHAN_MAXSUBJECT);
				}
				if ($new_name === '') {
					$new_name = $schan_anonymous[0];
				}
				$new_name = cleanString($new_name);
				$new_subject = cleanString($new_subject);
				if ($new_name !== $cur_meta['name'] || $new_subject !== $cur_meta['subject']) {
					if (postEditsSet($post['id'], array('name' => $new_name, 'subject' => $new_subject))) {
						manageLogAction(sprintf(__('Edited name/subject of %s'), postLink('&gt;&gt;' . $post['id'])));
						if (function_exists('clearPostCache')) {
							clearPostCache();
						}
						threadUpdated(getParent($post));
						$text .= manageInfo(__('Name and subject updated.'));
					} else {
						$text .= manageInfo(__('The name and subject could not be saved. The install directory must be writable.'));
					}
				}
			}
			if (isset($_POST['message']) && is_string($_POST['message'])) {
				$new_message = trim(str_replace(array("\r\n", "\r"), "\n", $_POST['message']));
				$new_message = str_replace("\n", '<br>', $new_message);
				if ($new_message === '' && $post['file'] == '') {
					$text .= manageInfo(__('The message can not be empty. Delete the post instead.'));
				} else {
					if (!empty($_POST['editednote']) && strpos($new_message, 'class="editednote"') === false) {
						$new_message .= '<br><span class="editednote">(' . __('edited by staff') . ')</span>';
					}
					updatePostMessage($post['id'], $new_message);
					manageLogAction(sprintf(__('Edited %s'), postLink('&gt;&gt;' . $post['id'])));
					clearPostCache();
					threadUpdated(getParent($post));
					$post = postByID($post['id']);
					$post['board'] = current_board();
					$text .= manageInfo(__('Post updated.'));
				}
			}
			$text .= manageEditPostForm($post);
		} elseif (isset($_GET['sticky']) && isset($_GET['setsticky'])) {
			if ($_GET['sticky'] > 0) {
				$post = postByID($_GET['sticky']);
				if ($post && $post['parent'] == SCHAN_NEWTHREAD) {
					stickyThreadByID($post['id'], intval($_GET['setsticky']));
					threadUpdated($post['id']);

					$actionMessage = (intval($_GET['setsticky']) == 1 ? __('Stickied') : __('Unstickied')) . ' ' . postLink('&gt;&gt;' . $post['id']);
					manageLogAction($actionMessage);
					$text .= manageInfo($actionMessage);
				} else {
					fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
				}
			} else {
				fancyDie(__('Form data was lost. Please go back and try again.'));
			}
		} elseif (isset($_GET['lock']) && isset($_GET['setlock'])) {
			if ($_GET['lock'] > 0) {
				$post = postByID($_GET['lock']);
				if ($post && $post['parent'] == SCHAN_NEWTHREAD) {
					lockThreadByID($post['id'], intval($_GET['setlock']));
					threadUpdated($post['id']);

					$actionMessage = (intval($_GET['setlock']) == 1 ? __('Locked') : __('Unlocked')) . ' ' . postLink('&gt;&gt;' . $post['id']);
					manageLogAction($actionMessage);
					$text .= manageInfo($actionMessage);
				} else {
					fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
				}
			} else {
				fancyDie(__('Form data was lost. Please go back and try again.'));
			}
		} elseif (isset($_GET['trustip'])) {
			$post = postByID(intval($_GET['trustip']));
			if (!$post) {
				fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
			}
			if (!function_exists('trustedIPSet')) {
				$text .= manageInfo(__('inc/extra/trust.php is missing.'));
			} else {
				$trust_board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : $active_board;
				$trust_on = isset($_GET['settrust']) && intval($_GET['settrust']) ? true : false;
				if (!trustedIPSet($trust_board, $post['ip'], $trust_on, $account['username'])) {
					$text .= manageInfo(__('Failed to save the trusted IP list. The install directory must be writable.'));
				} else {
					$trust_msg = $trust_on
						? sprintf(__('Trusted the poster of %1$s on board %2$s: their posts no longer need image or link approval.'), '&gt;&gt;' . $post['id'], htmlentities($trust_board))
						: sprintf(__('No longer trusting the poster of %1$s on board %2$s.'), '&gt;&gt;' . $post['id'], htmlentities($trust_board));
					manageLogAction($trust_msg);
					$text .= manageInfo($trust_msg);
				}
			}
		} elseif (isset($_GET['clearreports'])) {
			if ($_GET['clearreports'] > 0) {
				$post = postByID($_GET['clearreports']);
				if ($post) {
					approvePostByID($post['id'], 2);
					if (function_exists('pendingClear')) {
						pendingClear($post['id']);
					}
					deleteReportsByPost($post['id']);

					manageLogAction(__('Approved') . ' ' . postLink('&gt;&gt;' . $post['id']));
					$text .= manageInfo(sprintf(__('Post No.%d approved.'), $post['id']));
				} else {
					fancyDie(__("Sorry, there doesn't appear to be a post with that ID."));
				}
			}
		} elseif (isset($_GET["staffpost"])) {
			$onload = manageOnLoad("staffpost");
			$text .= buildPostForm(0, true, $active_board);
		} elseif (isset($_GET['changepassword'])) {
			if ($account['username'] == 'admin' && SCHAN_ADMINPASS != '') {
				fancyDie(__('This account may not be updated while SCHAN_ADMINPASS is set.'));
			} else if ($account['username'] == 'mod' && SCHAN_MODPASS != '') {
				fancyDie(__('This account may not be updated while SCHAN_MODPASS is set.'));
			}

			if (isset($_POST['password']) && isset($_POST['confirm'])) {
				if ($_POST['password'] == '') {
					fancyDie(__('A password is required.'));
				} else if ($_POST['password'] != $_POST['confirm']) {
					fancyDie(__('Passwords do not match.'));
				}

				$account['password'] = $_POST['password'];
				updateAccount($account);

				$text .= manageInfo(__('Password updated'));
			}

			$text .= manageChangePasswordForm();
		}

		if (isset($_GET['pendingreturn']) && $isadmin && !isset($_GET['pending']) && function_exists('managePendingPage')
			&& !(function_exists('roleIsJanitor') && roleIsJanitor($account))) {
			$text .= managePendingPage();
		}

		if ($text == '' && defined('SCHAN_MODVIEW') && $active_board !== '' && !(function_exists('roleIsFake') && roleIsFake($account))) {
			$own_page = false;
			foreach (array('accounts', 'boards', 'config', 'bans', 'keywords', 'countries', 'pages', 'home', 'extensions', 'emoji', 'import', 'dbmigrate', 'dashboard', 'modlog', 'reports', 'rebuildall', 'edit', 'changepassword', 'staffpost', 'pending') as $k) {
				if (isset($_GET[$k])) {
					$own_page = true;
				}
			}
			if (!$own_page) {
				die('--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="0;url=' . modBoardURL($active_board) . '">');
			}
		}

		if ($text == '') {
			if (function_exists('roleIsFake') && roleIsFake($account)) {
				$onload = manageOnLoad('staffpost');
				$text = buildPostForm(0, true, $active_board);
			} elseif (!$redirect) {
				$GLOBALS['schan_manage_dashboard'] = true;
				$text = manageDashboard();
			}
		}
	} else {
		$onload = manageOnLoad('login');
		$text .= manageLogInForm();
	}

	echo managePage($text, $onload);
} elseif ($active_board !== '' && (!file_exists($active_board . '/' . SCHAN_INDEX) || countThreads() == 0)) {
	rebuildIndexes($active_board);
}

if ($redirect) {
	$target = is_string($redirect) ? $redirect : ($active_board . '/' . SCHAN_INDEX);
	echo '--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="' . (isset($slow_redirect) ? '3' : '0') . ';url=' . $target . '">';
}

// deletes a post's files from its board folder (file, thumb, poll). for an OP that covers every
// reply too and removes the thread's res page and json
// call this BEFORE deletePost(), while the posts still exist in the db
function removePostFiles($post, $board) {
	if (!is_string($board) || !preg_match('/^[a-z0-9_-]{1,64}$/', $board) || empty($post)) {
		return;
	}

	$posts = array($post);
	if ($post['parent'] == SCHAN_NEWTHREAD) {
		$thread = postsInThreadByID($post['id']);
		if (!empty($thread)) {
			$posts = $thread;
		}
	}

	foreach ($posts as $p) {
		if (!empty($p['file']) && !(function_exists('isEmbed') && isEmbed($p['file_hex']))) {
			@unlink($board . '/src/' . basename($p['file']));
		}
		if (!empty($p['thumb'])) {
			@unlink(thumbWritePath($p['thumb'], $board));
			@unlink(thumbLegacyDir() . basename($p['thumb']));
			@unlink($board . '/thumb/' . basename($p['thumb']));
		}
		if (function_exists('pollDelete')) {
			pollDelete($p['id'], $board);
		}
	}

	if ($post['parent'] == SCHAN_NEWTHREAD) {
		@unlink($board . '/res/' . intval($post['id']) . '.html');
		@unlink($board . '/res/' . intval($post['id']) . '.json');
	}
}

// removes a board's whole folder (res, src, thumb, polls, index pages, everything), true if it's gone
function removeBoardFolder($slug) {
	if (!is_string($slug) || !preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) {
		return false;
	}
	$root = realpath('.');
	$dir = realpath($slug);
	if ($dir === false) {
		return true;
	}
	if ($root === false || dirname($dir) !== $root || !is_dir($dir)) {
		return false;
	}

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($it as $f) {
		$path = $f->getPathname();
		if ($f->isLink() || !$f->isDir()) {
			if (!@unlink($path)) {
				@chmod($path, 0666);
				@unlink($path);
			}
		} else if (!@rmdir($path)) {
			@chmod($path, 0777);
			@rmdir($path);
		}
	}
	if (!@rmdir($dir)) {
		@chmod($dir, 0777);
		@rmdir($dir);
	}
	clearstatcache();
	return !file_exists($dir);
}
