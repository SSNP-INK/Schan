<?php
/*
 * News highlight box: the thread subjects of every board marked "news" in
 * ?manage&boards, newest first, each with its date.
 *
 * Put this file in the install directory (next to imgboard.php). It reads the news list that
 * Schan saves in its database (settings table, name 'news_cache') whenever a board is rebuilt,
 * using the credentials in settings.php, so it can be dropped into any PHP page:
 *
 *   <?php include '/var/www/schan/imgb/news_highlight.php'; ?>              prints the box
 *
 *   <?php
 *   define('NEWS_HIGHLIGHT_NO_OUTPUT', true);                               just load the function
 *   include '/var/www/schan/imgb/news_highlight.php';
 *   echo news_highlight_html(8);                                            8 items
 *   echo news_highlight_html(5, 'news');                                    only the board /news/
 *   echo news_highlight_html(5, array('news', 'updates'), 'Site news');     several boards, own title
 *   ?>
 *
 * Colours can be changed from the page's own CSS, e.g.
 *   .news-highlight { --nh-accent: #ccaa88; --nh-link: #800000; }
 */

if (!function_exists('news_highlight_html')) {
	function news_highlight_html($limit = 5, $boards = null, $title = 'News') {
		static $styled = false;
		$h = function ($s) {
			return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
		};

		$items = array();
		$data = null;
		if (!defined('SCHAN_DBHOST') && is_file(__DIR__ . '/settings.php')) {
			require_once __DIR__ . '/settings.php';
		}
		if (defined('SCHAN_DBHOST') && function_exists('mysqli_connect')) {
			$nh_link = @mysqli_connect(SCHAN_DBHOST, SCHAN_DBUSERNAME, SCHAN_DBPASSWORD, SCHAN_DBNAME);
			if ($nh_link) {
				mysqli_set_charset($nh_link, 'utf8mb4');
				$nh_res = @mysqli_query($nh_link, "SELECT `value` FROM `" . SCHAN_DBPOSTS . "_settings` WHERE `name` = 'news_cache' LIMIT 1");
				$nh_row = $nh_res ? mysqli_fetch_assoc($nh_res) : null;
				if ($nh_row) {
					$data = json_decode($nh_row['value'], true);
				}
				mysqli_close($nh_link);
			}
		}
		if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
			$items = $data['items'];
		}
		if ($boards !== null) {
			$wanted = is_array($boards) ? $boards : array($boards);
			$items = array_values(array_filter($items, function ($i) use ($wanted) {
				return isset($i['board']) && in_array($i['board'], $wanted, true);
			}));
		}
		$items = array_slice($items, 0, max(1, (int)$limit));

		$out = '';
		if (!$styled) {
			$styled = true;
			$out .= '<style>'
				. '.news-highlight{--nh-accent:#9988ee;--nh-border:#b7c5d9;--nh-link:#34345c;--nh-text:#000;--nh-bg:#fff;'
				. 'box-sizing:border-box;width:100%;max-width:700px;margin:8px auto;border:1px solid #000;background:var(--nh-bg);color:var(--nh-text);font-family:Arial,Helvetica,sans-serif;}'
				. '.news-highlight-title{background:var(--nh-accent);color:#000;font-weight:bold;font-size:17px;padding:4px 8px;border-bottom:1px solid #000;}'
				. '.news-highlight-row{font-size:13.3px;padding:3px 8px;border-bottom:1px solid var(--nh-border);}'
				. '.news-highlight-row:last-child{border-bottom:0;}'
				. '.news-highlight-row a{color:var(--nh-link);text-decoration:underline;}'
				. '.news-highlight-row a:hover{text-decoration:none;}'
				. '</style>';
		}
		$out .= '<div class="news-highlight"><div class="news-highlight-title">' . $h($title) . '</div>';
		if (empty($items)) {
			$out .= '<div class="news-highlight-row">No news yet.</div>';
		}
		foreach ($items as $i) {
			$out .= '<div class="news-highlight-row">' . $h(isset($i['date']) ? $i['date'] : '') . ': '
				. '<a href="' . $h(isset($i['url']) ? $i['url'] : '#') . '">' . $h(isset($i['subject']) ? $i['subject'] : '') . '</a></div>';
		}
		return $out . '</div>';
	}
}

if (!defined('NEWS_HIGHLIGHT_NO_OUTPUT')) {
	$nh_limit = isset($news_highlight_limit) ? (int)$news_highlight_limit : 5;
	if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
		// Opened directly in the browser: wrap the box in a minimal page.
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>News</title></head><body>'
			. news_highlight_html($nh_limit) . '</body></html>';
	} else {
		echo news_highlight_html($nh_limit);
	}
}
