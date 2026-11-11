<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

if (!function_exists('mysqli_connect')) {
	fancyDie("MySQL library is not installed");
}

$link = @mysqli_connect(SCHAN_DBHOST, SCHAN_DBUSERNAME, SCHAN_DBPASSWORD);
if (!$link) {
	fancyDie("Could not connect to database: " . ((is_object($link)) ? mysqli_error($link) : (($link_error = mysqli_connect_error()) ? $link_error : '(unknown error)')));
}
$db_selected = @mysqli_query($link, "USE " . SCHAN_DBNAME);
if (!$db_selected) {
	fancyDie("Could not select database: " . ((is_object($link)) ? mysqli_error($link) : (($link_error = mysqli_connect_error()) ? $link_error : '(unknown error')));
}
mysqli_query($link, "SET NAMES 'utf8mb4'");

// Create tables (when necessary)
if (mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '" . SCHAN_DBACCOUNTS . "'")) == 0) {
	mysqli_query($link, $accounts_sql);
}

if (mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '" . SCHAN_DBBANS . "'")) == 0) {
	mysqli_query($link, $bans_sql);
}

if (mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '" . SCHAN_DBKEYWORDS . "'")) == 0) {
	mysqli_query($link, $keywords_sql);
}

if (mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '" . SCHAN_DBLOGS . "'")) == 0) {
	mysqli_query($link, $logs_sql);
}

if (mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '" . SCHAN_DBPOSTS . "'")) == 0) {
	mysqli_query($link, $posts_sql);
}

if (mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '" . SCHAN_DBREPORTS . "'")) == 0) {
	mysqli_query($link, $reports_sql);
}

if (mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '" . SCHAN_DBBOARDS . "'")) == 0) {
	mysqli_query($link, $boards_sql);
}

// Schema migrations for existing installations.
//   Adds the `board` column to every shared table on first boot after the
//   multi-board upgrade. Each ALTER is guarded by a column-existence check
//   so it is safe to run on every request.
$board_col_tables = array(
	SCHAN_DBPOSTS    => 'board',
	SCHAN_DBREPORTS  => 'board',
	SCHAN_DBBANS     => 'board',
	SCHAN_DBKEYWORDS => 'board',
	SCHAN_DBLOGS     => 'board',
	SCHAN_DBACCOUNTS => 'board',
);
foreach ($board_col_tables as $table => $column) {
	if (mysqli_num_rows(mysqli_query($link, "SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "'")) == 0) {
		mysqli_query($link, "ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` VARCHAR(64) NOT NULL DEFAULT ''");
	}
}

if (mysqli_num_rows(mysqli_query($link, "SHOW COLUMNS FROM `" . SCHAN_DBPOSTS . "` LIKE 'stickied'")) == 0) {
	mysqli_query($link, "ALTER TABLE `" . SCHAN_DBPOSTS . "` ADD COLUMN stickied TINYINT(1) NOT NULL DEFAULT '0'");
}

if (mysqli_num_rows(mysqli_query($link, "SHOW COLUMNS FROM `" . SCHAN_DBPOSTS . "` LIKE 'locked'")) == 0) {
	mysqli_query($link, "ALTER TABLE `" . SCHAN_DBPOSTS . "` ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT '0'");
}

// Index the board column on the tables that are queried with it in WHERE
// clauses the most. The ALTER will no-op harmlessly on repeat runs.
foreach (array(SCHAN_DBPOSTS, SCHAN_DBBANS, SCHAN_DBKEYWORDS, SCHAN_DBLOGS, SCHAN_DBREPORTS, SCHAN_DBACCOUNTS) as $table) {
	if (mysqli_num_rows(mysqli_query($link, "SHOW INDEX FROM `" . $table . "` WHERE Key_name = 'board'")) == 0) {
		mysqli_query($link, "ALTER TABLE `" . $table . "` ADD INDEX `board` (`board`)");
	}
}

// Per-board post numbering.
//   Each board keeps its own counter (`lastpost` on the boards table) and the
//   posts table is keyed by (board, id) instead of a global auto-increment id.
//   Existing post numbers are kept; every board continues from its highest one.
//   Both steps are guarded and only run once.
if (mysqli_num_rows(mysqli_query($link, "SHOW COLUMNS FROM `" . SCHAN_DBBOARDS . "` LIKE 'lastpost'")) == 0) {
	mysqli_query($link, "ALTER TABLE `" . SCHAN_DBBOARDS . "` ADD COLUMN `lastpost` INT UNSIGNED NOT NULL DEFAULT 0");
	mysqli_query($link, "UPDATE `" . SCHAN_DBBOARDS . "` b SET b.`lastpost` = (SELECT COALESCE(MAX(p.`id`), 0) FROM `" . SCHAN_DBPOSTS . "` p WHERE p.`board` = b.`slug`)");
}
$posts_pk = mysqli_query($link, "SHOW KEYS FROM `" . SCHAN_DBPOSTS . "` WHERE Key_name = 'PRIMARY'");
if ($posts_pk && mysqli_num_rows($posts_pk) == 1) {
	mysqli_query($link, "ALTER TABLE `" . SCHAN_DBPOSTS . "` MODIFY `id` MEDIUMINT(7) UNSIGNED NOT NULL");
	mysqli_query($link, "ALTER TABLE `" . SCHAN_DBPOSTS . "` DROP PRIMARY KEY, ADD PRIMARY KEY (`board`, `id`)");
}

mysqli_query($link, "ALTER TABLE `" . SCHAN_DBPOSTS . "` MODIFY tripcode VARCHAR(24) NOT NULL DEFAULT ''");

mysqli_query($link, "ALTER TABLE `" . SCHAN_DBPOSTS . "` MODIFY ip VARCHAR(255) NOT NULL DEFAULT ''");
mysqli_query($link, "ALTER TABLE `" . SCHAN_DBBANS . "` MODIFY ip VARCHAR(255) NOT NULL DEFAULT ''");

// Database migration helpers (used by the dbmigrate tool)
if (function_exists('insertPost')) {
	function migrateAccount($account) {
		global $link;
		$board = isset($account['board']) ? $account['board'] : '*';
		mysqli_query($link, "INSERT INTO `" . SCHAN_DBACCOUNTS . "` (`id`, `username`, `password`, `role`, `lastactive`, `board`) VALUES ('" . mysqli_real_escape_string($link, $account['id']) . "', '" . mysqli_real_escape_string($link, $account['username']) . "', '" . mysqli_real_escape_string($link, $account['password']) . "', '" . mysqli_real_escape_string($link, $account['role']) . "', '" . mysqli_real_escape_string($link, $account['lastactive']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
	}

	function migrateBan($ban) {
		global $link;
		$board = isset($ban['board']) ? $ban['board'] : '*';
		mysqli_query($link, "INSERT INTO `" . SCHAN_DBBANS . "` (`id`, `ip`, `timestamp`, `expire`, `reason`, `board`) VALUES ('" . mysqli_real_escape_string($link, $ban['id']) . "', '" . mysqli_real_escape_string($link, $ban['ip']) . "', '" . mysqli_real_escape_string($link, $ban['timestamp']) . "', '" . mysqli_real_escape_string($link, $ban['expire']) . "', '" . mysqli_real_escape_string($link, $ban['reason']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
	}

	function migrateKeyword($keyword) {
		global $link;
		$board = isset($keyword['board']) ? $keyword['board'] : '*';
		mysqli_query($link, "INSERT INTO `" . SCHAN_DBKEYWORDS . "` (`id`, `text`, `action`, `board`) VALUES ('" . mysqli_real_escape_string($link, $keyword['id']) . "', '" . mysqli_real_escape_string($link, $keyword['text']) . "', '" . mysqli_real_escape_string($link, $keyword['action']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
	}

	function migrateLog($log) {
		global $link;
		$board = isset($log['board']) ? $log['board'] : '*';
		mysqli_query($link, "INSERT INTO `" . SCHAN_DBLOGS . "` (`id`, `timestamp`, `account`, `message`, `board`) VALUES ('" . mysqli_real_escape_string($link, $log['id']) . "', '" . mysqli_real_escape_string($link, $log['timestamp']) . "', '" . mysqli_real_escape_string($link, $log['account']) . "', '" . mysqli_real_escape_string($link, $log['message']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
	}

	function migratePost($post) {
		global $link;
		$board = isset($post['board']) ? $post['board'] : SCHAN_DEFAULTBOARD;
		mysqli_query($link, "INSERT INTO `" . SCHAN_DBPOSTS . "` (`id`, `parent`, `timestamp`, `bumped`, `ip`, `name`, `tripcode`, `email`, `nameblock`, `subject`, `message`, `password`, `file`, `file_hex`, `file_original`, `file_size`, `file_size_formatted`, `image_width`, `image_height`, `thumb`, `thumb_width`, `thumb_height`, `moderated`, `stickied`, `locked`, `board`) VALUES (" . $post['id'] . ", " . $post['parent'] . ", " . $post['timestamp'] . ", " . $post['bumped'] . ", '" . mysqli_real_escape_string($link, $post['ip']) . "', '" . mysqli_real_escape_string($link, $post['name']) . "', '" . mysqli_real_escape_string($link, $post['tripcode']) . "', '" . mysqli_real_escape_string($link, $post['email']) . "', '" . mysqli_real_escape_string($link, $post['nameblock']) . "', '" . mysqli_real_escape_string($link, $post['subject']) . "', '" . mysqli_real_escape_string($link, $post['message']) . "', '" . mysqli_real_escape_string($link, $post['password']) . "', '" . $post['file'] . "', '" . $post['file_hex'] . "', '" . mysqli_real_escape_string($link, $post['file_original']) . "', " . $post['file_size'] . ", '" . $post['file_size_formatted'] . "', " . $post['image_width'] . ", " . $post['image_height'] . ", '" . $post['thumb'] . "', " . $post['thumb_width'] . ", " . $post['thumb_height'] . ", " . $post['moderated'] . ", " . (isset($post['stickied']) ? $post['stickied'] : 0) . ", " . (isset($post['locked']) ? $post['locked'] : 0) . ", '" . mysqli_real_escape_string($link, $board) . "')");
	}

	function migrateReport($report) {
		global $link;
		$board = isset($report['board']) ? $report['board'] : SCHAN_DEFAULTBOARD;
		mysqli_query($link, "INSERT INTO `" . SCHAN_DBREPORTS . "` (`id`, `ip`, `post`, `board`) VALUES ('" . mysqli_real_escape_string($link, $report['id']) . "', '" . mysqli_real_escape_string($link, $report['ip']) . "', '" . mysqli_real_escape_string($link, $report['post']) . "', '" . mysqli_real_escape_string($link, $board) . "')");
	}
}
