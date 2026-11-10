<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// static pages, flags page, stats page, loaded by html.php

function pagesPath() {
	return dirname(__DIR__) . '/.pages.php';
}

function pagesParse($raw) {
	if (!is_string($raw) || $raw === '') {
		return array();
	}
	$nl = strpos($raw, "\n");
	$data = json_decode($nl === false ? '' : substr($raw, $nl + 1), true);
	return is_array($data) ? $data : array();
}

function pagesLoad($force = false) {
	static $cache = null;
	if ($cache !== null && !$force) {
		return $cache;
	}
	$cache = array();
	$path = pagesPath();
	if (is_file($path)) {
		$fh = @fopen($path, 'r');
		if ($fh) {
			flock($fh, LOCK_SH);
			$cache = pagesParse(stream_get_contents($fh));
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	} else if (is_file(dirname(__DIR__) . '/rules_content.html')) {
		// first run, bring over the old rules page
		$cache['rules'] = array(
			'title' => 'Rules', 'content' => (string)@file_get_contents(dirname(__DIR__) . '/rules_content.html'), 'css' => '',
			'header' => 1, 'banner' => 1, 'boardbar' => 1, 'created' => time(), 'updated' => time(), 'built' => 0,
		);
	}
	return $cache;
}

function pagesSave($pages) {
	$flags = JSON_UNESCAPED_UNICODE;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}
	$fh = @fopen(pagesPath(), 'c+');
	if (!$fh) {
		return false;
	}
	$ok = false;
	if (flock($fh, LOCK_EX)) {
		$ok = ftruncate($fh, 0) && rewind($fh) && (fwrite($fh, "<?php http_response_code(404); exit; ?>\n" . json_encode($pages, $flags)) !== false);
		fflush($fh);
		flock($fh, LOCK_UN);
	}
	fclose($fh);
	pagesLoad(true);
	return $ok;
}

// error string if $slug is no good as a page name, '' if it's fine
function pageSlugError($slug, $existing) {
	global $schan_reserved_slugs;
	if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $slug)) {
		return __('A page name may only use lowercase letters, numbers, - and _ (up to 40 characters).');
	}
	$reserved = is_array($schan_reserved_slugs) ? array_map('strtolower', $schan_reserved_slugs) : array();
	if ($slug === 'flags' || in_array($slug, $reserved, true) || (defined('SCHAN_OVERBOARD') && $slug === SCHAN_OVERBOARD)) {
		return sprintf(__('"%s" is reserved and cannot be used as a page name.'), $slug);
	}
	if (function_exists('boardBySlug') && boardBySlug($slug)) {
		return sprintf(__('A board is already called "%s".'), $slug);
	}
	if (!$existing && (is_file(dirname(__DIR__) . '/' . $slug . '.html') || is_dir(dirname(__DIR__) . '/' . $slug))) {
		return sprintf(__('A file or folder called "%s" already exists in the install directory.'), $slug);
	}
	return '';
}

function buildStaticPage($page) {
	$GLOBALS['schan_page_title'] = $page['title'];
	$GLOBALS['schan_page_opts'] = $page;
	if (empty($page['header'])) {
		$GLOBALS['schan_hide_header'] = true;
	}
	if (empty($page['banner'])) {
		$GLOBALS['schan_no_banner'] = true;
	}
	$html = buildPage($page['content'], -3, 0, 0, 0, '');
	unset($GLOBALS['schan_page_title'], $GLOBALS['schan_page_opts'], $GLOBALS['schan_hide_header'], $GLOBALS['schan_no_banner']);
	return $html;
}

// flags.html: every meme flag in a box, 1.3x bigger than in posts (name on hover)
// built from static/meme-flags/ and refreshed with the other pages
function flagsPageContent() {
	$list = function_exists('memeFlagsList') ? memeFlagsList() : array();
	$cells = '';
	foreach ($list as $name => $file) {
		$cells .= '<span class="flagcell">' . trim(memeFlagImageHTML((string)$name)) . '</span>';
	}
	if ($cells === '') {
		$cells = '<i>' . __('No flags yet.') . '</i>';
	}
	return '<style>.flagsbox{box-sizing:border-box;width:100%;max-width:700px;margin:10px auto;padding:12px 10px;border:1px solid;text-align:center;line-height:0;}'
		. '.flagsbox .flagcell{display:inline-block;margin:8px 10px;}.flagsbox .flagcell img{zoom:1.3;}.flagsbox i{line-height:normal;}</style>'
		. '<div class="flagsbox">' . $cells . '</div>';
}

function rebuildFlagsPage($force = false) {
	$path = 'flags.html';
	if (!$force && is_file($path) && filemtime($path) > time() - 300) {
		return;
	}
	writePage($path, buildStaticPage(array('title' => __('Flags'), 'content' => flagsPageContent(), 'css' => '', 'header' => 1, 'banner' => 1, 'boardbar' => 1)));
}

// stats.html: Posts / PPH / PPD / UC per board, plus rolling 3D / 1W / 1M counts
// Posts = highest post number. PPH / PPD = that averaged over time since the oldest post
// UC ("uncomfiness") = posts in the last hour. 3D / 1W / 1M count visible posts
// refreshed with the other pages (5 min at most, or right away with "Rebuild All")
function statsWindows() {
	return array(
		'3D' => array(259200,  __('Posts in the last 3 days')),
		'1W' => array(604800,  __('Posts in the last 7 days')),
		'1M' => array(2592000, __('Posts in the last 30 days')),
	);
}

// one pass over a board, returns its numbers
function statsCountBoard($slug, $now) {
	$windows = statsWindows();
	$rolling = array();
	foreach ($windows as $key => $w) {
		$rolling[$key] = 0;
	}

	$max_no = 0;
	$min_time = null;
	$uncomfiness = 0;

	$previous = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$GLOBALS['schan_forced_board'] = $slug;

	foreach (allThreads() as $thread) {
		foreach (postsInThreadByID($thread['id']) as $post) {
			if (isset($post['moderated']) && intval($post['moderated']) == 0) {
				continue;
			}
			$id = intval($post['id']);
			if ($id > $max_no) {
				$max_no = $id;
			}
			$t = intval($post['timestamp']);
			if ($min_time === null || $t < $min_time) {
				$min_time = $t;
			}
			$age = max(0, $now - $t);
			if ($age <= 3600) {
				$uncomfiness++;
			}
			foreach ($windows as $key => $w) {
				if ($age <= $w[0]) {
					$rolling[$key]++;
				}
			}
		}
	}

	$GLOBALS['schan_forced_board'] = $previous;

	$per_hour = 0;
	$per_day = 0;
	if ($min_time !== null && $min_time < $now) {
		$elapsed_hours = max(($now - $min_time) / 3600, 1 / 3600);
		$per_hour = round($max_no / $elapsed_hours, 2);
		$per_day = round($max_no / ($elapsed_hours / 24), 2);
	}

	return array(
		'count'       => $max_no,
		'perHour'     => $per_hour,
		'perDay'      => $per_day,
		'uncomfiness' => $uncomfiness,
		'rolling'     => $rolling,
	);
}

function statsPageContent() {
	$windows = statsWindows();
	$now = time();
	$root = installRootURL();

	$total = array('count' => 0, 'perHour' => 0, 'perDay' => 0, 'uncomfiness' => 0, 'rolling' => array());
	foreach ($windows as $key => $w) {
		$total['rolling'][$key] = 0;
	}

	$rows = array();
	$order = 0;
	$boards = function_exists('allBoards') ? allBoards() : array();
	foreach ($boards as $b) {
		$st = statsCountBoard($b['slug'], $now);
		$total['count'] += $st['count'];
		$total['perHour'] += $st['perHour'];
		$total['perDay'] += $st['perDay'];
		$total['uncomfiness'] += $st['uncomfiness'];
		foreach ($st['rolling'] as $key => $n) {
			$total['rolling'][$key] += $n;
		}
		$st['slug'] = $b['slug'];
		$st['order'] = $order++;
		$rows[] = $st;
	}

	// busiest first (last hour, then PPH), ties keep the normal order
	usort($rows, function ($a, $b) {
		if ($a['uncomfiness'] != $b['uncomfiness']) {
			return $b['uncomfiness'] - $a['uncomfiness'];
		}
		if ($a['perHour'] != $b['perHour']) {
			return ($b['perHour'] > $a['perHour']) ? 1 : -1;
		}
		return $a['order'] - $b['order'];
	});

	$head = '<th class="postblock">' . __('Board') . '</th>'
		. '<th class="postblock" title="' . htmlentities(__('Highest post number'), ENT_QUOTES) . '">' . __('Posts') . '</th>'
		. '<th class="postblock" title="' . htmlentities(__('Average posts per hour'), ENT_QUOTES) . '">PPH</th>'
		. '<th class="postblock" title="' . htmlentities(__('Average posts per day'), ENT_QUOTES) . '">PPD</th>'
		. '<th class="postblock" title="' . htmlentities(__('Uncomfiness (posts within the hour)'), ENT_QUOTES) . '">UC</th>';
	foreach ($windows as $key => $w) {
		$head .= '<th class="postblock" title="' . htmlentities($w[1], ENT_QUOTES) . '">' . $key . '</th>';
	}

	$cells = function ($st) {
		$out = '<td>' . intval($st['count']) . '</td>'
			. '<td>' . round($st['perHour'], 2) . '</td>'
			. '<td>' . round($st['perDay'], 2) . '</td>'
			. '<td>' . intval($st['uncomfiness']) . '</td>';
		foreach ($st['rolling'] as $n) {
			$out .= '<td>' . intval($n) . '</td>';
		}
		return $out;
	};

	$body = '<tr><td>' . __('Total') . '</td>' . $cells($total) . '</tr>';

	foreach ($rows as $row) {
		$url = $root . '/' . rawurlencode($row['slug']) . '/';
		$body .= '<tr><td><a href="' . htmlentities($url, ENT_QUOTES) . '">/' . htmlentities($row['slug']) . '/</a></td>' . $cells($row) . '</tr>';
	}

	if (empty($rows)) {
		$body .= '<tr><td colspan="' . (count($windows) + 5) . '"><i>' . __('No boards yet.') . '</i></td></tr>';
	}

	return '<style>'
		. '.statswrap{max-width:100%;overflow-x:auto;margin:10px auto;text-align:center;}'
		. '.statsbox{border-collapse:collapse;margin:0 auto;text-align:left;}'
		. '.statsbox th,.statsbox td{border:1px solid;padding:6px 16px;}'
		. '.statsnote{text-align:center;font-size:smaller;margin:6px 0;}'
		. '</style>'
		. '<div class="statswrap"><table class="statsbox"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
		. '<div class="statsnote">'
		. __('PPH / PPD: average posts per hour / day. UC: posts in the last hour. 3D / 1W / 1M: posts in the last 3 days / week / month.') . ' ' . sprintf(__('Updated %s'), formatDate($now))
		. '</div>';
}

function rebuildStatsPage($force = false) {
	$path = 'stats.html';
	if (!$force && is_file($path) && filemtime($path) > time() - 300) {
		return;
	}
	writePage($path, buildStaticPage(array('title' => __('Board Statistics'), 'content' => statsPageContent(), 'css' => '', 'header' => 1, 'banner' => 1, 'boardbar' => 1)));
}

// writes the page files, without $force only if older than 5 min
// (keeps the board bar current without rebuilding everything on every post)
function rebuildPages($force = false) {
	if (extensionEnabled('home') && function_exists('rebuildHomePage')) {
		rebuildHomePage($force);
	}
	if (extensionEnabled('flags') && function_exists('rebuildFlagsPage')) {
		rebuildFlagsPage($force);
	}
	if (extensionEnabled('stats') && function_exists('rebuildStatsPage')) {
		rebuildStatsPage($force);
	}
	$pages = pagesLoad();
	if (empty($pages)) {
		return;
	}
	$now = time();
	$changed = false;
	foreach ($pages as $slug => $page) {
		$slug = (string)$slug;
		if (!$force && !empty($page['built']) && $page['built'] > $now - 300 && is_file($slug . '.html')) {
			continue;
		}
		writePage($slug . '.html', buildStaticPage($page));
		$pages[$slug]['built'] = $now;
		$changed = true;
	}
	if ($changed) {
		pagesSave($pages);
	}
}
