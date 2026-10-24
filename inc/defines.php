<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

define('SCHAN_NEWTHREAD', '0');
define('SCHAN_INDEXPAGE', false);
define('SCHAN_RESPAGE', true);
define('SCHAN_LOCKFILE', 'schan.lock');
define('SCHAN_WORDBREAK_IDENTIFIER', '@!@SCHAN_WORDBREAK@!@');

// roles
define('SCHAN_SUPER_ADMINISTRATOR', 1);
define('SCHAN_ADMINISTRATOR', 2);
define('SCHAN_MODERATOR', 3);
define('SCHAN_DISABLED', 99);

// fallbacks for old installs, don't rely on these
// copy new settings from settings.default.php into settings.php
if (!defined('SCHAN_LOCALE')) {
	define('SCHAN_LOCALE', '');
}
if (!defined('SCHAN_BOARDTITLE')) {
	define('SCHAN_BOARDTITLE', '');
}
if (!defined('SCHAN_MANAGEKEY')) {
	define('SCHAN_MANAGEKEY', '');
}
if (!defined('SCHAN_INDEX')) {
	define('SCHAN_INDEX', 'index.html');
}
if (!defined('SCHAN_MAXREPLIES')) {
	define('SCHAN_MAXREPLIES', 0);
}
if (!defined('SCHAN_MAXNAME')) {
	define('SCHAN_MAXNAME', 75);
}
if (!defined('SCHAN_MAXEMAIL')) {
	define('SCHAN_MAXEMAIL', 320);
}
if (!defined('SCHAN_MAXSUBJECT')) {
	define('SCHAN_MAXSUBJECT', 75);
}
if (!defined('SCHAN_MAXMESSAGE')) {
	define('SCHAN_MAXMESSAGE', 8000);
}
if (!defined('SCHAN_MAXWOP')) {
	define('SCHAN_MAXWOP', SCHAN_MAXW);
}
if (!defined('SCHAN_MAXHOP')) {
	define('SCHAN_MAXHOP', SCHAN_MAXH);
}
if (!defined('SCHAN_THUMBNAIL')) {
	define('SCHAN_THUMBNAIL', 'gd');
}
if (!defined('SCHAN_UPLOADVIAURL')) {
	define('SCHAN_UPLOADVIAURL', false);
}
if (!defined('SCHAN_STRIPMETADATA')) {
	define('SCHAN_STRIPMETADATA', false);
}
if (!defined('SCHAN_NOFILEOK')) {
	define('SCHAN_NOFILEOK', false);
}
if (!defined('SCHAN_CAPTCHA')) {
	define('SCHAN_CAPTCHA', '');
}
if (!defined('SCHAN_REPLYCAPTCHA')) {
	define('SCHAN_REPLYCAPTCHA', SCHAN_CAPTCHA);
}
if (!defined('SCHAN_REPORTCAPTCHA')) {
	define('SCHAN_REPORTCAPTCHA', '');
}
if (!defined('SCHAN_MANAGECAPTCHA')) {
	define('SCHAN_MANAGECAPTCHA', '');
}
if (!defined('SCHAN_REPORT')) {
	define('SCHAN_REPORT', false);
}
if (!defined('SCHAN_AUTOHIDE')) {
	define('SCHAN_AUTOHIDE', 0);
}
if (!defined('SCHAN_REQMOD')) {
	define('SCHAN_REQMOD', '');
}
if (!defined('SCHAN_BANMESSAGE')) {
	define('SCHAN_BANMESSAGE', true);
}
if (!defined('SCHAN_UPDATEBUMPED')) {
	define('SCHAN_UPDATEBUMPED', true);
}
if (!defined('SCHAN_SPOILERTEXT')) {
	define('SCHAN_SPOILERTEXT', false);
}
if (!defined('SCHAN_SPOILERIMAGE')) {
	define('SCHAN_SPOILERIMAGE', false);
}
if (!defined('SCHAN_AUTOREFRESH')) {
	define('SCHAN_AUTOREFRESH', 30);
}
if (!defined('SCHAN_CLOUDFLARE')) {
	define('SCHAN_CLOUDFLARE', false);
}
if (!defined('SCHAN_DISALLOWTHREADS')) {
	define('SCHAN_DISALLOWTHREADS', '');
}
if (!defined('SCHAN_DISALLOWREPLIES')) {
	define('SCHAN_DISALLOWREPLIES', '');
}
if (!defined('SCHAN_ALWAYSNOKO')) {
	define('SCHAN_ALWAYSNOKO', false);
}
if (!defined('SCHAN_WORDBREAK')) {
	define('SCHAN_WORDBREAK', 0);
}
if (!defined('SCHAN_EXPANDWIDTH')) {
	define('SCHAN_EXPANDWIDTH', 85);
}
if (!defined('SCHAN_TIMEZONE')) {
	define('SCHAN_TIMEZONE', '');
}
if (!defined('SCHAN_BACKLINKS')) {
	define('SCHAN_BACKLINKS', true);
}
if (!defined('SCHAN_CATALOG')) {
	define('SCHAN_CATALOG', true);
}
if (!defined('SCHAN_JSON')) {
	define('SCHAN_JSON', true);
}
if (!defined('SCHAN_DATEFMT')) {
	define('SCHAN_DATEFMT', '%g/%m/%d(%a)%H:%M:%S');
}
if (!defined('SCHAN_DBMIGRATE')) {
	define('SCHAN_DBMIGRATE', false);
}
if (!defined('SCHAN_DBACCOUNTS')) {
	define('SCHAN_DBACCOUNTS', 'accounts');
}
if (!defined('SCHAN_DBREPORTS')) {
	define('SCHAN_DBREPORTS', SCHAN_BOARD . '_reports');
}
if (!defined('SCHAN_DBKEYWORDS')) {
	define('SCHAN_DBKEYWORDS', SCHAN_BOARD . '_keywords');
}
if (!defined('SCHAN_DBLOGS')) {
	define('SCHAN_DBLOGS', 'logs');
}
if (!defined('SCHAN_DBPORT')) {
	define('SCHAN_DBPORT', 3306);
}
if (!defined('SCHAN_DBDRIVER')) {
	define('SCHAN_DBDRIVER', 'pdo');
}
if (!defined('SCHAN_DBDSN')) {
	define('SCHAN_DBDSN', '');
}
if (!defined('SCHAN_DBPATH')) {
	if (file_exists('schan.db')) {
		define('SCHAN_DBPATH', 'schan.db');
	} else {
		define('SCHAN_DBPATH', '.schan.db');
	}
}
if (!defined('SCHAN_DEFAULTSTYLE')) {
	define('SCHAN_DEFAULTSTYLE', 'futaba');
}
if (!isset($schan_stylesheets)) {
	$schan_stylesheets = array(
		'futaba' => 'Futaba',
		'burichan' => 'Burichan'
	);
}
if (!isset($schan_hidefieldsop)) {
	$schan_hidefieldsop = array();
}
if (!isset($schan_hidefields)) {
	$schan_hidefields = array();
}
if (!isset($schan_uploads)) {
	$schan_uploads = array();
	if (defined('SCHAN_PIC') && SCHAN_PIC) {
		$schan_uploads['image/jpeg'] = array('jpg');
		$schan_uploads['image/pjpeg'] = array('jpg');
		$schan_uploads['image/png'] = array('png');
		$schan_uploads['image/gif'] = array('gif');
	}
	if (defined('SCHAN_SWF') && SCHAN_SWF) {
		$schan_uploads['application/x-shockwave-flash'] = array('swf', 'swf_thumbnail.png');
	}
	if (defined('SCHAN_WEBM') && SCHAN_WEBM) {
		$schan_uploads['video/webm'] = array('webm');
		$schan_uploads['audio/webm'] = array('webm');
	}
}
if (!isset($schan_embeds)) {
	$schan_embeds = array();
	if (defined('SCHAN_EMBED') && SCHAN_EMBED) {
		$schan_embeds['SoundCloud'] = 'https://soundcloud.com/oembed?format=json&url=SCHANEMBED';
		$schan_embeds['Vimeo'] = 'https://vimeo.com/api/oembed.json?url=SCHANEMBED';
		$schan_embeds['YouTube'] = 'https://www.youtube.com/oembed?url=SCHANEMBED&format=json';
	}
}
if (!isset($schan_anonymous)) {
	$schan_anonymous = array('Anonymous');
}
if (!isset($schan_capcodes)) {
	$schan_capcodes = array(array('Admin', 'red'), array('Mod', 'purple'));
}
