<?php
require_once __DIR__ . '/storage.php';

/*
 * poster country flags
 *
 * - images are in static/flags/ (vichan flag set: us.png, de.png...)
 * - the country is looked up when the post is made, from Cloudflare's CF-IPCountry header
 *   (only if SCHAN_CLOUDFLARE is true) or a MaxMind GeoLite2-Country .mmdb read by the pure-PHP reader below
 * - only a marker like <!--flag:us--> is stored in the nameblock, it becomes an <img> (or gets removed)
 *   when the page is built, depending on whether flags are on for that board
 * - the per-board switch is stored in the database (settings table, name 'boardflags')
 */

// GeoLite2-Country database path, relative to the install dir (define SCHAN_GEOIPDB in settings.php to change it)
if (!defined('SCHAN_GEOIPDB')) {
	define('SCHAN_GEOIPDB', 'inc/GeoLite2-Country.mmdb');
}

// optional online lookup (ip-api.com, plain HTTP, sends the poster's IP to a third party)
// only used on a new post when nothing else found a country, turn on with SCHAN_GEOIPAPI = true in settings.php
if (!defined('SCHAN_GEOIPAPI')) {
	define('SCHAN_GEOIPAPI', false);
}

// whether flags are on for boards that have never been toggled
if (!defined('SCHAN_FLAGS_DEFAULT')) {
	define('SCHAN_FLAGS_DEFAULT', false);
}

// per-board setting

function flagsConfig() {
	if (!isset($GLOBALS['schan_flags_cfg'])) {
		$GLOBALS['schan_flags_cfg'] = storeJsonGet('boardflags', array());
	}
	return $GLOBALS['schan_flags_cfg'];
}

// saves the whole per-board settings array, true on success
function flagsConfigSave($cfg) {
	if (!storeJsonSet('boardflags', $cfg)) {
		return false;
	}
	$GLOBALS['schan_flags_cfg'] = $cfg;
	return true;
}

function flagsEnabledFor($board) {
	$cfg = flagsConfig();
	if (isset($cfg[$board])) {
		return (bool)$cfg[$board];
	}
	return SCHAN_FLAGS_DEFAULT ? true : false;
}

function flagsSetEnabled($board, $enabled) {
	$cfg = flagsConfig();
	$cfg[$board] = $enabled ? 1 : 0;
	return flagsConfigSave($cfg);
}

// per-board custom (hand-edited) index

// when on, a rebuild never overwrites the board's index.html so it can be edited by hand
// stored in the 'boardflags' setting under "index:<slug>" (if index.html doesn't exist yet it's generated once)
function indexCustomFor($board) {
	$cfg = flagsConfig();
	return !empty($cfg['index:' . $board]);
}

function indexCustomSet($board, $on) {
	$cfg = flagsConfig();
	if ($on) {
		$cfg['index:' . $board] = 1;
	} else {
		unset($cfg['index:' . $board]);
	}
	return flagsConfigSave($cfg);
}

// per-board "fileboard" index layout

// threads per index page when a board uses the table ("fileboard") layout
if (!defined('SCHAN_FILEBOARD_PERPAGE')) {
	define('SCHAN_FILEBOARD_PERPAGE', 50);
}

// stored in the 'boardflags' setting under "layout:<slug>" (value "file")
function layoutFileboardFor($board) {
	$cfg = flagsConfig();
	return isset($cfg['layout:' . $board]) && $cfg['layout:' . $board] === 'file';
}

function layoutFileboardSet($board, $on) {
	$cfg = flagsConfig();
	if ($on) {
		$cfg['layout:' . $board] = 'file';
	} else {
		unset($cfg['layout:' . $board]);
	}
	return flagsConfigSave($cfg);
}

// news boards

// news board: only admins can start threads (from the manage panel's Staff Post form), anyone can reply
// stored in the 'boardflags' setting under "news:<slug>"
function newsBoardFor($board) {
	$cfg = flagsConfig();
	return !empty($cfg['news:' . $board]);
}

function newsBoardSet($board, $on) {
	$cfg = flagsConfig();
	if ($on) {
		$cfg['news:' . $board] = 1;
	} else {
		unset($cfg['news:' . $board]);
	}
	return flagsConfigSave($cfg);
}

// thread subjects from every news board, newest first
function newsCollect($max = 50) {
	$prev = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$items = array();
	foreach (allBoards() as $b) {
		if (!newsBoardFor($b['slug'])) {
			continue;
		}
		$GLOBALS['schan_forced_board'] = $b['slug'];
		foreach (allThreads() as $t) {
			if (isset($t['moderated']) && $t['moderated'] == 0) {
				continue;
			}
			$t = postEditsApply($t);
			$subject = trim(html_entity_decode((string)$t['subject'], ENT_QUOTES, 'UTF-8'));
			if ($subject === '') {
				continue;
			}
			$items[] = array(
				'board' => $b['slug'],
				'id' => (int)$t['id'],
				'subject' => $subject,
				'timestamp' => (int)$t['timestamp'],
				'date' => date('m/d/Y', (int)$t['timestamp']),
				'url' => installRootURL() . '/' . $b['slug'] . '/res/' . (int)$t['id'] . '.html',
			);
		}
	}
	$GLOBALS['schan_forced_board'] = $prev;
	usort($items, function ($a, $b) {
		return $b['timestamp'] == $a['timestamp'] ? $b['id'] - $a['id'] : $b['timestamp'] - $a['timestamp'];
	});
	return array_slice($items, 0, $max);
}

// saves the news list in the database (settings table, name 'news_cache') for news_highlight.php
// false if it couldn't be saved
function newsRebuildCache() {
	return storeJsonSet('news_cache', array('updated' => time(), 'items' => newsCollect()), JSON_UNESCAPED_UNICODE);
}

// staff post edits (name, subject, "file deleted" marker)

// the db layer only has updatePostMessage(), so edits to a post's name/subject and the "file deleted"
// placeholder live in their own table (<posts>_postedits), applied when a post is drawn
// a NULL column means "not edited"

function postEditsBoard($post = null) {
	if (is_array($post) && isset($post['board']) && $post['board'] !== '') {
		return (string)$post['board'];
	}
	return current_board();
}

function postEditsFor($id, $board = null) {
	if (!isset($GLOBALS['schan_postedits_cache'])) {
		$GLOBALS['schan_postedits_cache'] = array();
	}
	$cache = &$GLOBALS['schan_postedits_cache'];
	$board = ($board === null || $board === '') ? current_board() : (string)$board;
	$key = $board . ':' . intval($id);
	if (!array_key_exists($key, $cache)) {
		$row = storeRow("SELECT `name`, `subject`, `deleted` FROM `" . storeTable('postedits') . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id) . " LIMIT 1");
		$e = array();
		if ($row !== null) {
			if ($row['name'] !== null) {
				$e['name'] = $row['name'];
			}
			if ($row['subject'] !== null) {
				$e['subject'] = $row['subject'];
			}
			if (!empty($row['deleted'])) {
				$e['deleted'] = 1;
			}
		}
		$cache[$key] = $e;
	}
	return $cache[$key];
}

// merge $fields into the post's edits. A null value removes that key
function postEditsSet($id, $fields, $board = null) {
	$board = ($board === null || $board === '') ? current_board() : (string)$board;
	$id = intval($id);
	$t = storeTable('postedits');
	storeEnsure();
	$sets = array();
	foreach (array('name', 'subject') as $k) {
		if (array_key_exists($k, $fields)) {
			$sets[$k] = $fields[$k] === null ? 'NULL' : "'" . storeEsc($fields[$k]) . "'";
		}
	}
	if (array_key_exists('deleted', $fields)) {
		$sets['deleted'] = $fields['deleted'] === null ? 'NULL' : ($fields['deleted'] ? '1' : 'NULL');
	}
	if (empty($sets)) {
		return true;
	}
	$cols = array();
	$vals = array();
	$upd = array();
	foreach ($sets as $k => $v) {
		$cols[] = '`' . $k . '`';
		$vals[] = $v;
		$upd[] = '`' . $k . '` = VALUES(`' . $k . '`)';
	}
	$ok = storeQuery("INSERT INTO `" . $t . "` (`board`, `post_id`, " . implode(', ', $cols) . ") VALUES ('" . storeEsc($board) . "', " . $id . ", " . implode(', ', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $upd));
	// drop the row once nothing is edited any more
	storeQuery("DELETE FROM `" . $t . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . $id . " AND `name` IS NULL AND `subject` IS NULL AND `deleted` IS NULL");
	$GLOBALS['schan_postedits_cache'] = array();
	return (bool)$ok;
}

// returns the post with any staff edits applied (subject, name)
function postEditsApply($post) {
	if (!is_array($post) || !isset($post['id'])) {
		return $post;
	}
	$e = postEditsFor($post['id'], postEditsBoard($post));
	if (empty($e)) {
		return $post;
	}
	if (array_key_exists('subject', $e)) {
		$post['subject'] = $e['subject'];
	}
	if (array_key_exists('name', $e)) {
		$post['name'] = $e['name'];
		if (isset($post['nameblock'])) {
			$new = $e['name'];
			$post['nameblock'] = preg_replace_callback('#(<span class="postername">).*?(</span>)#s', function ($m) use ($new) {
				return $m[1] . $new . $m[2];
			}, $post['nameblock'], 1);
		}
	}
	return $post;
}

function postFileDeletedFor($id, $board = null) {
	$e = postEditsFor($id, $board);
	return !empty($e['deleted']);
}

// the /deleted.png placeholder shown in place of a file removed with the F button
function deletedPlaceholderHTML($id = 0) {
	$w = 150;
	$h = 150;
	$path = dirname(__DIR__) . '/deleted.png';
	if (is_file($path)) {
		$size = @getimagesize($path);
		if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
			$scale = min(1, 250 / max($size[0], $size[1]));
			$w = max(1, (int)round($size[0] * $scale));
			$h = max(1, (int)round($size[1] * $scale));
		}
	}
	return '<span class="filesize">' . __('File deleted') . '</span><br><div class="deletedfile"><img src="' . htmlentities(installRootURL() . '/deleted.png', ENT_QUOTES) . '" alt="' . htmlentities(__('File deleted'), ENT_QUOTES) . '" width="' . $w . '" height="' . $h . '" border="0"></div>';
}

// blocked countries

// posters from a blocked country (GeoIP db, or the Cloudflare header if enabled) can't post
// stored in the 'boardflags' setting under "blocked:countries" as ISO codes
function blockedCountries() {
	$cfg = flagsConfig();
	$list = (isset($cfg['blocked:countries']) && is_array($cfg['blocked:countries'])) ? $cfg['blocked:countries'] : array();
	$out = array();
	foreach ($list as $c) {
		$c = strtoupper(trim((string)$c));
		if (preg_match('/^[A-Z]{2}$/', $c)) {
			$out[$c] = $c;
		}
	}
	ksort($out);
	return array_values($out);
}

function blockedCountriesSet($codes) {
	$clean = array();
	foreach ($codes as $c) {
		$c = strtoupper(trim((string)$c));
		if (preg_match('/^[A-Z]{2}$/', $c)) {
			$clean[$c] = $c;
		}
	}
	ksort($clean);
	$cfg = flagsConfig();
	if (empty($clean)) {
		unset($cfg['blocked:countries']);
	} else {
		$cfg['blocked:countries'] = array_values($clean);
	}
	return flagsConfigSave($cfg);
}

// english name of a country code (needs PHP's intl extension; falls back to the code)
function countryNameFor($code) {
	$code = strtoupper($code);
	if (class_exists('Locale')) {
		$n = @Locale::getDisplayRegion('-' . $code, 'en');
		if (is_string($n) && $n !== '' && $n !== $code && stripos($n, 'unknown') === false) {
			return $n;
		}
	}
	return $code;
}

// stops the request when the poster's country is blocked
function countryBlockCheck() {
	$list = blockedCountries();
	if (empty($list)) {
		return;
	}
	$code = strtoupper(flagCountryCode());
	if ($code !== '' && in_array($code, $list, true)) {
		fancyDie(sprintf(__('Posting from your country (%s) has been disabled.'), htmlentities($code)));
	}
}

// thread stats: Watching / Attachments / Replies

// "Watching" = browsers that pinged a thread in the last 45 seconds. pings are in the <posts>_watching table,
// anything older than 90 seconds is dropped on write
// replies and attachments are counted live
define('SCHAN_WATCH_ACTIVE', 45);
define('SCHAN_WATCH_KEEP', 90);

function watchingTouch($board, $thread, $vid) {
	$t = storeTable('watching');
	$now = time();
	storeEnsure();
	storeQuery("INSERT INTO `" . $t . "` (`board`, `thread`, `viewer`, `seen`) VALUES ('" . storeEsc($board) . "', " . intval($thread) . ", '" . storeEsc(substr((string)$vid, 0, 64)) . "', " . $now . ") ON DUPLICATE KEY UPDATE `seen` = VALUES(`seen`)");
	storeQuery("DELETE FROM `" . $t . "` WHERE `seen` < " . ($now - SCHAN_WATCH_KEEP));
}

// array(thread id => number of viewers active right now)
function watchingCounts($board, $ids) {
	$out = array();
	$list = array();
	foreach ($ids as $id) {
		$out[$id] = 0;
		$list[] = intval($id);
	}
	if (empty($list)) {
		return $out;
	}
	$cut = time() - SCHAN_WATCH_ACTIVE;
	$rows = storeRows("SELECT `thread`, COUNT(*) AS n FROM `" . storeTable('watching') . "` WHERE `board` = '" . storeEsc($board) . "' AND `thread` IN (" . implode(',', $list) . ") AND `seen` >= " . $cut . " GROUP BY `thread`");
	foreach ($rows as $r) {
		foreach ($ids as $id) {
			if (intval($id) === intval($r['thread'])) {
				$out[$id] = (int)$r['n'];
			}
		}
	}
	return $out;
}

// array(replies, attachments) for the posts of one thread (the opening post comes first)
function threadStatsCounts($posts, $board) {
	$replies = max(0, count($posts) - 1);
	$attachments = 0;
	foreach ($posts as $p) {
		if ($p['file'] != '' && !(isset($p['file_hex']) && isEmbed($p['file_hex']))) {
			$attachments++;
		}
		if (function_exists('mediaExtrasFor')) {
			$attachments += count(mediaExtrasFor($board, $p['id']));
		}
	}
	return array($replies, $attachments);
}

// line under a thread. $live is true on the thread's own page (it also pings so the viewer counts as watching),
// index pages only read the numbers
function threadStatsHTML($thread_id, $board, $posts, $live) {
	list($replies, $attachments) = threadStatsCounts($posts, $board);
	return '<div class="threadstats" data-thread="' . intval($thread_id) . '" data-board="' . htmlentities($board, ENT_QUOTES) . '" data-live="' . ($live ? '1' : '0') . '" style="margin:6px 0;font-size:smaller;">'
		. __('Watching') . ': <span class="ts-w">-</span> &nbsp; '
		. __('Attachments') . ': <span class="ts-a">' . $attachments . '</span> &nbsp; '
		. __('Replies') . ': <span class="ts-r">' . $replies . '</span></div>';
}

// endpoint: imgboard.php?watching=ID[,ID...]&board=slug[&beat=VISITOR]
function watchingHandle() {
	$board = current_board();
	$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$_GET['watching']))))), 0, 60);
	$beat = (isset($_GET['beat']) && is_string($_GET['beat']) && preg_match('/^[A-Za-z0-9]{6,32}$/', $_GET['beat'])) ? $_GET['beat'] : '';
	$found = array();
	foreach ($ids as $id) {
		$posts = postsInThreadByID($id);
		if (!empty($posts) && $posts[0]['id'] == $id && $posts[0]['parent'] == SCHAN_NEWTHREAD && !(isset($posts[0]['moderated']) && $posts[0]['moderated'] == 0)) {
			$found[$id] = $posts;
		}
	}
	if ($beat !== '' && count($found) == 1) {
		reset($found);
		watchingTouch($board, key($found), $beat);
	}
	$counts = watchingCounts($board, array_keys($found));
	$out = array();
	foreach ($found as $id => $posts) {
		list($r, $a) = threadStatsCounts($posts, $board);
		$out[$id] = array('watching' => $counts[$id], 'replies' => $r, 'attachments' => $a);
	}
	return (object)$out;
}

function threadStatsJS() {
	return <<<'JS'
<script type="text/javascript">
(function(){
function start(){
	var els=document.querySelectorAll('.threadstats');
	if(!els.length||!window.XMLHttpRequest){return;}
	var root=(typeof schanRoot==='string')?schanRoot:'';
	var vid='';
	try{vid=localStorage.getItem('schan_vid')||'';if(!vid){vid=Math.random().toString(36).slice(2,10)+Math.random().toString(36).slice(2,10);localStorage.setItem('schan_vid',vid);}}catch(e){vid=Math.random().toString(36).slice(2,14);}
	function setv(el,c,v){var s=el.querySelector('.'+c);if(s&&typeof v!=='undefined'){s.textContent=v;}}
	function fetchStats(board,ids,beat,cb){
		var x=new XMLHttpRequest();
		x.open('GET',root+'/imgboard.php?watching='+ids.join(',')+'&board='+encodeURIComponent(board)+(beat?'&beat='+encodeURIComponent(vid):'')+'&_='+Date.now(),true);
		x.onreadystatechange=function(){if(x.readyState!==4){return;}var r=null;try{r=JSON.parse(x.responseText);}catch(e){}cb(r);};
		x.send();
	}
	function run(){
		var live=[],byBoard={},i,el,b;
		els=document.querySelectorAll('.threadstats');
		for(i=0;i<els.length;i++){
			el=els[i];
			if(el.getAttribute('data-live')==='1'){live.push(el);}
			else{b=el.getAttribute('data-board');(byBoard[b]=byBoard[b]||[]).push(el);}
		}
		live.forEach(function(el){
			fetchStats(el.getAttribute('data-board'),[el.getAttribute('data-thread')],true,function(r){
				var d=r&&r[el.getAttribute('data-thread')];
				if(d){setv(el,'ts-w',d.watching);setv(el,'ts-a',d.attachments);setv(el,'ts-r',d.replies);}
			});
		});
		Object.keys(byBoard).forEach(function(board){
			var list=byBoard[board],ids=list.map(function(e){return e.getAttribute('data-thread');});
			fetchStats(board,ids,false,function(r){
				if(!r){return;}
				list.forEach(function(e){var d=r[e.getAttribute('data-thread')];if(d){setv(e,'ts-w',d.watching);setv(e,'ts-a',d.attachments);setv(e,'ts-r',d.replies);}});
			});
		});
	}
	run();
	setInterval(run,20000);
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',start);}else{start();}
})();
</script>
JS;
}

// flag images

// returns the file name inside static/flags/ for a country code, or '' if there is none
function flagFileFor($code) {
	static $cache = array();
	$code = strtolower($code);
	if (isset($cache[$code])) {
		return $cache[$code];
	}
	$cache[$code] = '';
	if (!preg_match('/^[a-z0-9_-]{2,10}$/', $code)) {
		return '';
	}
	$dir = dirname(__DIR__) . '/static/flags/';
	$names = array($code, strtoupper($code));
	$exts = array('png', 'gif', 'svg');
	foreach ($names as $name) {
		foreach ($exts as $ext) {
			if (is_file($dir . $name . '.' . $ext)) {
				$cache[$code] = $name . '.' . $ext;
				return $cache[$code];
			}
		}
	}
	return '';
}

function flagImageHTML($code) {
	$file = flagFileFor($code);
	if ($file === '') {
		return '';
	}
	$label = htmlentities(strtoupper($code), ENT_QUOTES);
	return ' <img class="flag" src="' . htmlentities(installRootURL() . '/static/flags/' . $file, ENT_QUOTES) . '" alt="' . $label . '" title="' . $label . '" style="width:16px;height:11px;vertical-align:baseline;border:0;">';
}

// page building

// swaps <!--flag:xx--> markers in a nameblock for the flag image, or removes them if flags are off for the board
// <!--mflag:name--> (meme flags) always show on every board
function flagsRender($html, $board) {
	if (strpos($html, '<!--flag:') !== false) {
		$show = flagsEnabledFor($board);
		$html = preg_replace_callback('/<!--flag:([a-z0-9_-]{2,10})-->/i', function ($m) use ($show) {
			return $show ? flagImageHTML($m[1]) : '';
		}, $html);
	}
	if (strpos($html, '<!--mflag:') !== false) {
		$html = preg_replace_callback('/<!--mflag:([A-Za-z0-9%_.~]+)-->/', function ($m) {
			return memeFlagImageHTML(rawurldecode($m[1]));
		}, $html);
	}
	return $html;
}

// custom (meme) flags

// images in static/meme-flags/ can be picked from a dropdown in the posting form
// they work on every board, the per-board flags switch doesn't affect them
// label is the file name without extension, names over 40 characters are ignored

function memeFlagsDir() {
	return dirname(__DIR__) . '/static/meme-flags/';
}

// returns array(name => file name) for every usable image, sorted by name
function memeFlagsList() {
	static $list = null;
	if ($list !== null) {
		return $list;
	}
	$list = array();
	$dir = memeFlagsDir();
	if (!is_dir($dir)) {
		return $list;
	}
	$files = @scandir($dir);
	if (!is_array($files)) {
		return $list;
	}
	foreach ($files as $file) {
		if ($file === '' || $file[0] === '.' || !preg_match('/\.(png|gif|svg|jpe?g|webp)$/i', $file)) {
			continue;
		}
		if (!is_file($dir . $file)) {
			continue;
		}
		$name = pathinfo($file, PATHINFO_FILENAME);
		if ($name === '' || strlen($name) > 40 || isset($list[$name])) {
			continue;
		}
		$list[$name] = $file;
	}
	uksort($list, 'strnatcasecmp');
	return $list;
}

function memeFlagImageHTML($name) {
	$list = memeFlagsList();
	if (!isset($list[$name])) {
		return '';
	}
	$label = htmlentities($name, ENT_QUOTES);
	return ' <img class="flag meme-flag" src="' . htmlentities(installRootURL() . '/static/meme-flags/' . rawurlencode($list[$name]), ENT_QUOTES) . '" alt="' . $label . '" title="' . $label . '" style="height:11px;width:auto;vertical-align:baseline;border:0;">';
}

// table row with the dropdown for the posting form ('' when there are no meme flags)
function memeFlagsFormRow() {
	$list = memeFlagsList();
	if (empty($list)) {
		return '';
	}
	$out = '<tr><td class="postblock">' . __('Flag') . '</td><td>';
	$out .= '<select name="meme_flag" onchange="try{localStorage.setItem(\'schan_memeflag\',this.value)}catch(e){}">';
	$out .= '<option value="">' . __('None') . '</option>';
	foreach ($list as $name => $file) {
		$name = (string)$name;
		$out .= '<option value="' . htmlentities($name, ENT_QUOTES) . '">' . htmlentities($name, ENT_QUOTES) . '</option>';
	}
	$out .= '</select>';
	$out .= '<script>(function(){try{var s=document.currentScript.previousElementSibling,v=localStorage.getItem(\'schan_memeflag\');if(v){s.value=v;if(s.value!==v){s.value=\'\';}}}catch(e){}})();</script>';
	$out .= '</td></tr>';
	return $out;
}

// returns the marker to store in a new post's nameblock for the poster's selection ('' for none)
function memeFlagMarkerForPost() {
	if (!isset($_POST['meme_flag']) || !is_string($_POST['meme_flag']) || $_POST['meme_flag'] === '') {
		return '';
	}
	$name = $_POST['meme_flag'];
	$list = memeFlagsList();
	if (!isset($list[$name])) {
		return '';
	}
	// dashes are encoded so the name can never close the HTML comment early
	return '<!--mflag:' . str_replace('-', '%2D', rawurlencode($name)) . '-->';
}

// real IPs in the manage panel

// the db only stores a hash of each poster's IP (hashData(), bcrypt with a fixed salt) which can't be reversed
// so when a post is made the real IP is also saved in the <posts>_ipmap table keyed by that hash,
// and the manage panel looks it up there. older posts stay hashed

function ipMapLookup($hash) {
	static $cache = array();
	$hash = (string)$hash;
	if (!array_key_exists($hash, $cache)) {
		$row = storeRow("SELECT `ip` FROM `" . storeTable('ipmap') . "` WHERE `hash` = '" . storeEsc($hash) . "' LIMIT 1");
		$cache[$hash] = $row === null ? null : $row['ip'];
	}
	return $cache[$hash];
}

// remember the real IP of a poster (call once the post exists)
function ipMapRemember($ip) {
	static $done = array();
	if (!is_string($ip) || $ip === '' || @inet_pton($ip) === false || isset($done[$ip])) {
		return;
	}
	$done[$ip] = true;
	storeEnsure();
	storeQuery("INSERT INTO `" . storeTable('ipmap') . "` (`hash`, `ip`) VALUES ('" . storeEsc(hashData($ip)) . "', '" . storeEsc($ip) . "') ON DUPLICATE KEY UPDATE `ip` = VALUES(`ip`)");
}

// real IP for a stored value (a hash or an IP), or '' when it is a hash that was never recorded
function ipMapReveal($stored) {
	$stored = (string)$stored;
	if ($stored === '' || strpos($stored, '$2y$') !== 0) {
		return $stored;
	}
	$real = ipMapLookup($stored);
	return $real === null ? '' : $real;
}

// HTML to show for a stored IP value in the manage panel
function ipDisplayHTML($stored) {
	$real = ipMapReveal($stored);
	if ($real !== '') {
		return htmlentities($real, ENT_QUOTES);
	}
	return '<span title="' . htmlentities((string)$stored, ENT_QUOTES) . '">(' . htmlentities(__('hashed, older post')) . ')</span>';
}

// staff: poster country next to IP addresses

// small flag + [CC] tag for an IP in the manage panel
// GeoIP database only, never the Cloudflare header (that's the current visitor, not the IP shown), '' for non-IPs
function manageCountryTag($ip) {
	$ip = trim(ipMapReveal($ip));
	if ($ip === '' || @inet_pton($ip) === false) {
		return '';
	}
	$code = strtolower(flagLookupDatabase($ip));
	if ($code === '') {
		return ' <small title="' . htmlentities(__('Country unknown'), ENT_QUOTES) . '">[?]</small>';
	}
	return flagImageHTML($code) . ' <small>[' . htmlentities(strtoupper($code), ENT_QUOTES) . ']</small>';
}

// ban appeals

// appeals are kept in the <posts>_appeals table, one row per ban id:
//   ban_id, ts (ban timestamp, so an appeal never carries over to a different ban), created (submitted at), text

define('SCHAN_APPEAL_MAXLEN', 1000);

// all appeals as array(ban_id => array('ts' =>, 'time' =>, 'text' =>))
function appealsLoad() {
	$data = array();
	foreach (storeRows("SELECT `ban_id`, `ts`, `created`, `text` FROM `" . storeTable('appeals') . "`") as $r) {
		$data[(string)$r['ban_id']] = array('ts' => (int)$r['ts'], 'time' => (int)$r['created'], 'text' => $r['text']);
	}
	return $data;
}

// saves an appeal (replacing any older one for that ban), true on success
function appealsSave($ban_id, $ts, $text) {
	storeEnsure();
	// forget appeals older than a year
	storeQuery("DELETE FROM `" . storeTable('appeals') . "` WHERE `created` < " . (time() - 31536000));
	return (bool)storeQuery("INSERT INTO `" . storeTable('appeals') . "` (`ban_id`, `ts`, `created`, `text`) VALUES (" . intval($ban_id) . ", " . intval($ts) . ", " . time() . ", '" . storeEsc($text) . "') ON DUPLICATE KEY UPDATE `ts` = VALUES(`ts`), `created` = VALUES(`created`), `text` = VALUES(`text`)");
}

// returns the appeal array for a ban row, or null
function appealForBan($ban) {
	if (!isset($ban['id']) || !isset($ban['timestamp'])) {
		return null;
	}
	$row = storeRow("SELECT `ts`, `created`, `text` FROM `" . storeTable('appeals') . "` WHERE `ban_id` = " . intval($ban['id']) . " LIMIT 1");
	if ($row !== null && (int)$row['ts'] === (int)$ban['timestamp']) {
		return array('ts' => (int)$row['ts'], 'time' => (int)$row['created'], 'text' => $row['text']);
	}
	return null;
}

// called when a ban is lifted
function appealsRemove($ban_id) {
	return storeExec("DELETE FROM `" . storeTable('appeals') . "` WHERE `ban_id` = " . intval($ban_id)) >= 0;
}

// appeal status/form shown to a banned visitor on the ban page
function appealBoxHTML($ban) {
	if (!isset($ban['id']) || !isset($ban['timestamp'])) {
		return '';
	}
	$msg = '';
	$existing = appealForBan($ban);

	if (!$existing && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appeal_text']) && is_string($_POST['appeal_text'])) {
		$text = trim(str_replace("\r\n", "\n", $_POST['appeal_text']));
		if ($text === '') {
			$msg = __('Please write your appeal before submitting.');
		} else {
			$text = _substr($text, 0, SCHAN_APPEAL_MAXLEN);
			$saved = appealsSave($ban['id'], (int)$ban['timestamp'], $text);
			if ($saved) {
				$existing = appealForBan($ban);
			} else {
				$msg = __('Your appeal could not be saved. Please try again later.');
			}
		}
	}

	if ($existing) {
		return '<br><br><small>' . sprintf(__('Your appeal was submitted %s and is waiting for review by a moderator.'), formatDate($existing['time'])) . '</small>';
	}

	$root = function_exists('installRootURL') ? installRootURL() : '';
	$board_field = function_exists('current_board') ? '<input type="hidden" name="board" value="' . htmlentities(current_board(), ENT_QUOTES) . '">' : '';
	$out = '<br><br><form method="post" action="' . htmlentities($root . '/imgboard.php', ENT_QUOTES) . '" style="margin:0;">';
	$out .= $board_field;
	if ($msg !== '') {
		$out .= '<small><b>' . htmlentities($msg, ENT_QUOTES) . '</b></small><br>';
	}
	$out .= '<small>' . __('Think this ban is a mistake? Appeal it:') . '</small><br>';
	$out .= '<textarea name="appeal_text" rows="5" cols="40" maxlength="' . SCHAN_APPEAL_MAXLEN . '" style="max-width:100%;"></textarea><br>';
	$out .= '<input type="submit" value="' . htmlentities(__('Appeal ban'), ENT_QUOTES) . '">';
	$out .= '</form>';
	return $out;
}

// posting

// returns the marker to store in a new post's nameblock ('' for no flag)
function flagsMarkerForPost($board) {
	if (!flagsEnabledFor($board)) {
		return '';
	}
	$code = flagCountryCode();
	if ($code === '' || flagFileFor($code) === '') {
		return '';
	}
	return '<!--flag:' . $code . '-->';
}

function flagPublicIP($ip) {
	return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// returns array(code, source) for an IP. $source is '' when nothing was found
function flagCountryInfo($ip) {
	if (defined('SCHAN_CLOUDFLARE') && SCHAN_CLOUDFLARE && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
		$code = strtolower(trim($_SERVER['HTTP_CF_IPCOUNTRY']));
		if (preg_match('/^[a-z0-9_-]{2,10}$/', $code) && $code !== 'xx') {
			return array($code, 'Cloudflare header');
		}
	}
	$code = strtolower(flagLookupDatabase($ip));
	if ($code !== '') {
		return array($code, 'GeoIP database');
	}
	if (SCHAN_GEOIPAPI) {
		$code = strtolower(flagLookupAPI($ip));
		if ($code !== '') {
			return array($code, 'online lookup');
		}
	}
	return array('', '');
}

function flagCountryCode() {
	$info = flagCountryInfo(remoteAddress());
	return $info[0];
}

function flagLookupAPI($ip) {
	if (!flagPublicIP($ip)) {
		return '';
	}
	$url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode';
	$body = false;
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3);
		$body = curl_exec($ch);
		curl_close($ch);
	} elseif (ini_get('allow_url_fopen')) {
		$ctx = stream_context_create(array('http' => array('timeout' => 3)));
		$body = @file_get_contents($url, false, $ctx);
	}
	if (!is_string($body)) {
		return '';
	}
	$data = json_decode($body, true);
	if (is_array($data) && isset($data['status']) && $data['status'] === 'success' && !empty($data['countryCode'])) {
		return $data['countryCode'];
	}
	return '';
}

function flagDatabasePath() {
	$path = SCHAN_GEOIPDB;
	if ($path !== '' && $path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
		$path = dirname(__DIR__) . '/' . $path;
	}
	return $path;
}

function flagLookupDatabase($ip) {
	static $reader = null;
	static $failed = false;
	static $results = array();
	if ($failed || $ip === '') {
		return '';
	}
	if (isset($results[$ip])) {
		return $results[$ip];
	}
	$results[$ip] = '';
	try {
		if ($reader === null) {
			$path = flagDatabasePath();
			if (!is_readable($path)) {
				$failed = true;
				return '';
			}
			$reader = new SchanGeoIP($path);
		}
		$record = $reader->lookup($ip);
	} catch (Exception $e) {
		$failed = true;
		return '';
	}
	if (is_array($record)) {
		if (isset($record['country']['iso_code'])) {
			$results[$ip] = $record['country']['iso_code'];
		} elseif (isset($record['registered_country']['iso_code'])) {
			$results[$ip] = $record['registered_country']['iso_code'];
		}
		return $results[$ip];
	}
	return $results[$ip];
}

// short status line for the boards management page
function flagsStatusNote() {
	$count = 0;
	$dir = dirname(__DIR__) . '/static/flags/';
	if (is_dir($dir)) {
		$files = @scandir($dir);
		if (is_array($files)) {
			$count = count($files) - 2;
		}
	}
	$parts = array();
	$parts[] = $count > 0 ? ($count . ' flag images found in static/flags/') : 'no flag images found in static/flags/';
	$parts[] = count(memeFlagsList()) . ' meme flag images found in static/meme-flags/ (always on for all boards)';
	if (defined('SCHAN_CLOUDFLARE') && SCHAN_CLOUDFLARE) {
		$parts[] = 'Cloudflare country header in use';
	}
	if (is_readable(flagDatabasePath())) {
		$parts[] = 'GeoIP database found';
	} else {
		$parts[] = 'GeoIP database NOT found (expected at ' . htmlentities(SCHAN_GEOIPDB, ENT_QUOTES) . ')';
	}
	$ip = remoteAddress();
	$info = flagCountryInfo($ip);
	if ($info[0] !== '') {
		$parts[] = 'your IP ' . htmlentities($ip, ENT_QUOTES) . ' resolves to "' . htmlentities($info[0], ENT_QUOTES) . '" via ' . $info[1] . (flagFileFor($info[0]) === '' ? ' but static/flags/ has no image for it' : '');
	} else {
		$parts[] = 'your IP ' . htmlentities($ip, ENT_QUOTES) . ' resolved to no country (flags will not show for posts like yours until a lookup source works)';
	}
	return '<p><small>Flags: ' . implode('; ', $parts) . '.</small></p>';
}

// minimal MaxMind DB (.mmdb) reader

class SchanGeoIP {
	private $buf;
	private $nodeCount;
	private $recordSize;
	private $nodeBytes;
	private $treeSize;
	private $ipVersion;
	private $dataStart;
	private $ipv4Start = null;

	public function __construct($path) {
		$buf = @file_get_contents($path);
		if ($buf === false || $buf === '') {
			throw new Exception('Unable to read GeoIP database');
		}
		$this->buf = $buf;

		$marker = "\xAB\xCD\xEFMaxMind.com";
		$pos = strrpos($buf, $marker);
		if ($pos === false) {
			throw new Exception('Invalid GeoIP database');
		}
		$metaStart = $pos + strlen($marker);
		$off = $metaStart;
		$meta = $this->decode($off, $metaStart);
		if (!is_array($meta) || !isset($meta['node_count']) || !isset($meta['record_size']) || !isset($meta['ip_version'])) {
			throw new Exception('Invalid GeoIP metadata');
		}

		$this->nodeCount = (int)$meta['node_count'];
		$this->recordSize = (int)$meta['record_size'];
		$this->ipVersion = (int)$meta['ip_version'];
		if ($this->recordSize != 24 && $this->recordSize != 28 && $this->recordSize != 32) {
			throw new Exception('Unsupported record size');
		}
		$this->nodeBytes = (int)($this->recordSize / 4);
		$this->treeSize = $this->nodeBytes * $this->nodeCount;
		$this->dataStart = $this->treeSize + 16;
	}

	public function lookup($ip) {
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return null;
		}
		// treat IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) as IPv4
		if (strlen($packed) == 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xFF\xFF") {
			$packed = substr($packed, 12);
		}
		$len = strlen($packed);

		if ($len == 4) {
			$node = ($this->ipVersion == 6) ? $this->getIPv4Start() : 0;
		} else {
			if ($this->ipVersion == 4) {
				return null;
			}
			$node = 0;
		}

		$bits = $len * 8;
		for ($i = 0; $i < $bits && $node < $this->nodeCount; $i++) {
			$bit = (ord($packed[$i >> 3]) >> (7 - ($i & 7))) & 1;
			$node = $this->readNode($node, $bit);
		}

		if ($node <= $this->nodeCount) {
			return null;
		}
		$off = $this->treeSize + ($node - $this->nodeCount);
		return $this->decode($off, $this->dataStart);
	}

	private function getIPv4Start() {
		if ($this->ipv4Start === null) {
			$node = 0;
			for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
				$node = $this->readNode($node, 0);
			}
			$this->ipv4Start = $node;
		}
		return $this->ipv4Start;
	}

	private function readNode($node, $index) {
		$o = $node * $this->nodeBytes;
		$b = $this->buf;
		if ($this->recordSize == 24) {
			$o += $index * 3;
			return (ord($b[$o]) << 16) | (ord($b[$o + 1]) << 8) | ord($b[$o + 2]);
		}
		if ($this->recordSize == 28) {
			$middle = ord($b[$o + 3]);
			if ($index == 0) {
				return (($middle & 0xF0) << 20) | (ord($b[$o]) << 16) | (ord($b[$o + 1]) << 8) | ord($b[$o + 2]);
			}
			return (($middle & 0x0F) << 24) | (ord($b[$o + 4]) << 16) | (ord($b[$o + 5]) << 8) | ord($b[$o + 6]);
		}
		$o += $index * 4;
		return (ord($b[$o]) << 24) | (ord($b[$o + 1]) << 16) | (ord($b[$o + 2]) << 8) | ord($b[$o + 3]);
	}

	// decodes one value at $off (advancing it), $base is where the data section starts (pointers are relative to it)
	private function decode(&$off, $base) {
		$b = $this->buf;
		$ctrl = ord($b[$off]);
		$off++;
		$type = $ctrl >> 5;

		if ($type == 1) {
			$ss = ($ctrl >> 3) & 3;
			$vvv = $ctrl & 7;
			if ($ss == 0) {
				$p = ($vvv << 8) | ord($b[$off]);
				$off += 1;
			} elseif ($ss == 1) {
				$p = (($vvv << 16) | (ord($b[$off]) << 8) | ord($b[$off + 1])) + 2048;
				$off += 2;
			} elseif ($ss == 2) {
				$p = (($vvv << 24) | (ord($b[$off]) << 16) | (ord($b[$off + 1]) << 8) | ord($b[$off + 2])) + 526336;
				$off += 3;
			} else {
				$p = (ord($b[$off]) << 24) | (ord($b[$off + 1]) << 16) | (ord($b[$off + 2]) << 8) | ord($b[$off + 3]);
				$off += 4;
			}
			$target = $base + $p;
			return $this->decode($target, $base);
		}

		if ($type == 0) {
			$type = 7 + ord($b[$off]);
			$off++;
		}

		$size = $ctrl & 31;
		if ($size == 29) {
			$size = 29 + ord($b[$off]);
			$off += 1;
		} elseif ($size == 30) {
			$size = 285 + ((ord($b[$off]) << 8) | ord($b[$off + 1]));
			$off += 2;
		} elseif ($size == 31) {
			$size = 65821 + ((ord($b[$off]) << 16) | (ord($b[$off + 1]) << 8) | ord($b[$off + 2]));
			$off += 3;
		}

		switch ($type) {
			case 2: // UTF-8 string
			case 4: // bytes
				$v = substr($b, $off, $size);
				$off += $size;
				return $v;
			case 3: // double
				$u = unpack('E', substr($b, $off, 8));
				$off += 8;
				return $u[1];
			case 15: // float
				$u = unpack('G', substr($b, $off, 4));
				$off += 4;
				return $u[1];
			case 5: // uint16
			case 6: // uint32
			case 8: // int32
			case 9: // uint64
			case 10: // uint128
				$v = 0;
				for ($i = 0; $i < $size; $i++) {
					$v = ($v << 8) | ord($b[$off + $i]);
				}
				$off += $size;
				return $v;
			case 7: // map
				$map = array();
				for ($i = 0; $i < $size; $i++) {
					$key = $this->decode($off, $base);
					$map[$key] = $this->decode($off, $base);
				}
				return $map;
			case 11: // array
				$arr = array();
				for ($i = 0; $i < $size; $i++) {
					$arr[] = $this->decode($off, $base);
				}
				return $arr;
			case 14: // boolean
				return $size != 0;
			case 12: // data cache container
			case 13: // end marker
				return null;
		}
		throw new Exception('Unknown GeoIP data type');
	}
}
