<?php
/*
 * Live view of the home page.
 *
 * The page itself (blocks, layout, counts, texts) is configured in ?manage&home and written to
 * index.html whenever the boards are rebuilt. This file renders the very same page on demand, so it
 * is always current. Place it at the Schan install root.
 */

$install = __DIR__;

if (!file_exists($install . '/settings.php')) {
	die('Cannot find settings.php. Place this file at the Schan install root.');
}

require $install . '/settings.php';
require $install . '/inc/defines.php';

if (!defined('SCHAN_LOCALE') || SCHAN_LOCALE == '') {
	function __($string) { return $string; }
} else {
	require $install . '/inc/gettext.php';
}

if (SCHAN_TIMEZONE != '') {
	date_default_timezone_set(SCHAN_TIMEZONE);
}

$bcrypt_salt = '$2y$12$' . str_pad(str_replace('=', '/', str_replace('+', '.', substr(base64_encode(SCHAN_TRIPSEED), 0, 22))), 22, '/');

require $install . '/inc/extensions.php';
require $install . '/inc/functions.php';
require $install . '/inc/html.php';
require $install . '/inc/extra/emoji.php';
require $install . '/inc/extra/pending.php';
require $install . '/inc/extra/overboard.php';
require $install . '/inc/extra/home.php';
require $install . '/inc/config.php';
require $install . '/inc/database/' . SCHAN_DBMODE . '_link.php';
require $install . '/inc/database/' . SCHAN_DBMODE . '.php';
require $install . '/inc/database/database.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

echo homeBuildHTML(homeLoad());
