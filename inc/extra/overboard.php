<?php
/*
 * overboard: /o/ shows the latest bumped threads from every board with the board name next to each
 * poster name, plus a catalog of the same threads
 *
 * written as static files (o/index.html, o/catalog.html), rebuilt whenever any board's index is
 * (new post, delete, Rebuild All), no db change
 */

if (!defined('SCHAN_OVERBOARD')) {
	define('SCHAN_OVERBOARD', 'o');       // Slug / folder of the overboard
}
if (!defined('SCHAN_OVERBOARD_INDEX')) {
	define('SCHAN_OVERBOARD_INDEX', 30);   // Threads shown on the overboard index
}
if (!defined('SCHAN_OVERBOARD_CATALOG')) {
	define('SCHAN_OVERBOARD_CATALOG', 150); // Threads shown in the overboard catalog
}

// the slug belongs to the overboard, so it cannot be used for a real board
if (isset($GLOBALS['schan_reserved_slugs']) && is_array($GLOBALS['schan_reserved_slugs']) && !in_array(SCHAN_OVERBOARD, $GLOBALS['schan_reserved_slugs'])) {
	$GLOBALS['schan_reserved_slugs'][] = SCHAN_OVERBOARD;
}

// every board's threads, newest bump first. Each thread carries 'board' and 'overboard'
function overboardThreads() {
	$prev = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$all = array();
	foreach (allBoards() as $b) {
		if ($b['slug'] === SCHAN_OVERBOARD) {
			continue;
		}
		$GLOBALS['schan_forced_board'] = $b['slug'];
		foreach (allThreads() as $t) {
			$t['board'] = $b['slug'];
			$t['overboard'] = true;
			$all[] = $t;
		}
	}
	$GLOBALS['schan_forced_board'] = $prev;
	usort($all, function ($a, $b) {
		if ($a['bumped'] == $b['bumped']) {
			return $b['id'] - $a['id'];
		}
		return $b['bumped'] - $a['bumped'];
	});
	return $all;
}

// boards a visitor can start a thread on from the overboard (the overboard itself and locked boards are left out)
function overboardPostableBoards() {
	$list = array();
	foreach (allBoards() as $b) {
		if ($b['slug'] === SCHAN_OVERBOARD || !empty($b['locked'])) {
			continue;
		}
		$list[] = $b;
	}
	return $list;
}

// extra row at the top of the overboard's posting form: dropdown of the board to post in
// the last board you chose is remembered in your browser
function overboardBoardRowHTML() {
	$boards = overboardPostableBoards();
	if (empty($boards)) {
		return '<tr><td class="postblock">' . __('Board') . '</td><td>' . __('No boards are accepting posts right now.') . '</td></tr>';
	}

	$options = '';
	foreach ($boards as $b) {
		$label = '/' . $b['slug'] . '/' . (isset($b['title']) && $b['title'] != '' ? ' - ' . $b['title'] : '');
		$options .= '<option value="' . htmlentities($b['slug'], ENT_QUOTES) . '">' . htmlentities($label, ENT_QUOTES) . '</option>';
	}

	$js = <<<'JS'
(function(){
	var s=document.getElementById('overboardBoard');
	if(!s){return;}
	try{
		var v=localStorage.getItem('schan_overboard_board');
		if(v){for(var i=0;i<s.options.length;i++){if(s.options[i].value===v){s.value=v;}}}
	}catch(e){}
	s.addEventListener('change',function(){try{localStorage.setItem('schan_overboard_board',s.value);}catch(e){}});
})();
JS;

	return '<tr><td class="postblock">' . __('Board') . '</td><td><select name="board" id="overboardBoard">' . $options . '</select>'
		. '<script type="text/javascript">' . $js . '</script></td></tr>';
}

// builds the overboard like a normal board: paged index pages (o/index.html, o/1.html...) with the
// posting form on top, plus the catalog. SCHAN_THREADSPERPAGE threads per page, the index covers the
// SCHAN_OVERBOARD_INDEX latest bumped threads
function rebuildOverboard() {
	$prev = isset($GLOBALS['schan_forced_board']) ? $GLOBALS['schan_forced_board'] : null;
	$threads = overboardThreads();

	$per_page = max(1, intval(SCHAN_THREADSPERPAGE));
	$index_threads = array_slice($threads, 0, SCHAN_OVERBOARD_INDEX);
	$pages = ceil(count($index_threads) / $per_page) - 1;
	$dir = SCHAN_OVERBOARD . '/';

	$page = 0;
	$i = 0;
	$index = '';
	foreach ($index_threads as $thread) {
		// postsInThreadByID() & co. look at the "current" board, so point it at this thread's board
		$GLOBALS['schan_forced_board'] = $thread['board'];
		$replies = postsInThreadByID($thread['id']);
		$thread['omitted'] = max(0, count($replies) - SCHAN_PREVIEWREPLIES - 1);

		$htmlreplies = array();
		for ($j = count($replies) - 1; $j > $thread['omitted']; $j--) {
			$replies[$j]['board'] = $thread['board'];
			$replies[$j]['overboard'] = true;
			$htmlreplies[] = buildPost($replies[$j], SCHAN_INDEXPAGE);
		}
		if ($i > 0) {
			$index .= "\n<hr>";
		}
		$index .= buildPost($thread, SCHAN_INDEXPAGE) . implode('', array_reverse($htmlreplies));

		if (++$i >= $per_page) {
			$GLOBALS['schan_forced_board'] = $prev;
			$file = ($page == 0) ? $dir . SCHAN_INDEX : $dir . $page . '.html';
			writePage($file, buildPage($index, 0, $pages, $page, 0, SCHAN_OVERBOARD));
			$page++;
			$i = 0;
			$index = '';
		}
	}
	$GLOBALS['schan_forced_board'] = $prev;

	if ($page == 0 || $index != '') {
		$file = ($page == 0) ? $dir . SCHAN_INDEX : $dir . $page . '.html';
		writePage($file, buildPage($index, 0, $pages, $page, 0, SCHAN_OVERBOARD));
	}

	// pages that no longer exist (fewer threads than before)
	for ($p = max(0, $pages) + 1; is_file($dir . $p . '.html'); $p++) {
		@unlink($dir . $p . '.html');
	}

	$catalog = '';
	foreach (array_slice($threads, 0, SCHAN_OVERBOARD_CATALOG) as $thread) {
		$GLOBALS['schan_forced_board'] = $thread['board'];
		$catalog .= buildCatalogPost($thread);
	}
	$GLOBALS['schan_forced_board'] = $prev;

	writePage($dir . 'catalog.html', buildPage($catalog, -1, 0, 0, 0, SCHAN_OVERBOARD));
}
