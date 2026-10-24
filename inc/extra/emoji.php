<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * custom emoji
 * images live in one shared folder at the install root: "static/emojis/smile.png"
 * users type :smile: and it shows as the image, same set on every board
 */

if (!defined('SCHAN_EMOJI_MAXKB')) {
	define('SCHAN_EMOJI_MAXKB', 512);
}

// install root on disk, the folder with settings.php (this file can be in inc/ or inc/extra/)
function emojiRoot() {
	static $root = null;
	if ($root === null) {
		$root = dirname(__FILE__);
		for ($i = 0; $i < 4; $i++) {
			if (is_file($root . '/settings.php')) {
				break;
			}
			$root = dirname($root);
		}
	}
	return $root;
}

// shared emoji directory at the install root
function emojiDir() {
	return emojiRoot() . '/../static/emojis';
}

// emoji used to be in emoji/ at the root, move them to static/emojis/ the first time they're needed
// and remove the old folder once it's empty
function emojiMigrateLegacy() {
	static $done = false;
	if ($done) {
		return;
	}
	$done = true;

	$legacy = emojiRoot() . '/emoji';
	if (!is_dir($legacy)) {
		return;
	}
	$dir = emojiDir();
	if (!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	if (!is_dir($dir) || !is_writable($dir)) {
		return;
	}
	foreach (scandir($legacy) as $file) {
		if (preg_match('/^[a-z0-9_]{1,30}\.(png|gif|jpg|webp)$/', $file) && !file_exists($dir . '/' . $file)) {
			@rename($legacy . '/' . $file, $dir . '/' . $file);
		}
	}
	@rmdir($legacy);
}

// returns array of name => filename. Pass true to re-read the folder
function emojiList($reset = false) {
	static $cache = null;
	if ($reset) {
		$cache = null;
	}
	if (function_exists('extensionEnabled') && !extensionEnabled('emoji')) {
		return array();
	}
	if ($cache !== null) {
		return $cache;
	}

	$cache = array();
	emojiMigrateLegacy();
	$dir = emojiDir();
	if (!is_dir($dir)) {
		return $cache;
	}
	foreach (scandir($dir) as $file) {
		if (preg_match('/^([a-z0-9_]{1,30})\.(png|gif|jpg|webp)$/', $file, $m)) {
			$cache[$m[1]] = $file;
		}
	}
	ksort($cache);
	return $cache;
}

// absolute URL of the shared emoji folder, rooted at the install directory
function emojiBaseURL() {
	if (function_exists('installRootURL')) {
		return installRootURL() . '/static/emojis/';
	}
	$install_root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
	return $install_root . '/static/emojis/';
}

function emojiCleanName($name) {
	$name = strtolower(trim($name));
	$name = preg_replace('/[^a-z0-9_]/', '_', $name);
	$name = trim(substr($name, 0, 30), '_');
	return $name;
}

// turns :name: into an <img> for emoji that exist. Unknown names are left as text
function parseEmoji($text) {
	if (strpos($text, ':') === false) {
		return $text;
	}
	$emoji = emojiList();
	if (empty($emoji)) {
		return $text;
	}
	$base = emojiBaseURL();
	return preg_replace_callback('/:([a-z0-9_]{1,30}):/i', function ($m) use ($emoji, $base) {
		$name = strtolower($m[1]);
		if (!isset($emoji[$name])) {
			return $m[0];
		}
		return '<img class="emoji" src="' . htmlentities($base . $emoji[$name], ENT_QUOTES) . '" alt=":' . $name . ':" title=":' . $name . ':" style="height:2.5em;width:auto;vertical-align:middle;">';
	}, $text);
}

// kaomoji for the post form, for your own list put a kaomoji.txt next to imgboard.php (one per line)
function kaomojiList() {
	if (file_exists('kaomoji.txt')) {
		$lines = file('kaomoji.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$list = array();
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line != '') {
				$list[] = $line;
			}
		}
		if (!empty($list)) {
			return $list;
		}
	}

	return array(
		'ヽ(´ー｀)ノ', '(;´Д`)', 'ヽ(´▽`)ノ', '(´人｀)', '(^Д^)',
		'(´ー`)', '( ´,_ゝ`)', '(´～`)', '(;ﾟДﾟ)', '(;ﾟ∀ﾟ)', '┐(ﾟ～ﾟ)┌',
		'ヽ(`Д´)ノ', '( ´ω`)', '(ﾟー｀)', '(・∀・)', '(⌒▽⌒ゞ)',
		'(ﾟ血ﾟ#)', '(ﾟ-ﾟ)', '(´¬`)', '(´π｀)', 'ヽ(ﾟρﾟ)ノ', 'Σ(;ﾟДﾟ)',
		'Σ(ﾟдﾟ|||)', 'ｷﾀ━━━(・∀・)━━━!!', '(*ﾟ∀ﾟ)', '(￣ー￣)',
		'＼(ˆoˆ)／', '(´・ω・`)', '(σ・∀・)σ', '(*^ーﾟ)b', '(-___-)',
		'(=ﾟωﾟ)ﾉ', '(・A・)', '(　´∀｀)つ', '(@^▽^)/'
	);
}

// collapsible kaomoji section. Clicking one inserts it into the message box
function kaomojiSection() {
	$list = kaomojiList();
	if (empty($list)) {
		return '';
	}

	$html = '<style>'
		. '.kaomojipicker summary{cursor:pointer;font-size:1.1em;margin:6px 0 4px 0;}'
		. '.kaomojipicker .kaomojigrid{display:flex;flex-wrap:wrap;gap:3px;max-width:520px;max-height:300px;overflow-y:auto;padding:2px 0;}'
		. '.kaomojipicker .kaomojigrid button{display:inline-block;padding:2px 8px;background:transparent;color:inherit;font:inherit;border:1px solid currentColor;border-radius:0;cursor:pointer;font-size:1.3em;line-height:1.4;text-decoration:none;white-space:nowrap;}'
		. '.kaomojipicker .kaomojigrid button:hover{opacity:.7;}'
		. '</style>';
	$html .= '<details class="kaomojipicker"><summary>' . __('Kaomoji') . '</summary><div class="kaomojigrid">';
	foreach ($list as $k) {
		$html .= '<button type="button" data-k="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" onclick="return insertKaomoji(this);">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</button>';
	}
	$html .= '</div></details>';
	$html .= '<script type="text/javascript">function insertKaomoji(e){var t=document.getElementById("message");if(!t){return false;}var s=e.getAttribute("data-k");var a=t.selectionStart,b=t.selectionEnd;if(typeof a==="number"){t.value=t.value.substring(0,a)+s+t.value.substring(b);t.selectionStart=t.selectionEnd=a+s.length;}else{t.value+=s;}t.focus();return false;}</script>';
	return $html;
}

// everything shown under the message box in the post form
function emojiPicker($parent = null) {
	if (function_exists('extensionEnabled') && !extensionEnabled('emoji')) {
		return '';
	}
	// BBCode drawer is shown on new threads and replies alike
	return bbcodeToolbar() . emojiSection() . kaomojiSection();
}

// collapsible BBCode toolbar above the emoji/kaomoji pickers on the new-thread form
// buttons wrap the selection, or insert an opening tag if nothing is selected
function bbcodeToolbar() {
	$buttons = array(
		array('label' => 'B',        'open' => '[b]',         'close' => '[/b]',         'class' => 'bbc-b'),
		array('label' => '/',        'open' => '[/]',         'close' => '',             'class' => 'bbc-slash'),
		array('label' => 'U',        'open' => '[u]',         'close' => '[/u]',         'class' => 'bbc-u'),
		array('label' => 'S',        'open' => '[s]',         'close' => '[/s]',         'class' => 'bbc-s'),
		array('label' => 'Spoiler',  'open' => '[spoiler]',   'close' => '[/spoiler]',   'class' => 'bbc-spoiler'),
		array('label' => '"Quote"',  'open' => '[quote]',     'close' => '[/quote]',     'class' => 'bbc-quote'),
		array('label' => 'Scroll',   'open' => '[scroll]',    'close' => '[/scroll]',    'class' => 'bbc-scroll'),
		array('label' => 'Color',    'open' => '[color=red]', 'close' => '[/color]',     'class' => 'bbc-color'),
		array('label' => 'Size',     'open' => '[size=150]',  'close' => '[/size]',      'class' => 'bbc-size'),
	);

	// buttons follow the page's own colors; override .bbcodepicker / .bbcodebar / .bbc-* in a stylesheet
	$html = '<style>'
		. '.bbcodepicker summary{cursor:pointer;font-size:1.1em;margin:6px 0 4px 0;}'
		. '.bbcodepicker .bbcodebar{display:flex;flex-wrap:wrap;gap:3px;padding:2px 0;}'
		. '.bbcodepicker .bbcodebar button{padding:2px 8px;background:transparent;color:inherit;font:inherit;border:1px solid currentColor;border-radius:0;cursor:pointer;}'
		. '.bbcodepicker .bbcodebar button:hover{opacity:.7;}'
		. '.bbc-b{font-weight:bold;}.bbc-u{text-decoration:underline;}.bbc-s{text-decoration:line-through;}.bbc-slash{font-style:italic;}'
		. '</style>';
	$html .= '<details class="bbcodepicker"><summary>BBCode</summary><div class="bbcodebar">';
	foreach ($buttons as $b) {
		$html .= '<button type="button" class="' . $b['class'] . '"'
			. ' data-open="' . htmlspecialchars($b['open'], ENT_QUOTES, 'UTF-8') . '"'
			. ' data-close="' . htmlspecialchars($b['close'], ENT_QUOTES, 'UTF-8') . '"'
			. ' onclick="return insertBBCode(this);">' . htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') . '</button>';
	}
	$html .= '</div></details>';

	$html .= '<script type="text/javascript">'
		. 'function insertBBCode(el){'
		. 'var t=document.getElementById("message");if(!t){return false;}'
		. 'var o=el.getAttribute("data-open")||"";'
		. 'var c=el.getAttribute("data-close")||"";'
		. 'var a=t.selectionStart,b=t.selectionEnd;'
		. 'if(typeof a==="number"){'
		. 'var sel=t.value.substring(a,b);'
		. 'var ins=o+sel+c;'
		. 't.value=t.value.substring(0,a)+ins+t.value.substring(b);'
		. 't.selectionStart=a+o.length;t.selectionEnd=a+o.length+sel.length;'
		. '}else{t.value+=o+c;}'
		. 't.focus();return false;}'
		. '</script>';

	return $html;
}

// collapsible custom emoji section
function emojiSection() {
	$emoji = emojiList();
	if (empty($emoji)) {
		return '';
	}
	$base = emojiBaseURL();

	// collapsed by default. Change to '<details class="emojipicker" open>' to start expanded
	$html = '<style>'
		. '.emojipicker summary{cursor:pointer;font-size:1.1em;margin:6px 0 4px 0;}'
		. '.emojipicker .emojigrid{display:flex;flex-wrap:wrap;gap:3px;max-width:520px;max-height:300px;overflow-y:auto;padding:2px 0;}'
		. '.emojipicker .emojigrid button{padding:0;display:flex;align-items:center;justify-content:center;width:48px;height:48px;background:transparent;color:inherit;font:inherit;border:1px solid currentColor;border-radius:0;cursor:pointer;}'
		. '.emojipicker .emojigrid button:hover{opacity:.7;}'
		. '.emojipicker .emojigrid img{max-width:40px;max-height:40px;width:auto;height:auto;border:0;}'
		. '</style>';
	$html .= '<details class="emojipicker"><summary>' . __('Emoji') . '</summary><div class="emojigrid">';
	foreach ($emoji as $name => $file) {
		$html .= '<button type="button" onclick="return insertEmoji(\'' . $name . '\');" title=":' . $name . ':"><img src="' . htmlentities($base . $file, ENT_QUOTES) . '" alt=":' . $name . ':"></button>';
	}
	$html .= '</div></details>';
	$html .= '<script type="text/javascript">function insertEmoji(n){var t=document.getElementById("message");if(!t){return false;}var s=":"+n+":";var a=t.selectionStart,b=t.selectionEnd;if(typeof a==="number"){t.value=t.value.substring(0,a)+s+t.value.substring(b);t.selectionStart=t.selectionEnd=a+s.length;}else{t.value+=s;}t.focus();return false;}</script>';
	return $html;
}

// rebuild every board's pages so posts and post forms pick up emoji changes
function emojiRebuild() {
	emojiList(true);
	foreach (allBoards() as $b) {
		$GLOBALS['schan_forced_board'] = $b['slug'];
		$threads = allThreads();
		foreach ($threads as $thread) {
			rebuildThread($thread['id'], $b['slug']);
		}
		rebuildIndexes($b['slug']);
		$GLOBALS['schan_forced_board'] = null;
	}
}

// handles add / rename / remove. Returns an info message to display
function handleEmojiManage() {
	if (function_exists('extensionEnabled') && !extensionEnabled('emoji')) {
		return manageInfo('The emoji extension is not installed. Install it from Manage → Extensions.');
	}
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		return '';
	}

	emojiMigrateLegacy();
	$dir = emojiDir();
	if (!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	if (!is_dir($dir) || !is_writable($dir)) {
		return manageInfo('The static/emojis folder does not exist or is not writable.');
	}

	$emoji = emojiList(true);

	// add
	if (isset($_POST['addemoji'])) {
		if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			return manageInfo('Upload failed. Choose an image file.');
		}
		$original = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
		$name = emojiCleanName(isset($_POST['name']) && trim($_POST['name']) != '' ? $_POST['name'] : $original);
		if ($name == '') {
			return manageInfo('Invalid emoji name. Use letters, numbers and underscores.');
		}
		if (isset($emoji[$name])) {
			return manageInfo('An emoji named :' . $name . ': already exists.');
		}
		if ($_FILES['file']['size'] > SCHAN_EMOJI_MAXKB * 1024) {
			return manageInfo('File is too large (max ' . SCHAN_EMOJI_MAXKB . ' KB).');
		}
		$imageinfo = @getimagesize($_FILES['file']['tmp_name']);
		$exts = array(IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg');
		if (defined('IMAGETYPE_WEBP')) {
			$exts[IMAGETYPE_WEBP] = 'webp';
		}
		if (!$imageinfo || !isset($exts[$imageinfo[2]])) {
			return manageInfo('Only PNG, GIF, JPG and WebP images are allowed.');
		}
		$target = $dir . '/' . $name . '.' . $exts[$imageinfo[2]];
		if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
			return manageInfo('Could not save the file.');
		}
		emojiRebuild();
		return manageInfo('Added :' . $name . ':');
	}

	// rename
	if (isset($_POST['renameemoji'])) {
		$old = emojiCleanName(isset($_POST['old']) ? $_POST['old'] : '');
		$new = emojiCleanName(isset($_POST['new']) ? $_POST['new'] : '');
		if (!isset($emoji[$old])) {
			return manageInfo('That emoji does not exist.');
		}
		if ($new == '') {
			return manageInfo('Invalid emoji name. Use letters, numbers and underscores.');
		}
		if ($new === $old) {
			return '';
		}
		if (isset($emoji[$new])) {
			return manageInfo('An emoji named :' . $new . ': already exists.');
		}
		$ext = pathinfo($emoji[$old], PATHINFO_EXTENSION);
		if (!rename($dir . '/' . $emoji[$old], $dir . '/' . $new . '.' . $ext)) {
			return manageInfo('Could not rename the file.');
		}
		emojiRebuild();
		return manageInfo('Renamed :' . $old . ': to :' . $new . ':. Existing posts that used :' . $old . ': will now show it as plain text.');
	}

	// remove
	if (isset($_POST['removeemoji'])) {
		$name = emojiCleanName(isset($_POST['old']) ? $_POST['old'] : '');
		if (!isset($emoji[$name])) {
			return manageInfo('That emoji does not exist.');
		}
		if (!unlink($dir . '/' . $emoji[$name])) {
			return manageInfo('Could not remove the file.');
		}
		emojiRebuild();
		return manageInfo('Removed :' . $name . ':');
	}

	return '';
}

// the manage page: add form + table of existing emoji
function manageEmojiPage() {
	$emoji = emojiList(true);
	$base = emojiBaseURL();

	$rows = '';
	foreach ($emoji as $name => $file) {
		$rows .= '<tr>'
			. '<td><img src="' . htmlentities($base . $file, ENT_QUOTES) . '" alt=":' . $name . ':" style="height:32px;width:auto;"></td>'
			. '<td><code>:' . $name . ':</code></td>'
			. '<td><form method="post" action="?manage&emoji" style="display:inline;">'
			. '<input type="hidden" name="renameemoji" value="1"><input type="hidden" name="old" value="' . $name . '">'
			. '<input type="text" name="new" value="' . $name . '" size="16" maxlength="30"> '
			. '<input type="submit" value="Rename" class="managebutton"></form></td>'
			. '<td><form method="post" action="?manage&emoji" style="display:inline;" onsubmit="return confirm(\'Remove :' . $name . ':?\');">'
			. '<input type="hidden" name="removeemoji" value="1"><input type="hidden" name="old" value="' . $name . '">'
			. '<input type="submit" value="Remove" class="managebutton"></form></td>'
			. '</tr>';
	}
	if ($rows == '') {
		$rows = '<tr><td colspan="4"><i>No custom emoji yet.</i></td></tr>';
	}

	$maxkb = SCHAN_EMOJI_MAXKB;
	return <<<EOF
	<fieldset>
	<legend>Add an emoji (shared across all boards)</legend>
	<form method="post" action="?manage&emoji" enctype="multipart/form-data">
	<input type="hidden" name="addemoji" value="1">
	<table border="0">
	<tr><td><label for="emojiname">Name</label></td><td><input type="text" name="name" id="emojiname" size="20" maxlength="30"> <small>Letters, numbers, underscores. Leave blank to use the file name.</small></td></tr>
	<tr><td><label for="emojifile">Image</label></td><td><input type="file" name="file" id="emojifile"> <small>PNG, GIF, JPG or WebP, max {$maxkb} KB.</small></td></tr>
	<tr><td>&nbsp;</td><td><input type="submit" value="Add" class="managebutton"></td></tr>
	</table>
	</form>
	</fieldset>
	<br>
	<fieldset>
	<legend>Custom emoji</legend>
	<table border="0" cellspacing="0" cellpadding="4" width="100%">
	$rows
	</table>
	</fieldset>
	<br>
EOF;
}
