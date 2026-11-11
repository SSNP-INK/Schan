<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// board links bar at the top of every page, loaded by html.php
// edit the array below, one array('label', 'url') per link, shown in this order
// use '/b/' style urls (starting with a slash) so they work from every page
function boardSwitcher($current = null) {
	$links = array(
		array('b',    '/b/'),
		array('o',    '/o/'),
		// array('Home', '/'),
	);

	if (empty($links)) {
		return '';
	}

	$out = array();
	foreach ($links as $link) {
		$out[] = '<a href="' . htmlentities($link[1], ENT_QUOTES) . '">' . htmlentities($link[0]) . '</a>';
	}

	return '<div class="boardbar" style="position:absolute;">[ <span class="boardlinks">' . implode(' / ', $out) . '</span> ]</div>';
}
