<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * Board scoping
 * -------------
 * Every query that touches a per-board table filters on the `board` column.
 * The active board slug is available via current_board() (see
 * inc/database/database.php). Accounts, bans, keywords, logs and reports also
 * accept the special value '*' meaning "applies to all boards".
 *
 * Reports do NOT accept '*': a report always belongs to the board its post is
 * on.
 */

// Account functions
function accountByID($id) {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBACCOUNTS . "` WHERE `id` = '" . mysqli_real_escape_string($link, $id) . "' LIMIT 1");
	if ($result) {
		while ($account = mysqli_fetch_assoc($result)) {
			return $account;
		}
	}
}

function accountByUsername($username) {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBACCOUNTS . "` WHERE `username` = '" . mysqli_real_escape_string($link, $username) . "' LIMIT 1");
	if ($result) {
		while ($account = mysqli_fetch_assoc($result)) {
			return $account;
		}
	}
}

function allAccounts() {
	global $link;
	$accounts = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBACCOUNTS . "` ORDER BY `role` ASC, `username` ASC");
	if ($result) {
		while ($account = mysqli_fetch_assoc($result)) {
			$accounts[] = $account;
		}
	}
	return $accounts;
}

function insertAccount($account) {
	global $link;
	if (!isset($account['board'])) {
		$account['board'] = '*';
	}
	mysqli_query($link, "INSERT INTO `" . SCHAN_DBACCOUNTS . "` (`username`, `password`, `role`, `lastactive`, `board`) VALUES ('" . mysqli_real_escape_string($link, $account['username']) . "', '" . mysqli_real_escape_string($link, hashData($account['password'])) . "', '" . mysqli_real_escape_string($link, $account['role']) . "', '0', '" . mysqli_real_escape_string($link, $account['board']) . "')");
	return mysqli_insert_id($link);
}

function updateAccount($account) {
	global $link;
	if (!isset($account['board'])) {
		$account['board'] = '*';
	}
	mysqli_query($link, "UPDATE `" . SCHAN_DBACCOUNTS . "` SET `username` = '" . mysqli_real_escape_string($link, $account['username']) . "', `password` = '" . mysqli_real_escape_string($link, hashData($account['password'])) . "', `role` = '" . mysqli_real_escape_string($link, $account['role']) . "', `lastactive` = " . mysqli_real_escape_string($link, $account['lastactive'])  . ", `board` = '" . mysqli_real_escape_string($link, $account['board']) . "' WHERE `id` = " . mysqli_real_escape_string($link, $account['id']) . " LIMIT 1");
}

function deleteAccountByID($id) {
	global $link;
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBACCOUNTS . "` WHERE `id` = " . mysqli_real_escape_string($link, $id) . " LIMIT 1");
}

// Board functions
function boardBySlug($slug) {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBBOARDS . "` WHERE `slug` = '" . mysqli_real_escape_string($link, $slug) . "' LIMIT 1");
	if ($result) {
		while ($board = mysqli_fetch_assoc($result)) {
			return $board;
		}
	}
}

function boardByID($id) {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBBOARDS . "` WHERE `id` = '" . mysqli_real_escape_string($link, $id) . "' LIMIT 1");
	if ($result) {
		while ($board = mysqli_fetch_assoc($result)) {
			return $board;
		}
	}
}

function allBoards() {
	global $link;
	$boards = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBBOARDS . "` ORDER BY `slug` ASC");
	if ($result) {
		while ($board = mysqli_fetch_assoc($result)) {
			$boards[] = $board;
		}
	}
	return $boards;
}

function insertBoard($board) {
	global $link;
	mysqli_query($link, "INSERT INTO `" . SCHAN_DBBOARDS . "` (`slug`, `title`, `desc`, `logo`, `locked`, `created`) VALUES ('" . mysqli_real_escape_string($link, $board['slug']) . "', '" . mysqli_real_escape_string($link, $board['title']) . "', '" . mysqli_real_escape_string($link, $board['desc']) . "', '" . mysqli_real_escape_string($link, $board['logo']) . "', '" . mysqli_real_escape_string($link, isset($board['locked']) ? $board['locked'] : 0) . "', '" . time() . "')");
	return mysqli_insert_id($link);
}

function updateBoard($board) {
	global $link;
	$locked = !empty($board['locked']) ? 1 : 0;
	mysqli_query($link, "UPDATE `" . SCHAN_DBBOARDS . "` SET `title` = '" . mysqli_real_escape_string($link, $board['title']) . "', `desc` = '" . mysqli_real_escape_string($link, $board['desc']) . "', `logo` = '" . mysqli_real_escape_string($link, $board['logo']) . "', `locked` = " . $locked . " WHERE `id` = " . intval($board['id']) . " LIMIT 1");
}

function deleteBoardByID($id) {
	global $link;
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBBOARDS . "` WHERE `id` = " . mysqli_real_escape_string($link, $id) . " LIMIT 1");
}

function countBoardPosts($slug) {
	global $link;
	$row = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $slug) . "'"));
	return isset($row[0]) ? intval($row[0]) : 0;
}

function countBoardThreads($slug) {
	global $link;
	$row = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $slug) . "' AND `parent` = 0"));
	return isset($row[0]) ? intval($row[0]) : 0;
}

// Bulk operations used when a board is removed
function deletePostsByBoard($slug) {
	global $link;
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $slug) . "'");
}

function deleteReportsByBoard($slug) {
	global $link;
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBREPORTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $slug) . "'");
}

function globalizeBansByBoard($slug) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBBANS . "` SET `board` = '*' WHERE `board` = '" . mysqli_real_escape_string($link, $slug) . "'");
}

function globalizeKeywordsByBoard($slug) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBKEYWORDS . "` SET `board` = '*' WHERE `board` = '" . mysqli_real_escape_string($link, $slug) . "'");
}

function globalizeLogsByBoard($slug) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBLOGS . "` SET `board` = '*' WHERE `board` = '" . mysqli_real_escape_string($link, $slug) . "'");
}

// ---------------------------------------------------------------------------
// Move operations.
//
// A move flips the `board` column on the OP and every reply in the thread,
// and physically relocates the OP's file/thumb to the target board's folder.
// The thread ID stays the same, so backlinks keep working.
// ---------------------------------------------------------------------------

function boardPostsInThread($id, $moderated_only = false) {
	global $link;
	$posts = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBPOSTS . "` WHERE (`id` = " . intval($id) . " OR `parent` = " . intval($id) . ")" . ($moderated_only ? " AND `moderated` = 1" : "") . " AND " . postBoardWhere() . " ORDER BY `id` ASC");
	if ($result) {
		while ($post = mysqli_fetch_assoc($result)) {
			$posts[] = $post;
		}
	}
	return $posts;
}

// Rewrites >>quote links inside a moved thread so they point at the new post numbers.
// Links to posts that are not part of the moved thread are turned back into plain text.
function remapQuoteLinks($message, $map, $new_op) {
	return preg_replace_callback('/<a href="res\/\d+\.html#\d+" class="ref(?:op|reply)">&gt;&gt;(\d+)<\/a>/', function ($m) use ($map, $new_op) {
		$old = intval($m[1]);
		if (isset($map[$old])) {
			$new = $map[$old];
			$is_op = ($new == $new_op);
			return '<a href="res/' . $new_op . '.html#' . $new . '" class="' . ($is_op ? 'refop' : 'refreply') . '">&gt;&gt;' . $new . '</a>';
		}
		return '&gt;&gt;' . $m[1];
	}, $message);
}

// Returns array(ok, error message, new thread id).
function moveThreadToBoard($id, $target_board) {
	global $link;
	$id = intval($id);
	$target_board = strtolower(trim($target_board));

	$op = postByID($id);
	if (empty($op) || $op['parent'] != SCHAN_NEWTHREAD) {
		return array(false, 'That post is not a thread.', 0);
	}

	$target = boardBySlug($target_board);
	if (empty($target)) {
		return array(false, 'Target board does not exist.', 0);
	}

	$source_board = $op['board'];
	if ($source_board === $target_board) {
		return array(false, 'Thread is already on that board.', 0);
	}

	$moved_files = array();
	if ($op['file'] != '') {
		$src_from = $source_board . '/src/' . $op['file'];
		$src_to   = $target_board . '/src/' . $op['file'];
		if (is_file($src_from)) {
			if (!@rename($src_from, $src_to)) {
				return array(false, 'Could not move ' . $src_from . ' to ' . $src_to, 0);
			}
			$moved_files[] = array('from' => $src_from, 'to' => $src_to);
		}
	}
	if ($op['thumb'] != '') {
		$th_from = $source_board . '/thumb/' . $op['thumb'];
		$th_to   = $target_board . '/thumb/' . $op['thumb'];
		if (is_file($th_from)) {
			if (!@rename($th_from, $th_to)) {
				foreach (array_reverse($moved_files) as $m) {
					@rename($m['to'], $m['from']);
				}
				return array(false, 'Could not move ' . $th_from . ' to ' . $th_to, 0);
			}
			$moved_files[] = array('from' => $th_from, 'to' => $th_to);
		}
	}

	// Post numbers are per board, so the thread gets fresh numbers on the target board.
	$src = mysqli_real_escape_string($link, $source_board);
	$dst = mysqli_real_escape_string($link, $target_board);
	$posts = array();
	$result = mysqli_query($link, "SELECT `id`, `parent`, `message` FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . $src . "' AND (`id` = " . $id . " OR `parent` = " . $id . ") ORDER BY `id` ASC");
	if ($result) {
		while ($row = mysqli_fetch_assoc($result)) {
			$posts[] = $row;
		}
	}

	syncBoardCounter($target_board);
	$map = array();
	$new_op = nextPostID($target_board);
	$map[$id] = $new_op;
	foreach ($posts as $p) {
		if (intval($p['id']) != $id) {
			$map[intval($p['id'])] = nextPostID($target_board);
		}
	}

	foreach ($posts as $p) {
		$old = intval($p['id']);
		$new = $map[$old];
		$parent = ($old == $id) ? 0 : $new_op;
		$message = remapQuoteLinks($p['message'], $map, $new_op);
		mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `id` = " . $new . ", `parent` = " . $parent . ", `board` = '" . $dst . "', `message` = '" . mysqli_real_escape_string($link, $message) . "' WHERE `board` = '" . $src . "' AND `id` = " . $old . " LIMIT 1");
		mysqli_query($link, "UPDATE `" . SCHAN_DBREPORTS . "` SET `board` = '" . $dst . "', `post` = " . $new . " WHERE `board` = '" . $src . "' AND `post` = " . $old);
	}

	if (function_exists('clearPostCache')) {
		clearPostCache();
	}

	return array(true, '', $new_op);
}

function movePostToBoard($id, $target_board) {
	return moveThreadToBoard($id, $target_board);
}

// Ban functions
function banByID($id) {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBBANS . "` WHERE `id` = '" . mysqli_real_escape_string($link, $id) . "' LIMIT 1");
	if ($result) {
		while ($ban = mysqli_fetch_assoc($result)) {
			return $ban;
		}
	}
}

function banByIP($ip) {
	global $link;
	$board = current_board();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBBANS . "` WHERE (`board` = '" . mysqli_real_escape_string($link, $board) . "' OR `board` = '*') AND (`ip` = '" . mysqli_real_escape_string($link, $ip) . "' OR `ip` = '" . mysqli_real_escape_string($link, hashData($ip)) . "') LIMIT 1");
	if ($result) {
		while ($ban = mysqli_fetch_assoc($result)) {
			return $ban;
		}
	}
}

function allBans() {
	global $link;
	$board = current_board();
	$bans = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBBANS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' OR `board` = '*' ORDER BY `timestamp` DESC");
	if ($result) {
		while ($ban = mysqli_fetch_assoc($result)) {
			$bans[] = $ban;
		}
	}
	return $bans;
}

function insertBan($ban) {
	global $link;
	$board = isset($ban['board']) ? $ban['board'] : current_board();
	mysqli_query($link, "INSERT INTO `" . SCHAN_DBBANS . "` (`ip`, `timestamp`, `expire`, `reason`, `board`) VALUES ('" . mysqli_real_escape_string($link, hashData($ban['ip'])) . "', '" . time() . "', '" . mysqli_real_escape_string($link, $ban['expire']) . "', '" . mysqli_real_escape_string($link, $ban['reason']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
	return mysqli_insert_id($link);
}

function clearExpiredBans() {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBBANS . "` WHERE `expire` > 0 AND `expire` <= " . time());
	if ($result) {
		while ($ban = mysqli_fetch_assoc($result)) {
			mysqli_query($link, "DELETE FROM `" . SCHAN_DBBANS . "` WHERE `id` = " . $ban['id'] . " LIMIT 1");
		}
	}
}

function deleteBanByID($id) {
	global $link;
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBBANS . "` WHERE `id` = " . mysqli_real_escape_string($link, $id) . " LIMIT 1");
}

// Keyword functions
function keywordByID($id) {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBKEYWORDS . "` WHERE `id` = '" . mysqli_real_escape_string($link, $id) . "' LIMIT 1");
	if ($result) {
		while ($keyword = mysqli_fetch_assoc($result)) {
			return $keyword;
		}
	}
	return array();
}

function keywordByText($text) {
	global $link;
	$text = strtolower($text);
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBKEYWORDS . "` WHERE `text` = '" . mysqli_real_escape_string($link, $text) . "'");
	if ($result) {
		while ($keyword = mysqli_fetch_assoc($result)) {
			if ($keyword['text'] === $text) {
				return $keyword;
			}
		}
	}
	return array();
}

function allKeywords() {
	global $link;
	$board = current_board();
	$keywords = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBKEYWORDS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' OR `board` = '*' ORDER BY `text` ASC");
	if ($result) {
		while ($keyword = mysqli_fetch_assoc($result)) {
			$keywords[] = $keyword;
		}
	}
	return $keywords;
}

function insertKeyword($keyword) {
	global $link;
	$keyword['text'] = strtolower($keyword['text']);
	$board = isset($keyword['board']) ? $keyword['board'] : '*';
	mysqli_query($link, "INSERT INTO `" . SCHAN_DBKEYWORDS . "` (`text`, `action`, `board`) VALUES ('" . mysqli_real_escape_string($link, $keyword['text']) . "', '" . mysqli_real_escape_string($link, $keyword['action']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
}

function deleteKeyword($id) {
	global $link;
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBKEYWORDS . "` WHERE `id` = '" . mysqli_real_escape_string($link, $id) . "'");
}

// Log functions
function getLogs($offset, $limit) {
	global $link;
	$board = current_board();
	$logs = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBLOGS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' OR `board` = '*' ORDER BY `timestamp` DESC LIMIT " . intval($offset) . ", " . intval($limit));
	if ($result) {
		while ($log = mysqli_fetch_assoc($result)) {
			$logs[] = $log;
		}
	}
	return $logs;
}

function allLogs() {
	global $link;
	$board = current_board();
	$logs = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBLOGS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' OR `board` = '*' ORDER BY `timestamp` ASC");
	if ($result) {
		while ($log = mysqli_fetch_assoc($result)) {
			$logs[] = $log;
		}
	}
	return $logs;
}

function insertLog($log) {
	global $link;
	$board = isset($log['board']) ? $log['board'] : current_board();
	mysqli_query($link, "INSERT INTO `" . SCHAN_DBLOGS . "` (`timestamp`, `account`, `message`, `board`) VALUES ('" . mysqli_real_escape_string($link, $log['timestamp']) . "', '" . mysqli_real_escape_string($link, $log['account']) . "', '" . mysqli_real_escape_string($link, $log['message']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
}

// Post numbering helpers.
//   Post numbers are per board, so every lookup by post number has to include the board.
function postBoardWhere() {
	global $link;
	return "`board` = '" . mysqli_real_escape_string($link, current_board()) . "'";
}

// Makes sure a board's counter is not behind its highest existing post number.
function syncBoardCounter($board) {
	global $link;
	$b = mysqli_real_escape_string($link, $board);
	mysqli_query($link, "UPDATE `" . SCHAN_DBBOARDS . "` SET `lastpost` = GREATEST(`lastpost`, (SELECT COALESCE(MAX(`id`), 0) FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . $b . "')) WHERE `slug` = '" . $b . "' LIMIT 1");
}

// Reserves and returns the next post number for a board.
function nextPostID($board) {
	global $link;
	$b = mysqli_real_escape_string($link, $board);
	mysqli_query($link, "UPDATE `" . SCHAN_DBBOARDS . "` SET `lastpost` = LAST_INSERT_ID(`lastpost` + 1) WHERE `slug` = '" . $b . "' LIMIT 1");
	if (mysqli_affected_rows($link) > 0) {
		return intval(mysqli_insert_id($link));
	}
	// No row for this board: fall back to highest existing number + 1.
	$row = mysqli_fetch_row(mysqli_query($link, "SELECT COALESCE(MAX(`id`), 0) + 1 FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . $b . "'"));
	return isset($row[0]) ? intval($row[0]) : 1;
}

// Removes the file from a post (the post itself stays). Used by the Janitor role's "Delete file".
function clearPostFileByID($id) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `file` = '', `file_hex` = '', `file_original` = '', `file_size` = 0, `file_size_formatted` = '', `image_width` = 0, `image_height` = 0, `thumb` = '', `thumb_width` = 0, `thumb_height` = 0 WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

// Post functions
function uniquePosts() {
	global $link;
	$board = current_board();
	$row = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(DISTINCT(`ip`)) FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "'"));
	return isset($row[0]) ? $row[0] : 0;
}

function postByID($id) {
	global $link;
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBPOSTS . "` WHERE `id` = '" . mysqli_real_escape_string($link, $id) . "' AND " . postBoardWhere() . " LIMIT 1");
	if ($result) {
		while ($post = mysqli_fetch_assoc($result)) {
			return $post;
		}
	}
}

function threadExistsByID($id) {
	global $link;
	$board = current_board();
	return mysqli_result(mysqli_query($link, "SELECT COUNT(*) FROM `" . SCHAN_DBPOSTS . "` WHERE `id` = '" . mysqli_real_escape_string($link, $id) . "' AND `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `parent` = 0 AND `moderated` = 1 LIMIT 1"), 0, 0) > 0;
}

function insertPost($post) {
	global $link;
	$board = isset($post['board']) ? $post['board'] : current_board();

	for ($attempt = 0; $attempt < 10; $attempt++) {
		$id = nextPostID($board);
		$ok = mysqli_query($link, "INSERT INTO `" . SCHAN_DBPOSTS . "` (`id`, `parent`, `timestamp`, `bumped`, `ip`, `name`, `tripcode`, `email`, `nameblock`, `subject`, `message`, `password`, `file`, `file_hex`, `file_original`, `file_size`, `file_size_formatted`, `image_width`, `image_height`, `thumb`, `thumb_width`, `thumb_height`, `moderated`, `board`) VALUES (" . $id . ", " . $post['parent'] . ", " . time() . ", " . time() . ", '" . hashData(remoteAddress()) . "', '" . mysqli_real_escape_string($link, $post['name']) . "', '" . mysqli_real_escape_string($link, $post['tripcode']) . "',	'" . mysqli_real_escape_string($link, $post['email']) . "',	'" . mysqli_real_escape_string($link, $post['nameblock']) . "', '" . mysqli_real_escape_string($link, $post['subject']) . "', '" . mysqli_real_escape_string($link, $post['message']) . "', '" . mysqli_real_escape_string($link, $post['password']) . "', '" . $post['file'] . "', '" . $post['file_hex'] . "', '" . mysqli_real_escape_string($link, $post['file_original']) . "', " . $post['file_size'] . ", '" . $post['file_size_formatted'] . "', " . $post['image_width'] . ", " . $post['image_height'] . ", '" . $post['thumb'] . "', " . $post['thumb_width'] . ", " . $post['thumb_height'] . ", " . $post['moderated'] . ", '" . mysqli_real_escape_string($link, $board) . "')");
		if ($ok) {
			return $id;
		}
		if (mysqli_errno($link) != 1062) {
			break;
		}
		// Duplicate number: the counter was behind. Resync and try again.
		syncBoardCounter($board);
	}
	return 0;
}

function updatePostMessage($id, $message) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `message` = '" .  mysqli_real_escape_string($link, $message) . "' WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

function updatePostBumped($id, $bumped) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `bumped` = '" .  mysqli_real_escape_string($link, $bumped) . "' WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

function approvePostByID($id, $moderated) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `moderated` = " . $moderated . " WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

function bumpThreadByID($id) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `bumped` = " . time() . " WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

function stickyThreadByID($id, $setsticky) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `stickied` = '" . mysqli_real_escape_string($link, $setsticky) . "' WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

function lockThreadByID($id, $setlock) {
	global $link;
	mysqli_query($link, "UPDATE `" . SCHAN_DBPOSTS . "` SET `locked` = '" . mysqli_real_escape_string($link, $setlock) . "' WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

function countThreads() {
	global $link;
	$board = current_board();
	return mysqli_result(mysqli_query($link, "SELECT COUNT(*) FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `parent` = 0 AND `moderated` = 1"), 0, 0);
}

function allThreads($moderated_only = true) {
	global $link;
	$board = current_board();
	$threads = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `parent` = 0" . ($moderated_only ? " AND moderated > 0" : "") . " ORDER BY `stickied` DESC, `bumped` DESC");
	if ($result) {
		while ($thread = mysqli_fetch_assoc($result)) {
			$threads[] = $thread;
		}
	}
	return $threads;
}

function numRepliesToThreadByID($id) {
	global $link;
	return mysqli_result(mysqli_query($link, "SELECT COUNT(*) FROM `" . SCHAN_DBPOSTS . "` WHERE `parent` = " . intval($id) . " AND `moderated` = 1 AND " . postBoardWhere()), 0, 0);
}

function _postsInThreadByID($id, $moderated_only = true) {
	global $link;
	$posts = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBPOSTS . "` WHERE (`id` = " . intval($id) . " OR `parent` = " . intval($id) . ")" . ($moderated_only ? " AND `moderated` = 1" : "") . " AND " . postBoardWhere() . " ORDER BY `id` ASC");
	if ($result) {
		while ($post = mysqli_fetch_assoc($result)) {
			$posts[] = $post;
		}
	}
	return $posts;
}

function imagesInThreadByID($id, $moderated_only = true) {
	$images = 0;
	$posts = postsInThreadByID($id, false);
	foreach ($posts as $post) {
		if ($post['file'] != '') {
			$images++;
		}
	}
	return $images;
}

function postsByHex($hex) {
	global $link;
	$board = current_board();
	$posts = array();
	$result = mysqli_query($link, "SELECT `id`, `parent` FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `file_hex` = '" . mysqli_real_escape_string($link, $hex) . "' AND `moderated` = 1 LIMIT 1");
	if ($result) {
		while ($post = mysqli_fetch_assoc($result)) {
			$posts[] = $post;
		}
	}
	return $posts;
}

function latestPosts($moderated = true) {
	global $link;
	$board = current_board();
	$posts = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `moderated` " . ($moderated ? '>' : '=') . " 0 ORDER BY `timestamp` DESC LIMIT 10");
	if ($result) {
		while ($post = mysqli_fetch_assoc($result)) {
			$posts[] = $post;
		}
	}
	return $posts;
}

function deletePostByID($id) {
	global $link;
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBPOSTS . "` WHERE `id` = " . intval($id) . " AND " . postBoardWhere() . " LIMIT 1");
}

function trimThreads() {
	global $link;
	$board = current_board();
	if (SCHAN_MAXTHREADS > 0) {
		$result = mysqli_query($link, "SELECT `id` FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `parent` = 0 AND `moderated` = 1 ORDER BY `stickied` DESC, `bumped` DESC LIMIT " . SCHAN_MAXTHREADS . ", 10");
		if ($result) {
			while ($post = mysqli_fetch_assoc($result)) {
				deletePost($post['id']);
			}
		}
	}
}

function lastPostByIP() {
	global $link;
	$board = current_board();
	$replies = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBPOSTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND (`ip` = '" . mysqli_real_escape_string($link, remoteAddress()) . "' OR `ip` = '" . mysqli_real_escape_string($link, hashData(remoteAddress())) . "') ORDER BY `id` DESC LIMIT 1");
	if ($replies) {
		while ($post = mysqli_fetch_assoc($replies)) {
			return $post;
		}
	}
}

// Report functions
function reportByIP($post, $ip) {
	global $link;
	$board = current_board();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBREPORTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `post` = '" . mysqli_real_escape_string($link, $post) . "' AND (`ip` = '" . mysqli_real_escape_string($link, $ip) . "' OR `ip` = '" . mysqli_real_escape_string($link, hashData($ip)) . "') LIMIT 1");
	if ($result) {
		while ($report = mysqli_fetch_assoc($result)) {
			return $report;
		}
	}
}

function reportsByPost($post) {
	global $link;
	$board = current_board();
	$reports = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBREPORTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `post` = '" . mysqli_real_escape_string($link, $post) . "'");
	if ($result) {
		while ($report = mysqli_fetch_assoc($result)) {
			$reports[] = $report;
		}
	}
	return $reports;
}

function allReports() {
	global $link;
	$board = current_board();
	$reports = array();
	$result = mysqli_query($link, "SELECT * FROM `" . SCHAN_DBREPORTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' ORDER BY `post` ASC");
	if ($result) {
		while ($report = mysqli_fetch_assoc($result)) {
			$reports[] = $report;
		}
	}
	return $reports;
}

function insertReport($report) {
	global $link;
	$board = isset($report['board']) ? $report['board'] : current_board();
	mysqli_query($link, "INSERT INTO `" . SCHAN_DBREPORTS . "` (`ip`, `post`, `board`) VALUES ('" . mysqli_real_escape_string($link, hashData($report['ip'])) . "', '" . mysqli_real_escape_string($link, $report['post']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
}

function deleteReportsByPost($post) {
	global $link;
	$board = current_board();
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBREPORTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND `post` = '" . mysqli_real_escape_string($link, $post) . "'");
}

function deleteReportsByIP($ip) {
	global $link;
	$board = current_board();
	mysqli_query($link, "DELETE FROM `" . SCHAN_DBREPORTS . "` WHERE `board` = '" . mysqli_real_escape_string($link, $board) . "' AND (`ip` = '" . mysqli_real_escape_string($link, $ip) . "' OR `ip` = '" . mysqli_real_escape_string($link, hashData($ip)) . "')");
}

// Utility functions
function mysqli_result($res, $row, $field = 0) {
	$res->data_seek($row);
	$datarow = $res->fetch_array();
	return $datarow[$field];
}
