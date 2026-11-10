<?php
/*
  global message: one line shown on every board (index, thread, catalog) between the posting form and the threads
  stored in the database (settings table, name 'globalmessage')
 */
require_once __DIR__ . '/storage.php';

if (!defined('SCHAN_GLOBALMESSAGE_MAX')) {
	define('SCHAN_GLOBALMESSAGE_MAX', 500);
}

function globalMessageGet() {
	if (function_exists('extensionEnabled') && !extensionEnabled('globalmessage')) {
		return '';
	}
	if (!isset($GLOBALS['schan_globalmsg'])) {
		$GLOBALS['schan_globalmsg'] = (string)storeSettingGet('globalmessage', '');
	}
	return $GLOBALS['schan_globalmsg'];
}

// saves the message ('' removes it). Returns false when it cannot be saved
function globalMessageSet($text) {
	if (function_exists('extensionEnabled') && !extensionEnabled('globalmessage')) {
		return false;
	}
	$text = trim(str_replace("\r", '', (string)$text));
	$text = preg_replace('/\s*\n\s*/', ' ', $text);
	$text = function_exists('mb_substr') ? mb_substr($text, 0, SCHAN_GLOBALMESSAGE_MAX, 'UTF-8') : substr($text, 0, SCHAN_GLOBALMESSAGE_MAX);
	if ($text === '') {
		$ok = storeSettingDelete('globalmessage');
	} else {
		$ok = storeSettingSet('globalmessage', $text);
	}
	if ($ok) {
		$GLOBALS['schan_globalmsg'] = $text;
	}
	return $ok;
}

function globalMessageHTML() {
	$text = globalMessageGet();
	if ($text === '') {
		return '';
	}
	$html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	$html = preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/', function ($m) {
		return '<a href="' . $m[2] . '">' . $m[1] . '</a>';
	}, $html);
	return '<div class="globalmessage" style="text-align:center;font-weight:bold;margin:6px 0;"><span class="filetitle">' . $html . '</span></div><hr>';
}

// the editor shown at the top of Manage > Boards
function globalMessageFormHTML() {
	if (function_exists('extensionEnabled') && !extensionEnabled('globalmessage')) {
		return '';
	}
	return '<fieldset><legend>' . __('Global message') . '</legend>'
		. '<form method="post" action="?manage&boards">'
		. '<input type="hidden" name="board_action" value="globalmessage">'
		. '<p><small>' . __('Shown on every board between the posting form and the threads. Leave empty to show nothing. For a link write [link text](https://example.com).') . '</small></p>'
		. '<textarea name="global_message" rows="3" cols="80" maxlength="' . intval(SCHAN_GLOBALMESSAGE_MAX) . '">' . htmlspecialchars(globalMessageGet(), ENT_QUOTES, 'UTF-8') . '</textarea><br>'
		. '<input type="submit" value="' . __('Save and rebuild all boards') . '" class="managebutton">'
		. '</form></fieldset><br>';
}
