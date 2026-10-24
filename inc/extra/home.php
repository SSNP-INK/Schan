<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * home page (index.html) builder and its manage section
 *
 * the page is made of BLOCKS in four ZONES: top (full width above the columns), left, right,
 * bottom (full width below the columns)
 * block types: image/logo, rotating banner, heading, boards list, recent images, recent posts, stats, custom HTML
 * add / remove / reorder / move between zones / switch off from ?manage&home
 *
 * written as a static file (index.html by default) on every board rebuild (Rebuild, or after a post, at most
 * once per "interval"). recent.php shows the same page live. it uses the boards' own stylesheets and classes
 * (postblock / reply) so it follows whichever theme the visitor picked, only layout css is added
 *
 * settings are JSON in .home.php (exits right away, like .pages.php)
 */

// install root on disk, this file can be in inc/ or inc/extra/ so go up to the folder with settings.php
function homeRoot() {
	static $root = null;
	if ($root === null) {
		$root = __DIR__;
		for ($i = 0; $i < 4; $i++) {
			if (is_file($root . '/settings.php')) {
				break;
			}
			$root = dirname($root);
		}
	}
	return $root;
}

function homeE($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// schema: every setting is described once and used for the form, the defaults and the sanitizer

function homeZones() {
	return array(
		'top'    => __('Top (full width)'),
		'left'   => __('Left column'),
		'right'  => __('Right column'),
		'bottom' => __('Bottom (full width)'),
	);
}

function homeGlobalFields() {
	return array(
		'enabled' => array('kind' => 'bool', 'label' => __('Generate the home page'), 'default' => 1,
			'note' => __('When off, the existing file is left alone.')),
		'file' => array('kind' => 'text', 'label' => __('File name'), 'default' => 'index.html',
			'note' => __('Written in the install directory. Letters, numbers, - and _, ending in .html. A file that was not made by this page is saved as .bak first.')),
		'site' => array('kind' => 'text', 'label' => __('Site name'), 'default' => '',
			'note' => __('Used for {site} in texts. Empty uses the host name.')),
		'title' => array('kind' => 'text', 'label' => __('Page title'), 'default' => '',
			'note' => __('Shown in the browser tab. Empty uses the site name.')),
		'width' => array('kind' => 'int', 'label' => __('Page width (pixels)'), 'default' => 900, 'min' => 300, 'max' => 2400),
		'left_width' => array('kind' => 'int', 'label' => __('Left column width (%)'), 'default' => 48, 'min' => 10, 'max' => 90),
		'gap' => array('kind' => 'int', 'label' => __('Space between boxes (pixels)'), 'default' => 8, 'min' => 0, 'max' => 60),
		'box_markup' => array('kind' => 'select', 'label' => __('Box markup'), 'default' => 'theme',
			'options' => array('theme' => __('Theme tables (same as the boards)'), 'plain' => __('Simple divs')),
			'note' => __('Both use the postblock / reply classes, so the active theme styles them.')),
		'show_boardbar' => array('kind' => 'bool', 'label' => __('Board bar'), 'default' => 1,
			'note' => __('The board links and your extra links at the top, like every other page.')),
		'header' => array('kind' => 'bool', 'label' => __('Page title at the top'), 'default' => 0,
			'note' => __('The big title line the boards show. Off by default because the page has its own logo / heading blocks.')),
		'globalmessage' => array('kind' => 'bool', 'label' => __('Global message'), 'default' => 0,
			'note' => __('Show the site-wide message on the home page. Off by default; the boards always show it.')),
		'page_banner' => array('kind' => 'bool', 'label' => __('Banner at the top'), 'default' => 0,
			'note' => __('Same as adding a Rotating banner block, but placed above everything.')),
		'exclude' => array('kind' => 'text', 'label' => __('Leave out boards'), 'default' => '',
			'note' => __('Board names separated by commas, e.g. test, staff. They are left out of every block.')),
		'hide_pending' => array('kind' => 'bool', 'label' => __('Leave out posts waiting for approval'), 'default' => 1),
		'auto' => array('kind' => 'bool', 'label' => __('Rebuild automatically when boards are rebuilt'), 'default' => 1,
			'note' => __('Runs after posts and on Rebuild. Turn off to rebuild only from this page.')),
		'interval' => array('kind' => 'int', 'label' => __('Automatic rebuild at most every (seconds)'), 'default' => 60, 'min' => 0, 'max' => 86400,
			'note' => __('Rebuild (and Save) always rebuild at once.')),
		'head' => array('kind' => 'textarea', 'label' => __('Extra <head> HTML'), 'default' => '', 'rows' => 3,
			'note' => __('Optional. Added at the end of the head. The standard head, style list, Manage / Options links, options window and footer are always included.')),
		'css' => array('kind' => 'textarea', 'label' => __('Custom CSS'), 'default' => '', 'rows' => 8,
			'note' => __('Optional. Added last. Classes: .homewrap .homebox .homeblock .homecols .homecol .postblock .reply .homeline .homeimg')),
	);
}

function homeBlockTypes() {
	return array(
		'image' => array('label' => __('Image / logo'), 'zone' => 'top', 'box' => 0, 'align' => 'center', 'title' => '', 'fields' => array(
			'src' => array('kind' => 'image', 'label' => __('Image address'), 'default' => '',
				'note' => __('A full address, or a path inside the install such as static/logo.png. Or upload one below.')),
			'width' => array('kind' => 'int', 'label' => __('Width (pixels, 0 = natural size)'), 'default' => 240, 'min' => 0, 'max' => 2000),
			'link' => array('kind' => 'text', 'label' => __('Link address'), 'default' => ''),
			'alt' => array('kind' => 'text', 'label' => __('Alternative text'), 'default' => ''),
		)),
		'banner' => array('label' => __('Rotating banner'), 'zone' => 'top', 'box' => 0, 'align' => 'center', 'title' => '', 'fields' => array(),
			'help' => __('Shows a random image from the banners folder, the same banner the boards show. Click it for another.')),
		'heading' => array('label' => __('Heading'), 'zone' => 'top', 'box' => 0, 'align' => 'center', 'title' => '', 'fields' => array(
			'text' => array('kind' => 'text', 'label' => __('Text'), 'default' => '{site}'),
			'sub' => array('kind' => 'text', 'label' => __('Smaller line under it'), 'default' => ''),
			'size' => array('kind' => 'int', 'label' => __('Text size (%)'), 'default' => 200, 'min' => 50, 'max' => 500),
		), 'help' => __('Texts can use {site}, {year}, {boards}, {posts}, {threads} and {updated}.')),
		'boards' => array('label' => __('Boards'), 'zone' => 'top', 'box' => 1, 'align' => 'left', 'title' => __('Boards'), 'fields' => array(
			'mode' => array('kind' => 'select', 'label' => __('List'), 'default' => 'auto',
				'options' => array('auto' => __('Every board, automatically'), 'custom' => __('My own list (below)'))),
			'label' => array('kind' => 'select', 'label' => __('Automatic list shows'), 'default' => 'title',
				'options' => array('title' => __('Board title'), 'slug' => __('/name/'), 'both' => __('/name/ and title'))),
			'columns' => array('kind' => 'int', 'label' => __('Automatic list columns'), 'default' => 2, 'min' => 1, 'max' => 6),
			'pages' => array('kind' => 'bool', 'label' => __('Automatic list also shows static pages'), 'default' => 0),
			'list' => array('kind' => 'textarea', 'label' => __('My own list'), 'default' => '', 'rows' => 9,
				'note' => __('One entry per line. "# Heading" starts a new column (add " | address" to link it). "board:name" or just the board name links a board. "page:name" links a static page. "Label | address" links anything.')),
		)),
		'recent_images' => array('label' => __('Recent images'), 'zone' => 'left', 'box' => 1, 'align' => 'left', 'title' => __('Recent Images'), 'fields' => array(
			'count' => array('kind' => 'int', 'label' => __('How many'), 'default' => 3, 'min' => 1, 'max' => 50),
			'width' => array('kind' => 'int', 'label' => __('Width (pixels, 0 = natural size)'), 'default' => 0, 'min' => 0, 'max' => 2000),
			'only' => array('kind' => 'text', 'label' => __('Only these boards'), 'default' => '', 'note' => __('Board names separated by commas. Empty means all.')),
			'spoilers' => array('kind' => 'bool', 'label' => __('Skip spoilered images'), 'default' => 1),
		)),
		'recent_posts' => array('label' => __('Recent posts'), 'zone' => 'right', 'box' => 1, 'align' => 'left', 'title' => __('Latest Posts'), 'fields' => array(
			'count' => array('kind' => 'int', 'label' => __('How many'), 'default' => 10, 'min' => 1, 'max' => 100),
			'length' => array('kind' => 'int', 'label' => __('Text length (characters)'), 'default' => 60, 'min' => 10, 'max' => 300),
			'label' => array('kind' => 'select', 'label' => __('Before each line show'), 'default' => 'title',
				'options' => array('title' => __('Board title'), 'slug' => __('/name/'), 'none' => __('Nothing'))),
			'threads' => array('kind' => 'bool', 'label' => __('Only new threads (no replies)'), 'default' => 0),
			'only' => array('kind' => 'text', 'label' => __('Only these boards'), 'default' => '', 'note' => __('Board names separated by commas. Empty means all.')),
		)),
		'stats' => array('label' => __('Stats'), 'zone' => 'right', 'box' => 1, 'align' => 'left', 'title' => __('Stats'), 'fields' => array(
			'posts' => array('kind' => 'bool', 'label' => __('Total posts'), 'default' => 1),
			'threads' => array('kind' => 'bool', 'label' => __('Total threads'), 'default' => 0),
			'posters' => array('kind' => 'bool', 'label' => __('Unique posters'), 'default' => 1),
			'content' => array('kind' => 'bool', 'label' => __('Active content'), 'default' => 1),
			'day' => array('kind' => 'bool', 'label' => __('Posts in the last 24 hours'), 'default' => 0),
			'boards' => array('kind' => 'bool', 'label' => __('Number of boards'), 'default' => 0),
			'last' => array('kind' => 'bool', 'label' => __('Time of the last post'), 'default' => 0),
		)),
		'html' => array('label' => __('Custom HTML'), 'zone' => 'bottom', 'box' => 0, 'align' => 'left', 'title' => __('Custom block'), 'fields' => array(
			'html' => array('kind' => 'textarea', 'label' => __('HTML'), 'default' => '', 'rows' => 8,
				'note' => __('Texts can use {site}, {year}, {boards}, {posts}, {threads} and {updated}.')),
		)),
	);
}

function homeCommonFields($type) {
	$t = homeBlockTypes();
	$d = $t[$type];
	return array(
		'enabled' => array('kind' => 'bool', 'label' => __('Show this block'), 'default' => 1),
		'zone' => array('kind' => 'select', 'label' => __('Place'), 'default' => $d['zone'], 'options' => homeZones()),
		'title' => array('kind' => 'text', 'label' => __('Title'), 'default' => $d['title']),
		'box' => array('kind' => 'bool', 'label' => __('Draw a box with the title'), 'default' => $d['box']),
		'align' => array('kind' => 'select', 'label' => __('Text alignment'), 'default' => $d['align'],
			'options' => array('left' => __('Left'), 'center' => __('Centered'), 'right' => __('Right'))),
		'class' => array('kind' => 'text', 'label' => __('Extra CSS class'), 'default' => ''),
	);
}

// defaults, storage

function homeNewBlock($type, $id) {
	$types = homeBlockTypes();
	$b = array('id' => $id, 'type' => $type);
	foreach (homeCommonFields($type) as $k => $def) {
		$b[$k] = $def['default'];
	}
	foreach ($types[$type]['fields'] as $k => $def) {
		$b[$k] = $def['default'];
	}
	return $b;
}

// A logo that already exists in the install, so the default page shows one
function homeFindLogo() {
	$root = homeRoot();
	foreach (array('logo', 'static/logo', 'static/home/logo', 'img/logo', 'images/logo') as $base) {
		foreach (array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg') as $ext) {
			if (is_file($root . '/' . $base . '.' . $ext)) {
				return $base . '.' . $ext;
			}
		}
	}
	return '';
}

function homeDefaults() {
	$cfg = array('next' => 1, 'blocks' => array());
	foreach (homeGlobalFields() as $k => $def) {
		$cfg[$k] = $def['default'];
	}
	$order = array('image', 'boards', 'recent_images', 'recent_posts', 'stats');
	foreach ($order as $type) {
		$b = homeNewBlock($type, 'b' . $cfg['next']);
		$cfg['next']++;
		if ($type === 'image') {
			$b['src'] = homeFindLogo();
		}
		$cfg['blocks'][] = $b;
	}
	return $cfg;
}

// fills anything missing so older saved settings keep working when options are added
function homeNormalize($stored) {
	if (!is_array($stored) || !isset($stored['blocks']) || !is_array($stored['blocks'])) {
		return homeDefaults();
	}
	$cfg = $stored;
	foreach (homeGlobalFields() as $k => $def) {
		if (!array_key_exists($k, $cfg)) {
			$cfg[$k] = $def['default'];
		}
	}
	$types = homeBlockTypes();
	$blocks = array();
	$max = 0;
	foreach ($cfg['blocks'] as $b) {
		if (!is_array($b) || empty($b['type']) || !isset($types[$b['type']]) || empty($b['id'])) {
			continue;
		}
		$full = homeNewBlock($b['type'], (string)$b['id']);
		foreach ($full as $k => $v) {
			if (array_key_exists($k, $b)) {
				$full[$k] = $b[$k];
			}
		}
		$zone_list = homeZones();
		if (!isset($zone_list[$full['zone']])) {
			$full['zone'] = $types[$b['type']]['zone'];
		}
		if (preg_match('/^b(\d+)$/', $full['id'], $m)) {
			$max = max($max, intval($m[1]));
		}
		$blocks[] = $full;
	}
	$cfg['blocks'] = $blocks;
	$cfg['next'] = max(isset($cfg['next']) ? intval($cfg['next']) : 1, $max + 1);
	return $cfg;
}

function homePath() {
	return homeRoot() . '/.home.php';
}

function homeLoad($force = false) {
	static $cache = null;
	if ($cache !== null && !$force) {
		return $cache;
	}
	$stored = array();
	$path = homePath();
	if (is_file($path)) {
		$fh = @fopen($path, 'r');
		if ($fh) {
			flock($fh, LOCK_SH);
			$raw = stream_get_contents($fh);
			flock($fh, LOCK_UN);
			fclose($fh);
			$nl = strpos($raw, "\n");
			$data = json_decode($nl === false ? '' : substr($raw, $nl + 1), true);
			if (is_array($data)) {
				$stored = $data;
			}
		}
	}
	$cache = homeNormalize($stored);
	return $cache;
}

function homeSave($cfg) {
	$flags = JSON_UNESCAPED_UNICODE;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}
	$fh = @fopen(homePath(), 'c+');
	if (!$fh) {
		return false;
	}
	$ok = false;
	if (flock($fh, LOCK_EX)) {
		$ok = ftruncate($fh, 0) && rewind($fh) && (fwrite($fh, "<?php http_response_code(404); exit; ?>\n" . json_encode($cfg, $flags)) !== false);
		fflush($fh);
		flock($fh, LOCK_UN);
	}
	fclose($fh);
	homeLoad(true);
	return $ok;
}

// small helpers

function homeSlugList($str) {
	$out = array();
	foreach (preg_split('/[\s,;]+/', strtolower(trim((string)$str)), -1, PREG_SPLIT_NO_EMPTY) as $s) {
		$out[$s] = $s;
	}
	return array_values($out);
}

function homeFileName($cfg) {
	$f = isset($cfg['file']) ? trim((string)$cfg['file']) : '';
	return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,60}\.html$/', $f) ? $f : 'index.html';
}

// relative paths are taken from the install root. Script-like addresses are refused
function homeURL($u) {
	$u = trim((string)$u);
	if ($u === '' || preg_match('/^\s*(javascript|data|vbscript):/i', $u)) {
		return '';
	}
	if (preg_match('#^([a-z][a-z0-9+.-]*:|//|/|\#|\?)#i', $u)) {
		return $u;
	}
	return installRootURL() . '/' . ltrim($u, '/');
}

function homeLen($s) {
	return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function homeSub($s, $len) {
	return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
}

// stored post text is already HTML-escaped; turn it back into plain text
function homePlain($html) {
	$t = str_ireplace(array('<br>', '<br/>', '<br />', "\n", "\r"), ' ', (string)$html);
	$t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
	return trim(preg_replace('/\s+/', ' ', $t));
}

function homeSize($bytes) {
	if ($bytes >= 1073741824) {
		return round($bytes / 1073741824, 1) . ' GB';
	}
	if ($bytes >= 1048576) {
		return round($bytes / 1048576, 1) . ' MB';
	}
	if ($bytes >= 1024) {
		return round($bytes / 1024, 1) . ' KB';
	}
	return $bytes . ' B';
}

function homeDate($ts) {
	return function_exists('formatDate') ? formatDate($ts) : date('Y-m-d H:i', $ts);
}

function homeSiteName($cfg) {
	$s = trim((string)$cfg['site']);
	if ($s === '' && !empty($_SERVER['HTTP_HOST'])) {
		$s = preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST']);
	}
	return $s !== '' ? $s : 'Home';
}

function homeTokens($cfg, $data) {
	return array(
		'{site}' => homeE(homeSiteName($cfg)),
		'{year}' => date('Y'),
		'{boards}' => count($data['boards']),
		'{posts}' => $data['stats']['posts'],
		'{threads}' => $data['stats']['threads'],
		'{updated}' => homeE(homeDate(time())),
	);
}

// data: one pass over every visible board

function homeVisibleBoards($cfg) {
	$exclude = homeSlugList($cfg['exclude']);
	$out = array();
	$all = function_exists('allBoards') ? allBoards() : array();
	foreach ($all as $b) {
		$slug = isset($b['slug']) ? $b['slug'] : '';
		if ($slug === '' || in_array(strtolower($slug), $exclude, true)) {
			continue;
		}
		$out[] = $b;
	}
	return $out;
}

function homeGather($cfg) {
	$boards = homeVisibleBoards($cfg);
	$titles = array();
	foreach ($boards as $b) {
		$titles[$b['slug']] = (isset($b['title']) && trim($b['title']) !== '') ? $b['title'] : $b['slug'];
	}

	$had = array_key_exists('schan_forced_board', $GLOBALS);
	$previous = $had ? $GLOBALS['schan_forced_board'] : null;

	$posts = array();
	$ips = array();
	$stats = array('posts' => 0, 'threads' => 0, 'posters' => 0, 'bytes' => 0, 'day' => 0, 'last' => 0);
	$now = time();

	foreach ($boards as $b) {
		$GLOBALS['schan_forced_board'] = $b['slug'];
		foreach (allThreads() as $thread) {
			foreach (postsInThreadByID($thread['id']) as $p) {
				if (function_exists('postEditsApply')) {
					$p = postEditsApply($p);
				}
				$pending = isset($p['moderated']) && intval($p['moderated']) == 0;
				if (!empty($cfg['hide_pending']) && $pending) {
					continue;
				}
				$media_pending = function_exists('postMediaPending') && postMediaPending($p);
				$message = isset($p['message']) ? $p['message'] : '';
				if (function_exists('postLinksPending') && function_exists('maskPendingLinks') && postLinksPending($p)) {
					$message = maskPendingLinks($message);
				}

				$ts = intval($p['timestamp']);
				$is_op = (intval($p['parent']) == 0);
				$spoiler = !empty($p['spoiler']) || (function_exists('spoilerIsPost') && spoilerIsPost($p));
				$has_thumb = !$media_pending && !empty($p['thumb']) && !empty($p['thumb_width']) && !empty($p['thumb_height']);
				$text = homePlain(isset($p['subject']) ? $p['subject'] : '');
				if ($text === '') {
					$text = homePlain(homeSub($message, 600));
				}

				$stats['posts']++;
				if ($is_op) {
					$stats['threads']++;
				}
				if (isset($p['ip']) && $p['ip'] !== '') {
					$ips[$p['ip']] = true;
				}
				if (!$media_pending && isset($p['file_size']) && intval($p['file_size']) > 0) {
					$stats['bytes'] += intval($p['file_size']);
				}
				if ($now - $ts <= 86400) {
					$stats['day']++;
				}
				if ($ts > $stats['last']) {
					$stats['last'] = $ts;
				}

				$posts[] = array(
					'board' => $b['slug'],
					'id' => intval($p['id']),
					'thread' => $is_op ? intval($p['id']) : intval($p['parent']),
					'op' => $is_op,
					'time' => $ts,
					'text' => $text,
					'thumb' => $has_thumb ? $p['thumb'] : '',
					'tw' => $has_thumb ? intval($p['thumb_width']) : 0,
					'th' => $has_thumb ? intval($p['thumb_height']) : 0,
					'spoiler' => $spoiler,
				);
			}
		}
	}

	if ($had) {
		$GLOBALS['schan_forced_board'] = $previous;
	} else {
		unset($GLOBALS['schan_forced_board']);
	}

	$stats['posters'] = count($ips);
	usort($posts, function ($a, $b) {
		if ($a['time'] != $b['time']) {
			return $b['time'] - $a['time'];
		}
		return $b['id'] - $a['id'];
	});

	return array('boards' => $boards, 'titles' => $titles, 'posts' => $posts, 'stats' => $stats);
}

function homePostURL($p) {
	return homeE(installRootURL() . '/' . rawurlencode($p['board']) . '/res/' . intval($p['thread']) . '.html#' . intval($p['id']));
}

// rendering

function homeBox($markup, $title, $body, $extra_class) {
	$cls = 'homebox' . ($extra_class !== '' ? ' ' . $extra_class : '');
	$head = $title !== '' ? $title : '';
	if ($markup === 'plain') {
		return '<div class="' . $cls . '">'
			. ($head !== '' ? '<div class="postblock">' . $head . '</div>' : '')
			. '<div class="reply">' . $body . '</div></div>';
	}
	return '<table class="' . $cls . '"><tbody>'
		. ($head !== '' ? '<tr><td class="postblock">' . $head . '</td></tr>' : '')
		. '<tr><td class="reply">' . $body . '</td></tr></tbody></table>';
}

function homeRenderBoardsBlock($b, $cfg, $data) {
	$root = installRootURL();
	$board_url = function ($slug) use ($root) {
		return homeE($root . '/' . rawurlencode($slug) . '/');
	};

	if ($b['mode'] === 'custom' && trim($b['list']) !== '') {
		$map = array();
		foreach ($data['boards'] as $row) {
			$map[strtolower($row['slug'])] = $row;
		}
		$pages = function_exists('pagesLoad') ? pagesLoad() : array();
		$groups = array(array('head' => '', 'items' => array()));
		$g = 0;
		foreach (preg_split('/\r?\n/', $b['list']) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if ($line[0] === '#') {
				$parts = array_map('trim', explode('|', ltrim($line, "# \t"), 2));
				$head = homeE($parts[0]);
				$url = isset($parts[1]) ? homeURL($parts[1]) : '';
				if ($url !== '') {
					$head = '<a href="' . homeE($url) . '">' . $head . '</a>';
				}
				if ($g === 0 && empty($groups[0]['items']) && $groups[0]['head'] === '') {
					$groups[0]['head'] = '<strong>' . $head . '</strong>';
				} else {
					$groups[] = array('head' => '<strong>' . $head . '</strong>', 'items' => array());
					$g++;
				}
				continue;
			}
			$item = '';
			if (strpos($line, '|') !== false) {
				$parts = array_map('trim', explode('|', $line, 2));
				$url = homeURL($parts[1]);
				$item = $url !== '' ? '<a href="' . homeE($url) . '">' . homeE($parts[0]) . '</a>' : homeE($parts[0]);
			} else {
				$name = $line;
				$kind = 'board';
				if (stripos($name, 'page:') === 0) {
					$kind = 'page';
					$name = trim(substr($name, 5));
				} else if (stripos($name, 'board:') === 0) {
					$name = trim(substr($name, 6));
				}
				$name = trim($name, '/ ');
				$key = strtolower($name);
				if ($kind === 'board' && isset($map[$key])) {
					$row = $map[$key];
					$label = trim($row['title']) !== '' ? $row['title'] : $row['slug'];
					$item = '<a href="' . $board_url($row['slug']) . '">' . homeE($label) . '</a>';
				} else if ($kind === 'page' && isset($pages[$key])) {
					$item = '<a href="' . homeE($root . '/' . $key . '.html') . '">' . homeE($pages[$key]['title']) . '</a>';
				} else {
					$item = homeE($line);
				}
			}
			$groups[$g]['items'][] = '<div class="homeline">' . $item . '</div>';
		}
		$out = '<div class="homegroups">';
		foreach ($groups as $grp) {
			if ($grp['head'] === '' && empty($grp['items'])) {
				continue;
			}
			$out .= '<div class="homegroup">' . ($grp['head'] !== '' ? '<div class="homeline">' . $grp['head'] . '</div>' : '') . implode('', $grp['items']) . '</div>';
		}
		return $out . '</div>';
	}

	$items = array();
	foreach ($data['boards'] as $row) {
		$title = $data['titles'][$row['slug']];
		if ($b['label'] === 'slug') {
			$label = '/' . $row['slug'] . '/';
		} else if ($b['label'] === 'both') {
			$label = '/' . $row['slug'] . '/ - ' . $title;
		} else {
			$label = $title;
		}
		$items[] = '<div class="homeline"><a href="' . $board_url($row['slug']) . '">' . homeE($label) . '</a></div>';
	}
	if (!empty($b['pages']) && function_exists('pagesLoad')) {
		foreach (pagesLoad() as $slug => $page) {
			$items[] = '<div class="homeline"><a href="' . homeE($root . '/' . $slug . '.html') . '">' . homeE($page['title']) . '</a></div>';
		}
	}
	if (empty($items)) {
		return '<div class="homeempty"><i>' . __('No boards yet.') . '</i></div>';
	}
	return '<div class="homelist" style="-webkit-column-count:' . intval($b['columns']) . ';column-count:' . intval($b['columns']) . ';">' . implode('', $items) . '</div>';
}

function homeRenderRecentImages($b, $data) {
	$only = homeSlugList($b['only']);
	$root = installRootURL();
	$out = '';
	$n = 0;
	foreach ($data['posts'] as $p) {
		if ($p['thumb'] === '' || (!empty($b['spoilers']) && $p['spoiler'])) {
			continue;
		}
		if (!empty($only) && !in_array(strtolower($p['board']), $only, true)) {
			continue;
		}
		$src = homeE($root . '/' . rawurlencode($p['board']) . '/thumb/' . rawurlencode($p['thumb']));
		$style = intval($b['width']) > 0 ? ' style="width:' . intval($b['width']) . 'px;max-width:100%;height:auto;"' : '';
		$out .= '<div class="homeimg"><a href="' . homePostURL($p) . '"><img src="' . $src . '" alt="" class="thumb" width="' . $p['tw'] . '" height="' . $p['th'] . '"' . $style . '></a></div>';
		if (++$n >= intval($b['count'])) {
			break;
		}
	}
	return $out !== '' ? $out : '<div class="homeempty"><i>' . __('No images yet.') . '</i></div>';
}

function homeRenderRecentPosts($b, $data) {
	$only = homeSlugList($b['only']);
	$len = max(10, intval($b['length']));
	$out = '';
	$n = 0;
	foreach ($data['posts'] as $p) {
		if (!empty($b['threads']) && !$p['op']) {
			continue;
		}
		if (!empty($only) && !in_array(strtolower($p['board']), $only, true)) {
			continue;
		}
		$text = $p['text'];
		if ($text === '') {
			$text = '>>' . $p['id'];
		}
		if (homeLen($text) > $len) {
			$text = homeSub($text, $len) . '...';
		}
		$label = '';
		if ($b['label'] === 'title') {
			$label = '<strong>' . homeE($data['titles'][$p['board']]) . '</strong>: ';
		} else if ($b['label'] === 'slug') {
			$label = '<strong>/' . homeE($p['board']) . '/</strong>: ';
		}
		$out .= '<div class="homeline">' . $label . '<a href="' . homePostURL($p) . '"><em>' . homeE($text) . '</em></a></div>';
		if (++$n >= intval($b['count'])) {
			break;
		}
	}
	return $out !== '' ? $out : '<div class="homeempty"><i>' . __('No posts yet.') . '</i></div>';
}

function homeRenderStats($b, $data) {
	$s = $data['stats'];
	$rows = array();
	if (!empty($b['posts'])) {
		$rows[] = __('Total posts') . ': ' . intval($s['posts']);
	}
	if (!empty($b['threads'])) {
		$rows[] = __('Total threads') . ': ' . intval($s['threads']);
	}
	if (!empty($b['posters'])) {
		$rows[] = __('Unique posters') . ': ' . intval($s['posters']);
	}
	if (!empty($b['content'])) {
		$rows[] = __('Active content') . ': ' . homeE(homeSize($s['bytes']));
	}
	if (!empty($b['day'])) {
		$rows[] = __('Posts in the last 24 hours') . ': ' . intval($s['day']);
	}
	if (!empty($b['boards'])) {
		$rows[] = __('Boards') . ': ' . count($data['boards']);
	}
	if (!empty($b['last']) && $s['last'] > 0) {
		$rows[] = __('Last post') . ': ' . homeE(homeDate($s['last']));
	}
	$out = '';
	foreach ($rows as $r) {
		$out .= '<div class="homeline">' . $r . '</div>';
	}
	return $out;
}

function homeRenderBlock($b, $cfg, $data, $tok) {
	$type = $b['type'];
	$inner = '';
	switch ($type) {
		case 'image':
			$src = homeURL($b['src']);
			if ($src === '') {
				return '';
			}
			$style = intval($b['width']) > 0 ? ' style="width:' . intval($b['width']) . 'px;max-width:100%;height:auto;"' : ' style="max-width:100%;height:auto;"';
			$inner = '<img src="' . homeE($src) . '" alt="' . homeE($b['alt']) . '"' . $style . '>';
			$link = homeURL($b['link']);
			if ($link !== '') {
				$inner = '<a href="' . homeE($link) . '">' . $inner . '</a>';
			}
			break;
		case 'banner':
			$inner = function_exists('bannerHTML') ? bannerHTML() : '';
			if ($inner === '') {
				return '';
			}
			break;
		case 'heading':
			$size = max(50, intval($b['size']));
			$inner = '<h1 class="homeheading" style="font-size:' . $size . '%;margin:.2em 0;">' . strtr(homeE($b['text']), $tok) . '</h1>';
			if (trim($b['sub']) !== '') {
				$inner .= '<div class="homesub">' . strtr(homeE($b['sub']), $tok) . '</div>';
			}
			break;
		case 'boards':
			$inner = homeRenderBoardsBlock($b, $cfg, $data);
			break;
		case 'recent_images':
			$inner = homeRenderRecentImages($b, $data);
			break;
		case 'recent_posts':
			$inner = homeRenderRecentPosts($b, $data);
			break;
		case 'stats':
			$inner = homeRenderStats($b, $data);
			break;
		case 'html':
			// administrator-written HTML, like a static page
			$inner = strtr((string)$b['html'], $tok);
			if (trim($inner) === '') {
				return '';
			}
			break;
		default:
			return '';
	}

	$cls = 'homeblock homeblock-' . $type;
	$extra = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string)$b['class']));
	if ($extra !== '') {
		$cls .= ' ' . $extra;
	}
	$align = in_array($b['align'], array('left', 'center', 'right'), true) ? $b['align'] : 'left';
	$astyle = $align !== 'left' ? ' style="text-align:' . $align . ';"' : '';

	if (!empty($b['box'])) {
		$title = strtr(homeE($b['title']), $tok);
		return '<div class="' . $cls . '">' . homeBox($cfg['box_markup'], $title, '<div' . $astyle . '>' . $inner . '</div>', '') . '</div>';
	}
	return '<div class="' . $cls . '"' . $astyle . '>' . $inner . '</div>';
}

function homeBuildHTML($cfg) {
	$root = installRootURL();
	$data = homeGather($cfg);
	$tok = homeTokens($cfg, $data);

	$zones = array('top' => '', 'left' => '', 'right' => '', 'bottom' => '');
	foreach ($cfg['blocks'] as $b) {
		if (empty($b['enabled'])) {
			continue;
		}
		$zones[$b['zone']] .= homeRenderBlock($b, $cfg, $data, $tok);
	}

	$cols = '';
	if ($zones['left'] !== '' && $zones['right'] !== '') {
		$cols = '<div class="homecols"><div class="homecol left">' . $zones['left'] . '</div><div class="homecol right">' . $zones['right'] . '</div></div>';
	} else if ($zones['left'] !== '' || $zones['right'] !== '') {
		$cols = '<div class="homecols"><div class="homecol only">' . $zones['left'] . $zones['right'] . '</div></div>';
	}

	$gap = intval($cfg['gap']);
	$css = '.homewrap{max-width:' . intval($cfg['width']) . 'px;margin:10px auto;padding:0 8px;box-sizing:border-box;display:flex;flex-direction:column;gap:' . $gap . 'px;}'
		. '.homecols{display:flex;gap:' . $gap . 'px;align-items:flex-start;}'
		. '.homecol{display:flex;flex-direction:column;gap:' . $gap . 'px;min-width:0;}'
		. '.homecol.left{flex:0 0 ' . intval($cfg['left_width']) . '%;max-width:' . intval($cfg['left_width']) . '%;}'
		. '.homecol.right,.homecol.only{flex:1 1 0;}'
		. '.homeblock{min-width:0;}'
		. '.homebox{width:100%;border-collapse:collapse;box-sizing:border-box;}'
		. '.homebox td{padding:4px 8px;}'
		. '.homebox td.postblock,.homebox td.reply,div.homebox>div.postblock,div.homebox>div.reply{border:' . (defined('SCHAN_HOMEBOXBORDER') ? SCHAN_HOMEBOXBORDER : '1px') . ' solid currentColor !important;}'
		. '.homebox td.reply{display:table-cell;width:auto;}'
		. 'div.homebox>div.postblock,div.homebox>div.reply{display:block;width:auto;margin:0;padding:4px 8px;box-sizing:border-box;}'
		. '.homeimg{text-align:center;padding:4px 0;}'
		. '.homeimg img{max-width:100%;height:auto;}'
		. '.homeline{padding:2px 0;overflow-wrap:anywhere;}'
		. '.homelist>.homeline{break-inside:avoid;}'
		. '.homegroups{display:flex;flex-wrap:wrap;gap:6px 28px;}'
		. '.homegroup{min-width:0;}'
		. '.homeempty{text-align:center;}'
		. '@media screen and (max-width:696px){.homecols{display:block;}.homecol.left{max-width:none;}.homecol{margin-bottom:' . $gap . 'px;}}';

	if (!empty($cfg['show_boardbar'])) {
		$css .= '.homewrap{padding-top:28px;}';
	}

	$content = '<!-- schan-home -->'
		. '<style>' . $css . '</style>'
		. '<div class="homewrap">' . $zones['top'] . $cols . $zones['bottom'] . '</div>';

	$title = trim($cfg['title']) !== '' ? strtr($cfg['title'], array('{site}' => homeSiteName($cfg))) : homeSiteName($cfg);

	// same page builder as the boards and static pages: head and styles, board bar, Manage/Options links,
	// options window (style, hover preview, 5ch mode, user css), global message, footer
	if (empty($cfg['globalmessage'])) {
		$GLOBALS['schan_no_globalmessage'] = true;
	}
	$page = buildStaticPage(array(
		'title' => $title,
		'content' => $content,
		'css' => $cfg['css'],
		'header' => !empty($cfg['header']) ? 1 : 0,
		'banner' => !empty($cfg['page_banner']) ? 1 : 0,
		'boardbar' => !empty($cfg['show_boardbar']) ? 1 : 0,
	));

	unset($GLOBALS['schan_no_globalmessage']);

	if (trim($cfg['head']) !== '') {
		$page = str_ireplace('</head>', $cfg['head'] . "\n</head>", $page);
	}
	return $page;
}

// writing the file

function homeWriteFile($path, $html) {
	$tmp = $path . '.tmp' . getmypid();
	if (@file_put_contents($tmp, $html, LOCK_EX) === false) {
		return false;
	}
	@chmod($tmp, 0664);
	if (!@rename($tmp, $path)) {
		@unlink($tmp);
		return false;
	}
	return true;
}

// returns 1 when the page was written, 0 when skipped, -1 when it could not be written
function rebuildHomePage($force = false) {
	$cfg = homeLoad();
	if (empty($cfg['enabled'])) {
		return 0;
	}
	$path = homeRoot() . '/' . homeFileName($cfg);

	if (!$force) {
		if (empty($cfg['auto'])) {
			return 0;
		}
		if (is_file($path) && intval($cfg['interval']) > 0 && filemtime($path) > time() - intval($cfg['interval'])
			&& strpos((string)@file_get_contents($path), '<!-- schan-home -->') !== false) {
			return 0;
		}
	}

	// keep a page that this module did not make
	if (is_file($path) && strpos((string)@file_get_contents($path), '<!-- schan-home -->') === false) {
		$bak = $path . '.bak';
		if (is_file($bak)) {
			$bak .= '.' . time();
		}
		@copy($path, $bak);
	}

	return homeWriteFile($path, homeBuildHTML($cfg)) ? 1 : -1;
}

// saving the manage form

function homeCleanField($def, $posted, $key, $old) {
	$has = is_array($posted) && array_key_exists($key, $posted);
	switch ($def['kind']) {
		case 'bool':
			return ($has && $posted[$key] !== '' && $posted[$key] !== '0') ? 1 : 0;
		case 'int':
			if (!$has) {
				return $old;
			}
			$min = isset($def['min']) ? $def['min'] : 0;
			$max = isset($def['max']) ? $def['max'] : 100000;
			return max($min, min($max, intval($posted[$key])));
		case 'select':
			return ($has && is_string($posted[$key]) && array_key_exists($posted[$key], $def['options'])) ? $posted[$key] : $old;
		case 'textarea':
			return $has && is_string($posted[$key]) ? str_replace("\r\n", "\n", $posted[$key]) : $old;
		default:
			if (!$has || !is_string($posted[$key])) {
				return $old;
			}
			$v = trim(str_replace(array("\r", "\n"), ' ', $posted[$key]));
			return homeLen($v) > 500 ? homeSub($v, 500) : $v;
	}
}

// stores an uploaded image in static/home/ and returns its path, or '' (with $error set)
function homeUpload($id, &$error) {
	$error = '';
	if (empty($_FILES['up']['name'][$id])) {
		return '';
	}
	$code = isset($_FILES['up']['error'][$id]) ? $_FILES['up']['error'][$id] : UPLOAD_ERR_NO_FILE;
	if ($code === UPLOAD_ERR_NO_FILE) {
		return '';
	}
	$tmp = isset($_FILES['up']['tmp_name'][$id]) ? $_FILES['up']['tmp_name'][$id] : '';
	if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
		$error = __('The image could not be uploaded.');
		return '';
	}
	if (filesize($tmp) > 5 * 1024 * 1024) {
		$error = __('The image is larger than 5 MB.');
		return '';
	}
	$info = @getimagesize($tmp);
	$types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif');
	if (defined('IMAGETYPE_WEBP')) {
		$types[IMAGETYPE_WEBP] = 'webp';
	}
	if (!$info || !isset($types[$info[2]])) {
		$error = __('Only PNG, JPEG, GIF and WebP images can be uploaded.');
		return '';
	}
	$dir = homeRoot() . '/static/home';
	if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
		$error = __('Could not create static/home. The install directory must be writable.');
		return '';
	}
	$name = 'img-' . substr(md5(uniqid('', true)), 0, 12) . '.' . $types[$info[2]];
	if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
		$error = __('The image could not be saved.');
		return '';
	}
	@chmod($dir . '/' . $name, 0644);
	return 'static/home/' . $name;
}

function homeApplyPost($cfg, &$notes) {
	$g = (isset($_POST['g']) && is_array($_POST['g'])) ? $_POST['g'] : array();
	foreach (homeGlobalFields() as $k => $def) {
		$cfg[$k] = homeCleanField($def, $g, $k, $cfg[$k]);
	}
	$cfg['file'] = homeFileName($cfg);

	$posted = (isset($_POST['b']) && is_array($_POST['b'])) ? $_POST['b'] : array();
	$types = homeBlockTypes();
	foreach ($cfg['blocks'] as $i => $b) {
		if (!isset($posted[$b['id']]) || !is_array($posted[$b['id']])) {
			continue;
		}
		$row = $posted[$b['id']];
		foreach (homeCommonFields($b['type']) as $k => $def) {
			$cfg['blocks'][$i][$k] = homeCleanField($def, $row, $k, $b[$k]);
		}
		foreach ($types[$b['type']]['fields'] as $k => $def) {
			$cfg['blocks'][$i][$k] = homeCleanField($def, $row, $k, $b[$k]);
		}
		if ($b['type'] === 'image') {
			$err = '';
			$path = homeUpload($b['id'], $err);
			if ($path !== '') {
				$cfg['blocks'][$i]['src'] = $path;
			}
			if ($err !== '') {
				$notes[] = $err;
			}
		}
	}
	return $cfg;
}

function homeMoveBlock(&$blocks, $id, $dir) {
	$idx = null;
	foreach ($blocks as $i => $b) {
		if ($b['id'] === $id) {
			$idx = $i;
		}
	}
	if ($idx === null) {
		return;
	}
	$step = ($dir === 'up') ? -1 : 1;
	$j = $idx + $step;
	while ($j >= 0 && $j < count($blocks) && $blocks[$j]['zone'] !== $blocks[$idx]['zone']) {
		$j += $step;
	}
	if ($j >= 0 && $j < count($blocks)) {
		$tmp = $blocks[$idx];
		$blocks[$idx] = $blocks[$j];
		$blocks[$j] = $tmp;
	}
}

// manage page

function homeFieldInput($name, $def, $value, $upload_id = '') {
	$n = homeE($name);
	switch ($def['kind']) {
		case 'bool':
			return '<input type="checkbox" name="' . $n . '" value="1"' . ($value ? ' checked' : '') . '>';
		case 'int':
			return '<input type="number" name="' . $n . '" value="' . intval($value) . '" min="' . intval(isset($def['min']) ? $def['min'] : 0) . '" max="' . intval(isset($def['max']) ? $def['max'] : 100000) . '" style="width:90px;">';
		case 'select':
			$o = '<select name="' . $n . '">';
			foreach ($def['options'] as $k => $label) {
				$o .= '<option value="' . homeE($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . homeE($label) . '</option>';
			}
			return $o . '</select>';
		case 'textarea':
			return '<textarea name="' . $n . '" rows="' . intval(isset($def['rows']) ? $def['rows'] : 5) . '" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px;" spellcheck="false">' . homeE($value) . '</textarea>';
		case 'image':
			return '<input type="text" name="' . $n . '" value="' . homeE($value) . '" size="50" maxlength="500">'
				. '<br><input type="file" name="up[' . homeE($upload_id) . ']" accept="image/png,image/jpeg,image/gif,image/webp">';
		default:
			return '<input type="text" name="' . $n . '" value="' . homeE($value) . '" size="50" maxlength="500">';
	}
}

function homeFieldRow($name, $def, $value, $upload_id = '') {
	$note = !empty($def['note']) ? '<br><small>' . homeE($def['note']) . '</small>' : '';
	return '<tr><td valign="top" style="white-space:nowrap;padding-right:12px;"><b>' . homeE($def['label']) . '</b></td><td>'
		. homeFieldInput($name, $def, $value, $upload_id) . $note . '</td></tr>';
}

function homeBlockForm($b, $position, $count) {
	$types = homeBlockTypes();
	$t = $types[$b['type']];
	$id = homeE($b['id']);
	$zones = homeZones();

	$summary = '<b>' . homeE($t['label']) . '</b>'
		. (trim($b['title']) !== '' ? ' &mdash; ' . homeE($b['title']) : '')
		. (empty($b['enabled']) ? ' <small>(' . __('hidden') . ')</small>' : '');

	$rows = '';
	foreach (homeCommonFields($b['type']) as $k => $def) {
		$rows .= homeFieldRow('b[' . $b['id'] . '][' . $k . ']', $def, $b[$k]);
	}
	foreach ($t['fields'] as $k => $def) {
		$rows .= homeFieldRow('b[' . $b['id'] . '][' . $k . ']', $def, $b[$k], $b['id']);
	}

	$help = !empty($t['help']) ? '<p><small>' . homeE($t['help']) . '</small></p>' : '';
	$preview = '';
	if ($b['type'] === 'image' && homeURL($b['src']) !== '') {
		$preview = '<p><img src="' . homeE(homeURL($b['src'])) . '" alt="" style="max-width:160px;max-height:90px;"></p>';
	}

	$btn = function ($action, $label, $confirm = '') use ($id) {
		return '<button type="submit" name="home_action" value="' . $action . ':' . $id . '" class="managebutton"'
			. ($confirm !== '' ? ' onclick="return confirm(\'' . $confirm . '\');"' : '') . '>' . $label . '</button> ';
	};

	return '<details open style="margin:6px 0;border:1px solid;padding:4px 8px;"><summary style="cursor:pointer;">' . $summary . '</summary>'
		. $help . $preview . '<table>' . $rows . '</table><p>'
		. $btn('up', __('Move up')) . $btn('down', __('Move down'))
		. $btn('remove', __('Remove block'), __('Remove this block?'))
		. '</p></details>';
}

function manageHomeForm($cfg) {
	$root = installRootURL();
	$file = homeFileName($cfg);
	$url = $root . '/' . $file;
	$path = homeRoot() . '/' . $file;
	$built = is_file($path) ? filemtime($path) : 0;
	$types = homeBlockTypes();

	$out = '<fieldset><legend>' . __('Home page') . '</legend>'
		. '<p>' . sprintf(__('The home page is %s. It is rebuilt with the boards, and by the buttons below.'), '<a href="' . homeE($url) . '" target="_blank">' . homeE($file) . '</a>')
		. ' ' . ($built ? sprintf(__('Last built %s.'), homeE(homeDate($built))) : __('Not built yet.'))
		. ' <a href="' . homeE($root . '/recent.php') . '" target="_blank">recent.php</a> ' . __('shows the same page live.') . '</p>'
		. '<form method="post" action="?manage&amp;home" style="display:inline;"><button type="submit" name="home_action" value="rebuild" class="managebutton">' . __('Rebuild now') . '</button></form> '
		. '<form method="post" action="?manage&amp;home" style="display:inline;" onsubmit="return confirm(\'' . __('Replace every setting and block with the defaults?') . '\');"><button type="submit" name="home_action" value="reset" class="managebutton">' . __('Restore defaults') . '</button></form>'
		. '</fieldset><br>';

	$out .= '<form method="post" action="?manage&amp;home" enctype="multipart/form-data" id="schan" name="schan">'
		. '<button type="submit" name="home_action" value="save" class="managebutton">' . __('Save and rebuild') . '</button><br><br>';

	// blocks, grouped by where they sit on the page
	$out .= '<fieldset><legend>' . __('Blocks') . '</legend>'
		. '<p><small>' . __('The page is shown top to bottom: Top, then Left and Right columns side by side, then Bottom. Blocks keep the order shown here inside their place.') . '</small></p>';
	foreach (homeZones() as $zone => $label) {
		$out .= '<h4 style="margin:10px 0 2px;">' . homeE($label) . '</h4>';
		$any = false;
		foreach ($cfg['blocks'] as $i => $b) {
			if ($b['zone'] !== $zone) {
				continue;
			}
			$any = true;
			$out .= homeBlockForm($b, $i, count($cfg['blocks']));
		}
		if (!$any) {
			$out .= '<p><i>' . __('Nothing here.') . '</i></p>';
		}
	}

	$type_opts = '';
	foreach ($types as $k => $t) {
		$type_opts .= '<option value="' . homeE($k) . '">' . homeE($t['label']) . '</option>';
	}
	$zone_opts = '';
	foreach (homeZones() as $k => $label) {
		$zone_opts .= '<option value="' . homeE($k) . '">' . homeE($label) . '</option>';
	}
	$out .= '<hr><p><b>' . __('Add a block') . '</b>: '
		. '<select name="add_type">' . $type_opts . '</select> '
		. '<select name="add_zone"><option value="">' . __('Default place') . '</option>' . $zone_opts . '</select> '
		. '<button type="submit" name="home_action" value="add" class="managebutton">' . __('Add') . '</button>'
		. '<br><small>' . __('Adding, moving or removing a block also saves the changes made above.') . '</small></p>'
		. '</fieldset><br>';

	// page-wide settings
	$rows = '';
	foreach (homeGlobalFields() as $k => $def) {
		$rows .= homeFieldRow('g[' . $k . ']', $def, $cfg[$k]);
	}
	$out .= '<fieldset><legend>' . __('Page settings') . '</legend><table>' . $rows . '</table></fieldset><br>'
		. '<button type="submit" name="home_action" value="save" class="managebutton">' . __('Save and rebuild') . '</button>'
		. '</form><br>';

	if ($built) {
		$out .= '<fieldset><legend>' . __('Preview') . '</legend>'
			. '<iframe src="' . homeE($url . '?t=' . $built) . '" loading="lazy" style="width:100%;height:640px;border:1px solid;background:#fff;"></iframe>'
			. '</fieldset><br>';
	}
	return $out;
}

function manageHomeResultNote($r, $file) {
	if ($r > 0) {
		return sprintf(__('Home page rebuilt: %s'), '<a href="' . homeE(installRootURL() . '/' . $file) . '" target="_blank">' . homeE($file) . '</a>');
	}
	if ($r < 0) {
		return __('The home page could not be written. The install directory must be writable.');
	}
	return __('The home page is turned off, so nothing was written. Tick "Generate the home page" and save.');
}

// entry point for ?manage&home. Handles the form, returns the page
function manageHomeEntry() {
	$notes = array();
	$cfg = homeLoad();
	$act = (isset($_POST['home_action']) && is_string($_POST['home_action'])) ? $_POST['home_action'] : '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act !== '') {
		if ($act === 'rebuild') {
			$notes[] = manageHomeResultNote(rebuildHomePage(true), homeFileName($cfg));
		} else if ($act === 'reset') {
			if (homeSave(homeDefaults())) {
				$cfg = homeLoad();
				$notes[] = __('Defaults restored.') . ' ' . manageHomeResultNote(rebuildHomePage(true), homeFileName($cfg));
			} else {
				$notes[] = __('The settings could not be saved. The install directory must be writable.');
			}
		} else {
			$cfg = homeApplyPost($cfg, $notes);
			$types = homeBlockTypes();
			$parts = explode(':', $act, 2);
			$verb = $parts[0];
			$target = isset($parts[1]) ? $parts[1] : '';

			if ($verb === 'add') {
				$type = (isset($_POST['add_type']) && is_string($_POST['add_type'])) ? $_POST['add_type'] : '';
				if (isset($types[$type])) {
					$b = homeNewBlock($type, 'b' . $cfg['next']);
					$cfg['next']++;
					$zone = (isset($_POST['add_zone']) && is_string($_POST['add_zone'])) ? $_POST['add_zone'] : '';
					$zone_list = homeZones();
					if (isset($zone_list[$zone])) {
						$b['zone'] = $zone;
					}
					$cfg['blocks'][] = $b;
					$notes[] = sprintf(__('Added block: %s'), homeE($types[$type]['label']));
				}
			} else if ($verb === 'remove') {
				foreach ($cfg['blocks'] as $i => $b) {
					if ($b['id'] === $target) {
						unset($cfg['blocks'][$i]);
						$notes[] = sprintf(__('Removed block: %s'), homeE($types[$b['type']]['label']));
					}
				}
				$cfg['blocks'] = array_values($cfg['blocks']);
			} else if ($verb === 'up' || $verb === 'down') {
				homeMoveBlock($cfg['blocks'], $target, $verb);
			}

			if (homeSave($cfg)) {
				$cfg = homeLoad();
				if (function_exists('manageLogAction')) {
					manageLogAction(__('Saved the home page settings'));
				}
				$notes[] = __('Settings saved.') . ' ' . manageHomeResultNote(rebuildHomePage(true), homeFileName($cfg));
			} else {
				$notes[] = __('The settings could not be saved. The install directory must be writable.');
			}
		}
	}

	$out = '';
	foreach ($notes as $n) {
		// notes are built from escaped pieces or fixed texts
		$out .= function_exists('manageInfo') ? manageInfo($n) : '<div>' . $n . '</div>';
	}
	return $out . manageHomeForm($cfg);
}
