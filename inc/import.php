<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * import tool (manage panel -> Import)
 *
 * pick a vichan or default Schan .sql dump (plain or .gz) and hit Import
 * it loads into temp tables (the live ones are never touched), detects the software, copies
 * boards / posts / bans / staff / logs / reports across, then drops the temp tables
 * super admins only, needs SCHAN_DBMODE = mysqli
 *
 * runs as a job in small steps and the progress page carries on by itself, so big dumps
 * don't hit the php / web server time limit. if the destination board is empty the original
 * post numbers are kept, otherwise posts are renumbered and >>links rewritten
 * images aren't in an sql file, copy the old src/ and thumb/ into <board>/src and <board>/thumb yourself
 */

// small helpers

function imp_h($s) {
	return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function imp_e($s) {
	global $link;
	return mysqli_real_escape_string($link, (string) $s);
}

function imp_cut($s, $n) {
	$s = (string) $s;
	return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

function imp_tbl($name) {
	return '`' . str_replace('`', '``', $name) . '`';
}

function imp_table_exists($src, $name) {
	$r = mysqli_query($src, "SHOW TABLES LIKE '" . addcslashes(mysqli_real_escape_string($src, $name), '_%') . "'");
	return $r && mysqli_num_rows($r) > 0;
}

function imp_columns($src, $table) {
	$cols = array();
	$r = mysqli_query($src, "SHOW COLUMNS FROM " . imp_tbl($table));
	if ($r) {
		while ($row = mysqli_fetch_assoc($r)) {
			$cols[] = $row['Field'];
		}
	}
	return $cols;
}

function imp_count($src, $sql) {
	$r = mysqli_query($src, $sql);
	if ($r && ($row = mysqli_fetch_row($r))) {
		return intval($row[0]);
	}
	return 0;
}

function imp_size($bytes) {
	$bytes = intval($bytes);
	if ($bytes >= 1048576) {
		return round($bytes / 1048576, 2) . ' MB';
	}
	if ($bytes >= 1024) {
		return round($bytes / 1024) . ' KB';
	}
	return $bytes . ' B';
}

function imp_random_password() {
	if (function_exists('random_bytes')) {
		return bin2hex(random_bytes(6));
	}
	if (function_exists('openssl_random_pseudo_bytes')) {
		return bin2hex(openssl_random_pseudo_bytes(6));
	}
	return substr(md5(uniqid(mt_rand(), true)), 0, 12);
}

function imp_hash_ip($ip, $hash) {
	$ip = (string) $ip;
	if ($ip === '') {
		return '';
	}
	return ($hash && function_exists('hashData')) ? hashData($ip) : $ip;
}

function imp_valid_slug($slug) {
	global $schan_reserved_slugs;
	if (!preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) {
		return 'invalid slug (letters, numbers, underscore, hyphen only)';
	}
	if (isset($schan_reserved_slugs) && is_array($schan_reserved_slugs) && in_array($slug, $schan_reserved_slugs)) {
		return 'slug is reserved';
	}
	if (file_exists($slug) && !is_dir($slug)) {
		return 'a file with that name exists in the install directory';
	}
	return '';
}

function imp_ensure_board($slug, $title, $desc, &$created) {
	$created = false;
	$b = boardBySlug($slug);
	if (empty($b)) {
		insertBoard(array('slug' => $slug, 'title' => imp_cut($title, 75), 'desc' => imp_cut($desc, 255), 'logo' => '', 'locked' => 0));
		$created = true;
	}
	foreach (array('', 'res/', 'src/', 'thumb/') as $sub) {
		$dir = $slug . '/' . $sub;
		if (!file_exists($dir)) {
			@mkdir($dir, 0755, true);
		}
	}
	return boardBySlug($slug);
}

// message conversion

// the ">>N" link in the stored format
function imp_cite_link($old, $map, $thread_of) {
	$old = intval($old);
	if (!isset($map[$old])) {
		return '&gt;&gt;' . $old;
	}
	$new = $map[$old];
	$thread = isset($thread_of[$old]) ? $thread_of[$old] : $new;
	$cls = ($thread == $new) ? 'refop' : 'refreply';
	return '<a href="res/' . $thread . '.html#' . $new . '" class="' . $cls . '">&gt;&gt;' . $new . '</a>';
}

// plain text -> stored message (escape, >>links, greentext, line breaks)
function imp_text_to_message($text, $map, $thread_of) {
	$text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
	$lines = explode("\n", $text);
	$out = array();
	foreach ($lines as $line) {
		$esc = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
		$esc = preg_replace_callback('/&gt;&gt;(\d+)/', function ($m) use ($map, $thread_of) {
			return imp_cite_link($m[1], $map, $thread_of);
		}, $esc);
		if (strpos($esc, '&gt;') === 0 && strpos($esc, '&gt;&gt;') !== 0) {
			$esc = '<span class="unkfunc">' . $esc . '</span>';
		}
		$out[] = $esc;
	}
	return implode('<br>', $out);
}

function imp_dom_children(DOMNode $node, $map, $thread_of) {
	$out = '';
	foreach ($node->childNodes as $c) {
		if ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) {
			$out .= htmlspecialchars($c->nodeValue, ENT_QUOTES, 'UTF-8');
			continue;
		}
		if ($c->nodeType !== XML_ELEMENT_NODE) {
			continue;
		}
		$tag = strtolower($c->nodeName);
		$class = ($c instanceof DOMElement) ? ' ' . $c->getAttribute('class') . ' ' : '';
		if ($tag === 'br') {
			$out .= '<br>';
			continue;
		}
		$inner = imp_dom_children($c, $map, $thread_of);
		switch ($tag) {
			case 'strong':
			case 'b':
				$out .= '<b>' . $inner . '</b>';
				break;
			case 'em':
			case 'i':
				$out .= '<i>' . $inner . '</i>';
				break;
			case 'u':
				$out .= '<u>' . $inner . '</u>';
				break;
			case 's':
			case 'del':
			case 'strike':
				$out .= '<s>' . $inner . '</s>';
				break;
			case 'code':
				$out .= '<code>' . $inner . '</code>';
				break;
			case 'pre':
				$out .= '<pre>' . $inner . '</pre>';
				break;
			case 'p':
			case 'div':
				$out .= $inner . '<br>';
				break;
			case 'span':
				if (strpos($class, ' quote ') !== false) {
					$out .= '<span class="unkfunc">' . $inner . '</span>';
				} else if (strpos($class, ' spoiler ') !== false) {
					$out .= '<span class="spoiler">' . $inner . '</span>';
				} else if (strpos($class, ' heading ') !== false) {
					$out .= '<b>' . $inner . '</b>';
				} else {
					$out .= $inner;
				}
				break;
			case 'a':
				$text = $c->textContent;
				$href = ($c instanceof DOMElement) ? $c->getAttribute('href') : '';
				if (preg_match('/^>>(\d+)$/', trim($text), $m)) {
					$out .= imp_cite_link($m[1], $map, $thread_of);
				} else if (preg_match('/^>>>/', trim($text))) {
					$out .= htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
				} else if (preg_match('#^(https?|ftp)://#i', $href)) {
					$out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank">' . $inner . '</a>';
				} else {
					$out .= $inner;
				}
				break;
			default:
				$out .= $inner;
		}
	}
	return $out;
}

// vichan html body -> stored message
function imp_vichan_message($row, $map, $thread_of) {
	$body = isset($row['body']) ? (string) $row['body'] : '';
	$raw = isset($row['body_nomarkup']) ? (string) $row['body_nomarkup'] : '';
	if ($body !== '' && class_exists('DOMDocument')) {
		$prev = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$ok = @$doc->loadHTML('<?xml encoding="utf-8" ?><div id="imp-root">' . $body . '</div>');
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if ($ok) {
			$root = $doc->getElementsByTagName('div')->item(0);
			if ($root) {
				$msg = imp_dom_children($root, $map, $thread_of);
				while (substr($msg, -4) === '<br>') {
					$msg = substr($msg, 0, -4);
				}
				return $msg;
			}
		}
	}
	if ($raw === '') {
		$raw = html_entity_decode(strip_tags(str_ireplace(array('<br>', '<br/>', '<br />'), "\n", $body)), ENT_QUOTES, 'UTF-8');
	}
	return imp_text_to_message($raw, $map, $thread_of);
}

// schan message, only the >>links need the new numbers
function imp_schan_message($message, $map, $thread_of) {
	return preg_replace_callback('/<a href="res\/\d+\.html#\d+"[^>]*>&gt;&gt;(\d+)<\/a>/', function ($m) use ($map, $thread_of) {
		return imp_cite_link($m[1], $map, $thread_of);
	}, (string) $message);
}

function imp_nameblock($name, $trip, $email, $time, $capcode) {
	if (function_exists('nameBlock')) {
		return nameBlock($name, $trip, $email, $time, $capcode, '');
	}
	$out = '<span class="postername">' . $name . '</span>';
	if ($trip !== '') {
		$out .= '<span class="postertrip">!' . $trip . '</span>';
	}
	return $out . $capcode;
}

// files

function imp_copy_file($from, $to, &$stats) {
	if (!is_file($from)) {
		$stats['missing']++;
		return;
	}
	if (file_exists($to)) {
		$stats['existing']++;
		return;
	}
	if (@copy($from, $to)) {
		$stats['copied']++;
	} else {
		$stats['failed']++;
	}
}

// file info of a vichan post (first file only)
function imp_vichan_file($row) {
	$f = array('file' => '', 'thumb' => '', 'w' => 0, 'h' => 0, 'tw' => 0, 'th' => 0, 'size' => 0, 'orig' => '', 'hex' => '', 'extra' => 0, 'embed' => 0);
	if (!empty($row['embed'])) {
		$f['embed'] = 1;
	}
	if (isset($row['files']) && $row['files'] !== null && $row['files'] !== '') {
		$j = json_decode($row['files'], true);
		if (is_array($j) && count($j) > 0) {
			$first = reset($j);
			$f['extra'] = count($j) - 1;
			$f['file'] = isset($first['file']) ? $first['file'] : '';
			$f['thumb'] = isset($first['thumb']) ? $first['thumb'] : '';
			$f['w'] = isset($first['width']) ? $first['width'] : 0;
			$f['h'] = isset($first['height']) ? $first['height'] : 0;
			$f['tw'] = isset($first['thumbwidth']) ? $first['thumbwidth'] : 0;
			$f['th'] = isset($first['thumbheight']) ? $first['thumbheight'] : 0;
			$f['size'] = isset($first['size']) ? $first['size'] : 0;
			$f['orig'] = isset($first['name']) ? $first['name'] : (isset($first['filename']) ? $first['filename'] : '');
			$f['hex'] = isset($first['hash']) ? $first['hash'] : '';
		}
	} else if (isset($row['file'])) {
		$f['file'] = $row['file'];
		$f['thumb'] = isset($row['thumb']) ? $row['thumb'] : '';
		$f['w'] = isset($row['filewidth']) ? $row['filewidth'] : 0;
		$f['h'] = isset($row['fileheight']) ? $row['fileheight'] : 0;
		$f['tw'] = isset($row['thumbwidth']) ? $row['thumbwidth'] : 0;
		$f['th'] = isset($row['thumbheight']) ? $row['thumbheight'] : 0;
		$f['size'] = isset($row['filesize']) ? $row['filesize'] : 0;
		$f['orig'] = isset($row['filename']) ? $row['filename'] : '';
		$f['hex'] = isset($row['filehash']) ? $row['filehash'] : '';
	}
	if ($f['file'] === null || $f['file'] === 'deleted' || $f['file'] === 'file' || $f['file'] === 'spoiler') {
		$f['file'] = '';
	}
	if ($f['thumb'] === null || in_array($f['thumb'], array('spoiler', 'file', 'deleted'))) {
		$f['thumb'] = '';
	}
	$f['file'] = basename((string) $f['file']);
	$f['thumb'] = basename((string) $f['thumb']);
	if ($f['file'] === '') {
		$f['thumb'] = '';
	}
	return $f;
}

// post import

function imp_insert_posts($rows, &$ok, &$fail, &$errors) {
	global $link;
	if (empty($rows)) {
		return;
	}
	$cols = array('id', 'parent', 'timestamp', 'bumped', 'ip', 'name', 'tripcode', 'email', 'nameblock', 'subject', 'message', 'password', 'file', 'file_hex', 'file_original', 'file_size', 'file_size_formatted', 'image_width', 'image_height', 'thumb', 'thumb_width', 'thumb_height', 'moderated', 'stickied', 'locked', 'board');
	$numeric = array('id', 'parent', 'timestamp', 'bumped', 'file_size', 'image_width', 'image_height', 'thumb_width', 'thumb_height', 'moderated', 'stickied', 'locked');
	$tuples = array();
	foreach ($rows as $r) {
		$vals = array();
		foreach ($cols as $c) {
			$v = isset($r[$c]) ? $r[$c] : (in_array($c, $numeric) ? 0 : '');
			if (in_array($c, $numeric)) {
				$v = max(0, intval($v));
				if ($c == 'image_width' || $c == 'image_height' || $c == 'thumb_width' || $c == 'thumb_height') {
					$v = min(65535, $v);
				}
				$vals[] = $v;
			} else {
				$vals[] = "'" . imp_e($v) . "'";
			}
		}
		$tuples[] = '(' . implode(', ', $vals) . ')';
	}
	$head = "INSERT INTO `" . SCHAN_DBPOSTS . "` (`" . implode('`, `', $cols) . "`) VALUES ";
	if (mysqli_query($link, $head . implode(', ', $tuples))) {
		$ok += count($rows);
		return;
	}
	// retry one by one so one bad row doesn't lose the batch
	foreach ($tuples as $i => $t) {
		if (mysqli_query($link, $head . $t)) {
			$ok++;
		} else {
			$fail++;
			if (count($errors) < 5) {
				$errors[] = 'Post ' . intval($rows[$i]['id']) . ' on ' . imp_h($rows[$i]['board']) . ': ' . imp_h(mysqli_error($link));
			}
		}
	}
}

// job runner

function imp_budget() {
	if (defined('SCHAN_IMPORT_BUDGET')) {
		return SCHAN_IMPORT_BUDGET;
	}
	$max = intval(ini_get('max_execution_time'));
	return ($max > 0) ? max(3, min(12, intval($max / 3))) : 12;
}

function imp_over($start, $budget) {
	return (microtime(true) - $start) >= $budget;
}

function imp_job() {
	return isset($_SESSION['schan_import_job']) ? $_SESSION['schan_import_job'] : null;
}

function imp_job_save($job) {
	$_SESSION['schan_import_job'] = $job;
}

function imp_job_drop_tables($job) {
	global $link;
	if (!empty($job['tables'])) {
		foreach ($job['tables'] as $t) {
			@mysqli_query($link, "DROP TABLE IF EXISTS `" . str_replace('`', '', $t) . "`");
		}
	}
}

function imp_lookup($slug, $ids) {
	global $link;
	$out = array();
	$ids = array_values(array_unique(array_map('intval', $ids)));
	foreach (array_chunk($ids, 500) as $chunk) {
		if (empty($chunk)) {
			continue;
		}
		$r = mysqli_query($link, "SELECT `old`, `new`, `tnew`, `op` FROM `" . $GLOBALS['imp_map_table'] . "` WHERE `board` = '" . imp_e($slug) . "' AND `old` IN (" . implode(',', $chunk) . ")");
		while ($r && ($row = mysqli_fetch_assoc($r))) {
			$out[intval($row['old'])] = $row;
		}
	}
	return $out;
}

// old -> new post number table for a board
function imp_build_map($job, &$plan) {
	global $link;
	$map = imp_tbl($job['tmp'] . 'map');
	$src = imp_tbl($plan['table']);
	$pc = ($job['type'] === 'vichan') ? 'thread' : 'parent';
	$slug = imp_e($plan['slug']);

	$b = boardBySlug($plan['slug']);
	$plan['keep'] = (countBoardPosts($plan['slug']) == 0 && (empty($b['lastpost']) || intval($b['lastpost']) == 0));
	$plan['total'] = imp_count($link, "SELECT COUNT(*) FROM " . $src);

	mysqli_query($link, "INSERT INTO " . $map . " (`board`, `old`, `new`, `told`, `op`) SELECT '" . $slug . "', p.`id`, p.`id`, IF(IFNULL(p.`" . $pc . "`, 0) = 0, p.`id`, p.`" . $pc . "`), IF(IFNULL(p.`" . $pc . "`, 0) = 0, 1, 0) FROM " . $src . " p LEFT JOIN " . $src . " o ON o.`id` = p.`" . $pc . "` AND IFNULL(o.`" . $pc . "`, 0) = 0 WHERE IFNULL(p.`" . $pc . "`, 0) = 0 OR o.`id` IS NOT NULL");
	$mapped = imp_count($link, "SELECT COUNT(*) FROM " . $map . " WHERE `board` = '" . $slug . "'");
	$plan['orphans'] = max(0, $plan['total'] - $mapped);

	if (!$plan['keep'] && $mapped > 0) {
		$first = nextPostID($plan['slug']);
		mysqli_query($link, "SET @imp_r := " . (intval($first) - 1));
		mysqli_query($link, "UPDATE " . $map . " SET `new` = (@imp_r := @imp_r + 1) WHERE `board` = '" . $slug . "' ORDER BY `old` ASC");
	}
	mysqli_query($link, "UPDATE " . $map . " m JOIN " . $map . " o ON o.`board` = m.`board` AND o.`old` = m.`told` SET m.`tnew` = o.`new` WHERE m.`board` = '" . $slug . "'");
}

// imports the current board's posts until time runs out, true when the board is done
function imp_step_posts(&$job, $start, $budget) {
	global $link;
	$plan =& $job['plans'][$job['pi']];
	$slug = $plan['slug'];
	$tbl = imp_tbl($plan['table']);
	$is_vichan = ($job['type'] === 'vichan');
	$opts = $job['opts'];
	$GLOBALS['imp_map_table'] = $job['tmp'] . 'map';

	$src_dir = '';
	$thumb_dir = '';
	if ($opts['files'] && $job['cfg']['files'] !== '') {
		$root = rtrim($job['cfg']['files'], '/\\');
		$src_dir = $is_vichan ? $root . '/' . $plan['src'] . '/src/' : $root . '/src/';
		$thumb_dir = $is_vichan ? $root . '/' . $plan['src'] . '/thumb/' : $root . '/thumb/';
	}
	$fstats = $plan['fstats'];
	$ok = $plan['ok'];
	$fail = $plan['fail'];
	$errors = $plan['errors'];
	$extra = $plan['extra'];
	$embeds = $plan['embeds'];
	$last = $plan['last'];
	$finished = false;

	while (true) {
		$q = mysqli_query($link, "SELECT * FROM " . $tbl . " WHERE `id` > " . intval($last) . " ORDER BY `id` ASC LIMIT 200");
		if (!$q || mysqli_num_rows($q) == 0) {
			$finished = true;
			break;
		}
		$rows = array();
		$ids = array();
		while ($row = mysqli_fetch_assoc($q)) {
			$rows[] = $row;
			$ids[] = intval($row['id']);
			$last = intval($row['id']);
			foreach (array('body', 'body_nomarkup', 'message') as $f) {
				if (!empty($row[$f]) && preg_match_all('/(?:&gt;|>)(?:&gt;|>)(\d+)/', $row[$f], $m)) {
					foreach ($m[1] as $c) {
						$ids[] = intval($c);
					}
				}
			}
		}
		mysqli_free_result($q);
		$found = imp_lookup($slug, $ids);
		$map = array();
		$thread_of = array();
		foreach ($found as $old => $r) {
			$map[$old] = intval($r['new']);
			$thread_of[$old] = intval($r['tnew']);
		}

		$batch = array();
		foreach ($rows as $row) {
			$old = intval($row['id']);
			if (!isset($found[$old])) {
				continue;
			}
			$new = $map[$old];
			$op = ($found[$old]['op'] == 1);
			$parent = $op ? 0 : $thread_of[$old];

			if ($is_vichan) {
				$ts = intval($row['time']);
				$bumped = ($op && isset($row['bump']) && $row['bump']) ? intval($row['bump']) : $ts;
				$name = imp_cut(html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8'), 75);
				$trip = imp_cut(ltrim((string) (isset($row['trip']) ? $row['trip'] : ''), '!'), 24);
				$email = imp_cut(isset($row['email']) ? $row['email'] : '', 75);
				$subject = imp_cut(html_entity_decode((string) (isset($row['subject']) ? $row['subject'] : ''), ENT_QUOTES, 'UTF-8'), 75);
				$cap = (isset($row['capcode']) && $row['capcode'] !== null && $row['capcode'] !== '') ? ' <span class="capcode">## ' . imp_h($row['capcode']) . '</span>' : '';
				$f = imp_vichan_file($row);
				$extra += $f['extra'];
				$embeds += $f['embed'];
				$post = array(
					'id' => $new,
					'parent' => $parent,
					'timestamp' => $ts,
					'bumped' => $bumped,
					'ip' => imp_hash_ip(isset($row['ip']) ? $row['ip'] : '', $opts['haship']),
					'name' => imp_h($name),
					'tripcode' => $trip,
					'email' => imp_h($email),
					'nameblock' => imp_nameblock(imp_h($name), $trip, imp_h($email), $ts, $cap),
					'subject' => imp_h($subject),
					'message' => imp_vichan_message($row, $map, $thread_of),
					'password' => '',
					'file' => $f['file'],
					'file_hex' => imp_cut($f['hex'], 75),
					'file_original' => imp_cut(imp_h($f['orig']), 255),
					'file_size' => $f['size'],
					'file_size_formatted' => $f['file'] !== '' ? imp_size($f['size']) : '',
					'image_width' => $f['w'],
					'image_height' => $f['h'],
					'thumb' => $f['thumb'],
					'thumb_width' => $f['tw'],
					'thumb_height' => $f['th'],
					'moderated' => 1,
					'stickied' => (!empty($row['sticky']) && $op) ? 1 : 0,
					'locked' => (!empty($row['locked']) && $op) ? 1 : 0,
					'board' => $slug,
				);
				$fname = $f['file'];
				$tname = $f['thumb'];
			} else {
				$post = array(
					'id' => $new,
					'parent' => $parent,
					'timestamp' => intval($row['timestamp']),
					'bumped' => isset($row['bumped']) ? intval($row['bumped']) : intval($row['timestamp']),
					'ip' => isset($row['ip']) ? $row['ip'] : '',
					'name' => isset($row['name']) ? $row['name'] : '',
					'tripcode' => isset($row['tripcode']) ? $row['tripcode'] : '',
					'email' => isset($row['email']) ? $row['email'] : '',
					'nameblock' => isset($row['nameblock']) ? $row['nameblock'] : '',
					'subject' => isset($row['subject']) ? $row['subject'] : '',
					'message' => imp_schan_message(isset($row['message']) ? $row['message'] : '', $map, $thread_of),
					'password' => isset($row['password']) ? $row['password'] : '',
					'file' => isset($row['file']) ? $row['file'] : '',
					'file_hex' => isset($row['file_hex']) ? $row['file_hex'] : '',
					'file_original' => isset($row['file_original']) ? $row['file_original'] : '',
					'file_size' => isset($row['file_size']) ? $row['file_size'] : 0,
					'file_size_formatted' => isset($row['file_size_formatted']) ? $row['file_size_formatted'] : '',
					'image_width' => isset($row['image_width']) ? $row['image_width'] : 0,
					'image_height' => isset($row['image_height']) ? $row['image_height'] : 0,
					'thumb' => isset($row['thumb']) ? $row['thumb'] : '',
					'thumb_width' => isset($row['thumb_width']) ? $row['thumb_width'] : 0,
					'thumb_height' => isset($row['thumb_height']) ? $row['thumb_height'] : 0,
					'moderated' => isset($row['moderated']) ? $row['moderated'] : 1,
					'stickied' => isset($row['stickied']) ? $row['stickied'] : 0,
					'locked' => isset($row['locked']) ? $row['locked'] : 0,
					'board' => $slug,
				);
				$fname = basename((string) $post['file']);
				$tname = basename((string) $post['thumb']);
			}

			if ($src_dir !== '' && $fname !== '') {
				imp_copy_file($src_dir . $fname, $slug . '/src/' . $fname, $fstats);
				if ($tname !== '') {
					imp_copy_file($thumb_dir . $tname, $slug . '/thumb/' . $tname, $fstats);
				}
			}
			$batch[] = $post;
		}
		imp_insert_posts($batch, $ok, $fail, $errors);
		if (imp_over($start, $budget)) {
			break;
		}
	}

	$plan['fstats'] = $fstats;
	$plan['ok'] = $ok;
	$plan['fail'] = $fail;
	$plan['errors'] = $errors;
	$plan['extra'] = $extra;
	$plan['embeds'] = $embeds;
	$plan['last'] = $last;

	if (!$finished) {
		return false;
	}

	syncBoardCounter($slug);
	$rep =& $job['rep'];
	$line = 'Board <b>' . imp_h($slug) . '</b>: ' . $ok . ' posts imported' . ($plan['keep'] ? ' (post numbers kept)' : ' (renumbered)');
	if ($fail) {
		$line .= ', ' . $fail . ' failed';
	}
	$rep['lines'][] = $line . '.';
	if ($plan['orphans']) {
		$rep['warnings'][] = imp_h($slug) . ': ' . $plan['orphans'] . ' replies skipped because their thread no longer exists.';
	}
	if ($extra) {
		$rep['warnings'][] = imp_h($slug) . ': ' . $extra . ' additional files on multi-file posts were not imported (only the first file of each post is).';
	}
	if ($embeds) {
		$rep['warnings'][] = imp_h($slug) . ': ' . $embeds . ' posts had an embed, which is not imported.';
	}
	if ($src_dir !== '') {
		$rep['lines'][] = '&nbsp;&nbsp;Files for ' . imp_h($slug) . ': ' . $fstats['copied'] . ' copied, ' . $fstats['existing'] . ' already present, ' . $fstats['missing'] . ' not found in the old install folder' . ($fstats['failed'] ? ', ' . $fstats['failed'] . ' failed to copy' : '') . '.';
	}
	foreach ($errors as $e) {
		$rep['errors'][] = $e;
	}
	return true;
}

function imp_ban_exists($ip, $board) {
	global $link;
	$r = mysqli_query($link, "SELECT `id` FROM `" . SCHAN_DBBANS . "` WHERE `ip` = '" . imp_e($ip) . "' AND `board` = '" . imp_e($board) . "' LIMIT 1");
	return $r && mysqli_num_rows($r) > 0;
}

// accounts, bans, keywords, reports (small tables, one step)
function imp_step_misc(&$job) {
	global $link;
	$src = $link;
	$type = $job['type'];
	$prefix = $job['cfg']['prefix'];
	$opts = $job['opts'];
	$target = $job['target'];
	$slug_of = $job['slug_of'];
	$rep =& $job['rep'];
	$now = time();
	$GLOBALS['imp_map_table'] = $job['tmp'] . 'map';

	// staff accounts
	if ($opts['accounts']) {
		$added = 0;
		$skipped = 0;
		if ($type === 'vichan') {
			if (imp_table_exists($src, $prefix . 'mods')) {
				$res = mysqli_query($src, "SELECT * FROM " . imp_tbl($prefix . 'mods') . " ORDER BY `id` ASC");
				while ($res && ($m = mysqli_fetch_assoc($res))) {
					$type_n = intval($m['type']);
					if ($type_n >= 30) {
						$role = $opts['superadmin'] ? SCHAN_SUPER_ADMINISTRATOR : SCHAN_ADMINISTRATOR;
					} else if ($type_n >= 20) {
						$role = SCHAN_MODERATOR;
					} else if ($type_n >= 10 && defined('SCHAN_JANITOR')) {
						$role = SCHAN_JANITOR;
					} else {
						$role = SCHAN_DISABLED;
					}
					$boards_raw = trim((string) $m['boards']);
					$targets = array();
					if ($boards_raw === '*' || $boards_raw === '') {
						$targets[] = '*';
					} else {
						foreach (explode(',', $boards_raw) as $uri) {
							$uri = trim($uri);
							if (isset($slug_of[$uri])) {
								$targets[] = $slug_of[$uri];
							}
						}
					}
					if (empty($targets)) {
						$skipped++;
						$rep['warnings'][] = 'Account ' . imp_h($m['username']) . ' skipped: none of its boards were imported.';
						continue;
					}
					$first = true;
					foreach ($targets as $t) {
						$uname = imp_cut($m['username'], 200);
						if (count($targets) > 1) {
							$uname .= '-' . $t;
						}
						if (accountByUsername($uname)) {
							$skipped++;
							$rep['warnings'][] = 'Account ' . imp_h($uname) . ' skipped: that username already exists.';
							continue;
						}
						$pw = imp_random_password();
						$id = insertAccount(array('username' => $uname, 'password' => $pw, 'role' => $role, 'board' => $t));
						if ($id) {
							$added++;
							if ($first) {
								$job['acct_map'][intval($m['id'])] = intval($id);
								$first = false;
							}
							$rep['passwords'][] = array($uname, $pw, $t);
						}
					}
				}
			} else {
				$rep['warnings'][] = 'No mods table found in the vichan database, accounts not imported.';
			}
		} else {
			if (imp_table_exists($src, $prefix . 'accounts')) {
				$res = mysqli_query($src, "SELECT * FROM " . imp_tbl($prefix . 'accounts') . " ORDER BY `id` ASC");
				while ($res && ($a = mysqli_fetch_assoc($res))) {
					$existing = accountByUsername($a['username']);
					if ($existing) {
						$job['acct_map'][intval($a['id'])] = intval($existing['id']);
						$skipped++;
						$rep['warnings'][] = 'Account ' . imp_h($a['username']) . ' skipped: that username already exists.';
						continue;
					}
					mysqli_query($link, "INSERT INTO `" . SCHAN_DBACCOUNTS . "` (`username`, `password`, `role`, `lastactive`, `board`) VALUES ('" . imp_e($a['username']) . "', '" . imp_e($a['password']) . "', " . intval($a['role']) . ", " . intval($a['lastactive']) . ", '*')");
					$id = mysqli_insert_id($link);
					if ($id) {
						$job['acct_map'][intval($a['id'])] = intval($id);
						$added++;
					}
				}
				$rep['warnings'][] = 'Accounts were copied with their original password hashes. They only work if SCHAN_TRIPSEED here matches the old install; otherwise reset the passwords from the Accounts page.';
			} else {
				$rep['warnings'][] = 'No accounts table found, accounts not imported.';
			}
		}
		$rep['lines'][] = 'Accounts: ' . $added . ' imported, ' . $skipped . ' skipped.';
		if ($type === 'vichan' && $added) {
			$rep['warnings'][] = 'vichan passwords cannot be converted, so the imported accounts got random temporary passwords (listed below, shown once).';
		}
	}

	// bans
	if ($opts['bans']) {
		$added = 0;
		$skipped = 0;
		$ranges = 0;
		$bt = $prefix . 'bans';
		if (imp_table_exists($src, $bt)) {
			$res = mysqli_query($src, "SELECT * FROM " . imp_tbl($bt) . " ORDER BY `id` ASC");
			while ($res && ($b = mysqli_fetch_assoc($res))) {
				if ($type === 'vichan') {
					$expire = (isset($b['expires']) && $b['expires']) ? intval($b['expires']) : 0;
					if ($expire > 0 && $expire <= $now) {
						$skipped++;
						continue;
					}
					if (!isset($b['ipstart'])) {
						$skipped++;
						continue;
					}
					$ipstart = @inet_ntop($b['ipstart']);
					$ipend = (isset($b['ipend']) && $b['ipend'] !== null && $b['ipend'] !== '') ? @inet_ntop($b['ipend']) : $ipstart;
					if ($ipstart === false || $ipstart === null) {
						$skipped++;
						continue;
					}
					if ($ipend !== $ipstart) {
						$ranges++;
						continue;
					}
					$board = ($b['board'] === null || $b['board'] === '') ? '*' : (isset($slug_of[$b['board']]) ? $slug_of[$b['board']] : null);
					if ($board === null) {
						$skipped++;
						continue;
					}
					$ip = imp_hash_ip($ipstart, $opts['haship']);
					$reason = imp_cut(strip_tags((string) $b['reason']), 1000);
					$created = intval($b['created']);
				} else {
					$expire = intval($b['expire']);
					if ($expire > 0 && $expire <= $now) {
						$skipped++;
						continue;
					}
					$board = $opts['scope_all'] ? '*' : $target;
					$ip = $b['ip'];
					$reason = $b['reason'];
					$created = intval($b['timestamp']);
				}
				if (imp_ban_exists($ip, $board)) {
					$skipped++;
					continue;
				}
				mysqli_query($link, "INSERT INTO `" . SCHAN_DBBANS . "` (`ip`, `timestamp`, `expire`, `reason`, `board`) VALUES ('" . imp_e($ip) . "', " . $created . ", " . $expire . ", '" . imp_e($reason) . "', '" . imp_e($board) . "')");
				$added++;
			}
		} else {
			$rep['warnings'][] = 'No bans table found in the dump.';
		}
		$rep['lines'][] = 'Bans: ' . $added . ' imported, ' . $skipped . ' skipped (expired, duplicate or for a board that was not imported).';
		if ($ranges) {
			$rep['warnings'][] = $ranges . ' IP range bans were not imported: bans here match single addresses only.';
		}
	}

	// keywords (schan only, vichan keeps wordfilters in its config)
	if ($opts['keywords'] && $type === 'schan') {
		$added = 0;
		if (imp_table_exists($src, $prefix . 'keywords')) {
			$res = mysqli_query($src, "SELECT * FROM " . imp_tbl($prefix . 'keywords'));
			while ($res && ($k = mysqli_fetch_assoc($res))) {
				$board = $opts['scope_all'] ? '*' : $target;
				$text = strtolower($k['text']);
				$dup = mysqli_query($link, "SELECT `id` FROM `" . SCHAN_DBKEYWORDS . "` WHERE `text` = '" . imp_e($text) . "' AND `board` = '" . imp_e($board) . "' LIMIT 1");
				if ($dup && mysqli_num_rows($dup) > 0) {
					continue;
				}
				mysqli_query($link, "INSERT INTO `" . SCHAN_DBKEYWORDS . "` (`text`, `action`, `board`) VALUES ('" . imp_e($text) . "', '" . imp_e($k['action']) . "', '" . imp_e($board) . "')");
				$added++;
			}
		}
		$rep['lines'][] = 'Keywords: ' . $added . ' imported.';
	}

	// reports
	if ($opts['reports']) {
		$added = 0;
		$skipped = 0;
		$rt = $prefix . 'reports';
		if (imp_table_exists($src, $rt)) {
			$res = mysqli_query($src, "SELECT * FROM " . imp_tbl($rt) . " ORDER BY `id` ASC");
			while ($res && ($rp = mysqli_fetch_assoc($res))) {
				$board = ($type === 'vichan') ? (isset($slug_of[$rp['board']]) ? $slug_of[$rp['board']] : null) : $target;
				$old = intval($rp['post']);
				$found = ($board === null) ? array() : imp_lookup($board, array($old));
				if (!isset($found[$old])) {
					$skipped++;
					continue;
				}
				$ip = ($type === 'vichan') ? imp_hash_ip(isset($rp['ip']) ? $rp['ip'] : '', $opts['haship']) : $rp['ip'];
				mysqli_query($link, "INSERT INTO `" . SCHAN_DBREPORTS . "` (`ip`, `post`, `board`) VALUES ('" . imp_e($ip) . "', " . intval($found[$old]['new']) . ", '" . imp_e($board) . "')");
				$added++;
			}
		}
		$rep['lines'][] = 'Reports: ' . $added . ' imported, ' . $skipped . ' skipped (post not imported).';
	}
}

// mod log, 500 rows per query, true when done
function imp_step_logs(&$job, $start, $budget) {
	global $link;
	if (!$job['opts']['logs']) {
		return true;
	}
	$type = $job['type'];
	$prefix = $job['cfg']['prefix'];
	$lt = $prefix . ($type === 'vichan' ? 'modlogs' : 'logs');
	if (!imp_table_exists($link, $lt)) {
		return true;
	}
	$order = ($type === 'vichan') ? '`time` ASC' : '`id` ASC';
	$slug_of = $job['slug_of'];
	while (true) {
		$res = mysqli_query($link, "SELECT * FROM " . imp_tbl($lt) . " ORDER BY " . $order . " LIMIT " . intval($job['log_off']) . ", 500");
		if (!$res || mysqli_num_rows($res) == 0) {
			$job['rep']['lines'][] = 'Moderation log entries: ' . $job['log_added'] . ' imported.';
			return true;
		}
		$tuples = array();
		while ($l = mysqli_fetch_assoc($res)) {
			$job['log_off']++;
			if ($type === 'vichan') {
				$board = ($l['board'] === null || $l['board'] === '') ? '*' : (isset($slug_of[$l['board']]) ? $slug_of[$l['board']] : null);
				if ($board === null) {
					continue;
				}
				$acct = isset($job['acct_map'][intval($l['mod'])]) ? $job['acct_map'][intval($l['mod'])] : 0;
				$msg = imp_cut(htmlspecialchars(strip_tags((string) $l['text']), ENT_QUOTES, 'UTF-8'), 255);
				$ts = intval($l['time']);
			} else {
				$board = $job['opts']['scope_all'] ? '*' : $job['target'];
				$acct = isset($job['acct_map'][intval($l['account'])]) ? $job['acct_map'][intval($l['account'])] : 0;
				$msg = imp_cut($l['message'], 255);
				$ts = intval($l['timestamp']);
			}
			$tuples[] = "(" . $ts . ", " . intval($acct) . ", '" . imp_e($msg) . "', '" . imp_e($board) . "')";
		}
		if (!empty($tuples)) {
			if (mysqli_query($link, "INSERT INTO `" . SCHAN_DBLOGS . "` (`timestamp`, `account`, `message`, `board`) VALUES " . implode(', ', $tuples))) {
				$job['log_added'] += count($tuples);
			}
		}
		if (imp_over($start, $budget)) {
			return false;
		}
	}
}

// rebuilds static pages in small batches, true when done
function imp_step_rebuild(&$job, $start, $budget) {
	global $link;
	$slugs = $job['slugs'];
	while ($job['rb']['pi'] < count($slugs)) {
		$slug = $slugs[$job['rb']['pi']];
		if ($job['rb']['phase'] === 'threads') {
			$q = mysqli_query($link, "SELECT `id` FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . imp_e($slug) . "' AND `parent` = 0 AND `moderated` > 0 AND `id` > " . intval($job['rb']['last']) . " ORDER BY `id` ASC LIMIT 25");
			if (!$q || mysqli_num_rows($q) == 0) {
				$job['rb']['phase'] = 'index';
				continue;
			}
			$ids = array();
			while ($r = mysqli_fetch_row($q)) {
				$ids[] = intval($r[0]);
			}
			foreach ($ids as $id) {
				rebuildThread($id, $slug);
				$job['rb']['last'] = $id;
				$job['rb']['done']++;
			}
			$GLOBALS['schan_forced_board'] = null;
		} else {
			rebuildIndexes($slug);
			$GLOBALS['schan_forced_board'] = null;
			$job['rb']['pi']++;
			$job['rb']['phase'] = 'threads';
			$job['rb']['last'] = 0;
			$job['rb']['done'] = 0;
		}
		if (imp_over($start, $budget)) {
			return false;
		}
	}
	if (function_exists('rebuildPages')) {
		rebuildPages(true);
	}
	$job['rep']['lines'][] = 'Rebuilt ' . count($slugs) . ' board(s).';
	return true;
}

function imp_job_status($job) {
	switch ($job['stage']) {
		case 'posts':
			$p = isset($job['plans'][$job['pi']]) ? $job['plans'][$job['pi']] : null;
			return $p ? 'Importing posts of /' . imp_h($p['slug']) . '/ (' . intval($p['ok']) . ' of ' . intval($p['total']) . ')' : 'Importing posts';
		case 'misc':
			return 'Importing accounts, bans and reports';
		case 'logs':
			return 'Importing the moderation log (' . intval($job['log_off']) . ' rows read)';
		case 'rebuild':
			$slug = isset($job['slugs'][$job['rb']['pi']]) ? $job['slugs'][$job['rb']['pi']] : '';
			return 'Rebuilding pages of /' . imp_h($slug) . '/ (' . intval($job['rb']['done']) . ' threads done)';
	}
	return 'Finishing';
}

function imp_form_progress($job) {
	$url = '?manage&import&continue=1&token=' . rawurlencode(imp_token());
	return '<fieldset><legend>Importing&hellip;</legend>'
		. '<p><b>' . imp_job_status($job) . '</b></p>'
		. '<p>This page continues by itself. Keep it open. If it stops, <a href="' . imp_h($url) . '">click here to continue</a>.</p>'
		. '<p><a href="?manage&amp;import&amp;cancel=1&amp;token=' . imp_h(rawurlencode(imp_token())) . '" onclick="return confirm(\'Stop the import? Posts already imported stay.\');">Stop and clean up</a></p>'
		. '</fieldset><script>setTimeout(function(){location.href=' . json_encode($url) . ';},400);</script><br>';
}

function imp_job_finish(&$job) {
	imp_job_drop_tables($job);
	clearstatcache();
	if (function_exists('clearPostCache')) {
		clearPostCache();
	}
	if (function_exists('manageLogAction')) {
		manageLogAction('Imported a ' . ($job['type'] === 'vichan' ? 'vichan' : 'TinyIB') . ' database (' . implode(', ', $job['slugs']) . ')');
	}
	$rep = $job['rep'];
	if (!$job['opts']['rebuild'] && $job['opts']['posts']) {
		$rep['warnings'][] = 'Pages were not rebuilt. Click Rebuild All before the imported threads show up.';
	}
	if ($job['cfg']['files'] === '') {
		$rep['warnings'][] = 'Images are not stored in an SQL file. Copy the old install\'s src/ and thumb/ folders into each board\'s own src/ and thumb/ folders, or import again with the old install folder filled in.';
	}
	unset($_SESSION['schan_import_job']);
	$_SESSION['schan_import_report'] = $rep;
	return imp_form_report($rep);
}

// runs steps until time's up, then returns the page to show
function imp_job_run($job) {
	@set_time_limit(0);
	@ignore_user_abort(true);
	$mem = ini_get('memory_limit');
	if ($mem !== '-1' && intval($mem) > 0 && intval($mem) < 512 && substr(strtoupper(trim($mem)), -1) === 'M') {
		@ini_set('memory_limit', '512M');
	}
	$start = microtime(true);
	$budget = imp_budget();

	while (true) {
		if ($job['stage'] === 'posts') {
			if ($job['pi'] >= count($job['plans'])) {
				$job['stage'] = 'misc';
			} else if (imp_step_posts($job, $start, $budget)) {
				$job['pi']++;
			}
		} else if ($job['stage'] === 'misc') {
			imp_step_misc($job);
			$job['stage'] = 'logs';
		} else if ($job['stage'] === 'logs') {
			if (imp_step_logs($job, $start, $budget)) {
				$job['stage'] = ($job['opts']['rebuild'] && $job['opts']['posts'] && !empty($job['slugs'])) ? 'rebuild' : 'finish';
			}
		} else if ($job['stage'] === 'rebuild') {
			if (imp_step_rebuild($job, $start, $budget)) {
				$job['stage'] = 'finish';
			}
		} else {
			return imp_job_finish($job);
		}
		imp_job_save($job);
		if (imp_over($start, $budget) && $job['stage'] !== 'finish') {
			return imp_form_progress($job);
		}
	}
}

// sQL dump loading

// streams a dump's statements (plain or gzip) to $callback
function imp_sql_each($path, $callback) {
	$gz = function_exists('gzopen');
	$fh = $gz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
	if (!$fh) {
		return false;
	}
	$buf = '';
	$q = '';
	$blk = false;
	while (($line = ($gz ? gzgets($fh, 8388608) : fgets($fh, 8388608))) !== false) {
		$n = strlen($line);
		$i = 0;
		$seg = 0;
		while ($i < $n) {
			if ($blk) {
				$e = strpos($line, '*/', $i);
				if ($e === false) {
					$i = $n;
					$seg = $n;
					break;
				}
				$i = $e + 2;
				$seg = $i;
				$blk = false;
				continue;
			}
			if ($q !== '') {
				$i += strcspn($line, ($q === '`') ? '`' : $q . '\\', $i);
				if ($i >= $n) {
					break;
				}
				if ($line[$i] === '\\') {
					$i += 2;
					continue;
				}
				if ($i + 1 < $n && $line[$i + 1] === $q) {
					$i += 2;
					continue;
				}
				$q = '';
				$i++;
				continue;
			}
			$i += strcspn($line, ";'\"`#-/", $i);
			if ($i >= $n) {
				break;
			}
			$c = $line[$i];
			if ($c === ';') {
				$buf .= substr($line, $seg, $i - $seg);
				$stmt = trim($buf);
				$buf = '';
				if ($stmt !== '') {
					call_user_func($callback, $stmt);
				}
				$i++;
				$seg = $i;
			} else if ($c === "'" || $c === '"' || $c === '`') {
				$q = $c;
				$i++;
			} else if ($c === '#' || ($c === '-' && substr($line, $i, 2) === '--')) {
				$buf .= substr($line, $seg, $i - $seg);
				$seg = $n;
				$i = $n;
			} else if ($c === '/' && $i + 1 < $n && $line[$i + 1] === '*') {
				$buf .= substr($line, $seg, $i - $seg);
				$e = strpos($line, '*/', $i + 2);
				if ($e === false) {
					$blk = true;
					$seg = $n;
					$i = $n;
				} else {
					$i = $e + 2;
					$seg = $i;
				}
			} else {
				$i++;
			}
		}
		$buf .= (string) substr($line, $seg);
	}
	$gz ? gzclose($fh) : fclose($fh);
	$stmt = trim($buf);
	if ($stmt !== '') {
		call_user_func($callback, $stmt);
	}
	return true;
}

// rewrites a statement to run on the temp copy of its table
// only CREATE TABLE / INSERT / REPLACE / ALTER TABLE are kept, anything else (DROP, USE, LOCK, SET...)
// is ignored so the live tables can't be touched
function imp_sql_rewrite($stmt, $tmp, &$tables) {
	$name_re = '(?:`[^`]+`\.)?(?:`([^`]+)`|([A-Za-z0-9_$]+))';
	$pats = array(
		'create' => '/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)' . $name_re . '/i',
		'insert' => '/^((?:INSERT|REPLACE)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?INTO\s+)' . $name_re . '/i',
		'alter' => '/^(ALTER\s+TABLE\s+)' . $name_re . '/i',
	);
	foreach ($pats as $kind => $re) {
		if (!preg_match($re, $stmt, $m)) {
			continue;
		}
		$name = (isset($m[2]) && $m[2] !== '') ? $m[2] : (isset($m[3]) ? $m[3] : '');
		if ($name === '') {
			return '';
		}
		if ($kind === 'create') {
			if (stripos($stmt, 'CREATE TABLE') !== 0 || preg_match('/\sLIKE\s/i', substr($stmt, 0, strlen($m[0]) + 12))) {
				return '';
			}
			$tables[$name] = $tmp . $name;
			$GLOBALS['imp_tmp_tables'][] = $tmp . $name;
		} else if (!isset($tables[$name])) {
			return '';
		}
		return $m[1] . '`' . $tmp . $name . '`' . substr($stmt, strlen($m[0]));
	}
	return '';
}

function imp_cleanup() {
	global $link;
	if (!empty($GLOBALS['imp_tmp_tables']) && $link) {
		foreach ($GLOBALS['imp_tmp_tables'] as $t) {
			@mysqli_query($link, "DROP TABLE IF EXISTS `" . str_replace('`', '', $t) . "`");
		}
		$GLOBALS['imp_tmp_tables'] = array();
	}
}

// loads the dump into temp tables, returns stats, $tables maps original -> temp name
function imp_load_dump($path, $tmp, &$tables) {
	global $link;
	$tables = array();
	$stats = array('ok' => 0, 'failed' => 0, 'errors' => array(), 'readable' => true);
	$res = mysqli_query($link, "SELECT @@SESSION.sql_mode");
	$row = $res ? mysqli_fetch_row($res) : array('');
	$old_mode = $row[0];
	mysqli_query($link, "SET SESSION sql_mode = ''");
	$stats['readable'] = imp_sql_each($path, function ($stmt) use ($tmp, &$tables, &$stats, $link) {
		$sql = imp_sql_rewrite($stmt, $tmp, $tables);
		if ($sql === '') {
			return;
		}
		if (mysqli_query($link, $sql)) {
			$stats['ok']++;
		} else {
			$stats['failed']++;
			if (count($stats['errors']) < 5) {
				$stats['errors'][] = imp_h(imp_cut(mysqli_error($link), 200));
			}
		}
	});
	mysqli_query($link, "SET SESSION sql_mode = '" . imp_e($old_mode) . "'");
	return $stats;
}

// inspecting the loaded dump

function imp_inspect($src, $type, $cfg, &$error) {
	$prefix = $cfg['prefix'];
	if ($type === 'vichan') {
		$boards = array();
		if (imp_table_exists($src, $prefix . 'boards')) {
			$res = mysqli_query($src, "SELECT * FROM " . imp_tbl($prefix . 'boards') . " ORDER BY `uri` ASC");
			while ($res && ($b = mysqli_fetch_assoc($res))) {
				$boards[$b['uri']] = array('uri' => $b['uri'], 'title' => isset($b['title']) ? $b['title'] : '', 'desc' => isset($b['subtitle']) ? $b['subtitle'] : '');
			}
		}
		$res = mysqli_query($src, "SHOW TABLES LIKE '" . addcslashes(mysqli_real_escape_string($src, $prefix . 'posts_'), '_%') . "%'");
		while ($res && ($t = mysqli_fetch_row($res))) {
			$uri = substr($t[0], strlen($prefix . 'posts_'));
			if ($uri !== '' && !isset($boards[$uri])) {
				$boards[$uri] = array('uri' => $uri, 'title' => '', 'desc' => '');
			}
		}
		$out = array();
		foreach ($boards as $uri => $b) {
			if (!preg_match('/^[A-Za-z0-9_-]+$/', $uri) || !imp_table_exists($src, $prefix . 'posts_' . $uri)) {
				continue;
			}
			$b['posts'] = imp_count($src, "SELECT COUNT(*) FROM " . imp_tbl($prefix . 'posts_' . $uri));
			$out[] = $b;
		}
		if (empty($out)) {
			$error = 'No vichan boards were found in that file.';
			return false;
		}
		return array('type' => 'vichan', 'boards' => $out);
	}
	return array('type' => 'schan');
}

// the page

function imp_token() {
	if (empty($_SESSION['schan_import_token'])) {
		$_SESSION['schan_import_token'] = imp_random_password() . imp_random_password();
	}
	return $_SESSION['schan_import_token'];
}

function imp_form_upload($error = '') {
	$text = '';
	if ($error !== '') {
		$text .= manageInfo($error);
	}
	return $text . '<form id="schan" name="schan" method="post" action="?manage&amp;import" enctype="multipart/form-data">'
		. '<input type="hidden" name="import_step" value="upload">'
		. '<input type="hidden" name="token" value="' . imp_h(imp_token()) . '">'
		. '<fieldset><legend>Import a vichan or TinyIB database</legend>'
		. '<p>Select the <b>.sql</b> dump (a .sql.gz also works). Boards, threads, replies, bans, staff accounts, logs and reports are imported; '
		. 'the type is detected automatically. A TinyIB dump goes into the default board (' . imp_h(SCHAN_DEFAULTBOARD) . '), a vichan dump creates a board for each of its boards.</p>'
		. '<table border="0">'
		. '<tr><td>Browse for the file</td><td><input type="file" name="sqlfile" accept=".sql,.gz,.sql.gz,application/sql,text/plain,application/gzip"></td></tr>'
		. '<tr><td><label for="imp_sqlpath">&hellip; or path to it on the server</label></td><td><input type="text" name="sqlpath" id="imp_sqlpath" value="" size="50" placeholder="/home/user/backup.sql"> <small>used when no file is chosen; no upload size limit</small></td></tr>'
		. '<tr><td><label for="imp_filespath">Old install folder (optional)</label></td><td><input type="text" name="filespath" id="imp_filespath" value="" size="50" placeholder="/var/www/vichan"> <small>images and thumbnails are copied from its board folders (or src/ and thumb/ for TinyIB)</small></td></tr>'
		. '<tr><td>&nbsp;</td><td><input type="submit" value="Import" class="managebutton" onclick="return confirm(\'Start the import now? Keep this page open until it finishes.\');"></td></tr>'
		. '</table>'
		. '<p><small>Maximum upload size: ' . imp_h(ini_get('upload_max_filesize')) . ' (post limit ' . imp_h(ini_get('post_max_size')) . '). Use the server path for bigger dumps.</small></p>'
		. '</fieldset></form><br>';
}

function imp_form_report($rep) {
	$text = '<fieldset><legend>Import finished</legend>';
	if (!empty($rep['errors'])) {
		$text .= '<b>Errors</b><ul>';
		foreach ($rep['errors'] as $e) {
			$text .= '<li>' . $e . '</li>';
		}
		$text .= '</ul>';
	}
	$text .= '<ul>';
	foreach ($rep['lines'] as $l) {
		$text .= '<li>' . $l . '</li>';
	}
	$text .= '</ul>';
	if (!empty($rep['warnings'])) {
		$text .= '<b>Notes</b><ul>';
		foreach (array_unique($rep['warnings']) as $w) {
			$text .= '<li>' . $w . '</li>';
		}
		$text .= '</ul>';
	}
	if (!empty($rep['passwords'])) {
		$text .= '<b>Temporary passwords (shown once, copy them now)</b>'
			. '<table border="1" cellspacing="0" cellpadding="4"><tr><th>Username</th><th>Password</th><th>Board</th></tr>';
		foreach ($rep['passwords'] as $p) {
			$text .= '<tr><td>' . imp_h($p[0]) . '</td><td><code>' . imp_h($p[1]) . '</code></td><td>' . ($p[2] === '*' ? 'All' : imp_h($p[2])) . '</td></tr>';
		}
		$text .= '</table>';
	}
	$text .= '<p><a href="?manage&amp;import">Import another file</a></p></fieldset><br>';
	return $text;
}

function handleImportManage() {
	global $account, $link;

	if (!isset($account['role']) || $account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
		fancyDie('Access denied');
	}
	if (SCHAN_DBMODE !== 'mysqli') {
		return manageInfo('The import tool needs SCHAN_DBMODE to be set to mysqli.');
	}

	$job = imp_job();
	$get_ok = isset($_GET['token']) && is_string($_GET['token']) && hash_equals(imp_token(), $_GET['token']);

	if (isset($_GET['cancel'])) {
		if (!$get_ok) {
			return imp_form_upload('Your session expired. Please try again.');
		}
		if ($job) {
			imp_job_drop_tables($job);
			unset($_SESSION['schan_import_job']);
		}
		return imp_form_upload('Import stopped and the temporary tables were removed.');
	}
	if (isset($_GET['continue'])) {
		if (!$get_ok || !$job) {
			return imp_form_upload($job ? 'Your session expired. Please try again.' : '');
		}
		return imp_job_run($job);
	}
	if (isset($_GET['report']) && !empty($_SESSION['schan_import_report'])) {
		return imp_form_report($_SESSION['schan_import_report']);
	}

	if (!isset($_POST['import_step'])) {
		if ($job) {
			return '<fieldset><legend>An import is in progress</legend><p>' . imp_job_status($job) . '</p><p><a href="?manage&amp;import&amp;continue=1&amp;token=' . imp_h(rawurlencode(imp_token())) . '">Continue</a> &middot; <a href="?manage&amp;import&amp;cancel=1&amp;token=' . imp_h(rawurlencode(imp_token())) . '">Stop and clean up</a></p></fieldset><br>';
		}
		$text = '';
		if (!empty($_SESSION['schan_import_report'])) {
			$text .= '<p><a href="?manage&amp;import&amp;report=1">Show the report of the last import</a></p>';
		}
		return $text . imp_form_upload();
	}
	if (!isset($_POST['token']) || !is_string($_POST['token']) || !hash_equals(imp_token(), $_POST['token'])) {
		return imp_form_upload('Your session expired. Please try again.');
	}
	if ($job) {
		imp_job_drop_tables($job);
		unset($_SESSION['schan_import_job']);
	}

	// source: uploaded dump or a path on the server
	$sqlpath = '';
	$uploaded = isset($_FILES['sqlfile']) && is_array($_FILES['sqlfile']) && $_FILES['sqlfile']['error'] != UPLOAD_ERR_NO_FILE;
	if ($uploaded) {
		$err = $_FILES['sqlfile']['error'];
		if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
			return imp_form_upload('That file is larger than the server allows (' . imp_h(ini_get('upload_max_filesize')) . '). Use the server path box instead, or compress it to .sql.gz.');
		}
		if ($err != UPLOAD_ERR_OK || !is_uploaded_file($_FILES['sqlfile']['tmp_name'])) {
			return imp_form_upload('The upload failed (error ' . intval($err) . '). Please try again.');
		}
		$sqlpath = $_FILES['sqlfile']['tmp_name'];
	} else {
		$given = isset($_POST['sqlpath']) && is_string($_POST['sqlpath']) ? trim($_POST['sqlpath']) : '';
		if ($given === '') {
			return imp_form_upload('Choose a .sql file or enter its path.');
		}
		$real = @realpath($given);
		if ($real === false || !is_file($real) || !is_readable($real)) {
			return imp_form_upload('The file ' . imp_h($given) . ' does not exist or cannot be read by the web server.');
		}
		$sqlpath = $real;
	}

	$filespath = '';
	$given = isset($_POST['filespath']) && is_string($_POST['filespath']) ? trim($_POST['filespath']) : '';
	if ($given !== '') {
		$real = @realpath($given);
		if ($real === false || !is_dir($real)) {
			return imp_form_upload('The old install folder ' . imp_h($given) . ' does not exist or cannot be read by the web server.');
		}
		$filespath = $real;
	}

	@set_time_limit(0);
	@ignore_user_abort(true);
	$GLOBALS['imp_tmp_tables'] = array();
	$tmp = 'imp_' . substr(md5(uniqid('', true)), 0, 6) . '_';

	// 1. load dump into temp tables
	$tables = array();
	$stats = imp_load_dump($sqlpath, $tmp, $tables);
	if (!$stats['readable']) {
		imp_cleanup();
		return imp_form_upload('The file could not be read.');
	}

	// 2. vichan or schan?
	$type = '';
	$prefix = '';
	foreach ($tables as $orig => $new) {
		if (preg_match('/^(.*)posts_([A-Za-z0-9_-]+)$/', $orig, $m) && in_array('thread', imp_columns($link, $new))) {
			$type = 'vichan';
			$prefix = $m[1];
			break;
		}
	}
	if ($type === '') {
		foreach ($tables as $orig => $new) {
			if (preg_match('/^(.*)posts$/', $orig, $m)) {
				$cols = imp_columns($link, $new);
				if (in_array('parent', $cols) && in_array('timestamp', $cols)) {
					$type = 'schan';
					$prefix = $m[1];
					break;
				}
			}
		}
	}
	if ($type === '') {
		$failed = $stats['failed'] ? ' (' . $stats['failed'] . ' statements failed' . (empty($stats['errors']) ? '' : ': ' . $stats['errors'][0]) . ')' : '';
		imp_cleanup();
		return imp_form_upload('That file does not look like a vichan or TinyIB dump: no posts tables were found' . $failed . '.');
	}

	$cfg = array('prefix' => $tmp . $prefix, 'files' => $filespath);
	$info = imp_inspect($link, $type, $cfg, $error);
	if (!$info) {
		imp_cleanup();
		return imp_form_upload(imp_h($error));
	}

	// 3. vichan boards keep their name, schan goes to the default board
	$cands = array();
	$target = '';
	if ($type === 'vichan') {
		foreach ($info['boards'] as $b) {
			$cands[] = array('src' => $b['uri'], 'slug' => strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $b['uri'])), 'table' => $cfg['prefix'] . 'posts_' . $b['uri'], 'title' => $b['title'] !== '' ? $b['title'] : $b['uri'], 'desc' => $b['desc']);
		}
	} else {
		$target = (defined('SCHAN_DEFAULTBOARD') && SCHAN_DEFAULTBOARD !== '') ? SCHAN_DEFAULTBOARD : 'b';
		$cands[] = array('src' => '', 'slug' => $target, 'table' => $cfg['prefix'] . 'posts', 'title' => $target, 'desc' => '');
	}

	$opts = array('posts' => true, 'files' => ($filespath !== ''), 'bans' => true, 'accounts' => true, 'logs' => true, 'reports' => true, 'keywords' => true, 'haship' => true, 'superadmin' => false, 'scope_all' => false, 'rebuild' => true);

	$job = array(
		'tmp' => $tmp, 'type' => $type, 'cfg' => $cfg, 'opts' => $opts, 'target' => $target,
		'plans' => array(), 'pi' => 0, 'stage' => 'posts', 'tables' => $GLOBALS['imp_tmp_tables'],
		'rep' => array('lines' => array(), 'warnings' => array(), 'errors' => array(), 'passwords' => array()),
		'acct_map' => array(), 'slug_of' => array(), 'slugs' => array(),
		'log_off' => 0, 'log_added' => 0, 'rb' => array('pi' => 0, 'last' => 0, 'phase' => 'threads', 'done' => 0),
	);
	$job['rep']['lines'][] = 'Detected a <b>' . ($type === 'vichan' ? 'vichan' : 'TinyIB') . '</b> dump with ' . count($tables) . ' tables.';
	if ($stats['failed']) {
		$job['rep']['warnings'][] = $stats['failed'] . ' statements in the dump could not be loaded' . (empty($stats['errors']) ? '' : ' (first error: ' . $stats['errors'][0] . ')') . '. Some data may be missing.';
	}

	// post number table, kept between steps, dropped at the end
	$map_name = $tmp . 'map';
	mysqli_query($link, "CREATE TABLE `" . $map_name . "` (`board` VARCHAR(64) NOT NULL, `old` INT UNSIGNED NOT NULL, `new` INT UNSIGNED NOT NULL, `told` INT UNSIGNED NOT NULL, `tnew` INT UNSIGNED NOT NULL DEFAULT 0, `op` TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (`board`, `old`))");
	$job['tables'][] = $map_name;

	foreach ($cands as $plan) {
		$err = imp_valid_slug($plan['slug']);
		if ($err !== '') {
			$job['rep']['errors'][] = 'Board "' . imp_h($plan['slug']) . '" skipped: ' . $err . '.';
			continue;
		}
		$created = false;
		imp_ensure_board($plan['slug'], $plan['title'], $plan['desc'], $created);
		$job['rep']['lines'][] = ($created ? 'Created' : 'Using existing') . ' board <b>' . imp_h($plan['slug']) . '</b>.';
		$job['slugs'][] = $plan['slug'];
		if ($type === 'vichan') {
			$job['slug_of'][$plan['src']] = $plan['slug'];
		}
		$plan += array('keep' => true, 'total' => 0, 'orphans' => 0, 'last' => 0, 'ok' => 0, 'fail' => 0, 'extra' => 0, 'embeds' => 0, 'errors' => array(), 'fstats' => array('copied' => 0, 'missing' => 0, 'existing' => 0, 'failed' => 0));
		imp_build_map($job, $plan);
		$job['plans'][] = $plan;
	}
	if (empty($job['plans'])) {
		imp_job_drop_tables($job);
		return imp_form_report($job['rep']);
	}
	$GLOBALS['imp_tmp_tables'] = array();
	imp_job_save($job);
	return imp_form_progress($job);
}
