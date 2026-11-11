<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// post rendering (bbcode, links, backlinks, buildPost), loaded by html.php

function _makeLinksClickable($matches) {
	if (!isset($matches[1])) {
		return '';
	}
	$url = cleanQuotes($matches[1]);
	$text = $matches[1];
	return '<a href="' . $url . '" target="_blank">' . $text . '</a>';
}

function makeLinksClickable($text) {
	$text = preg_replace_callback('!(((f|ht)tp(s)?://)[-a-zA-Zа-яА-Я()0-9@%\!_+.,~#?&;:|\'/=]+)!i', '_makeLinksClickable', $text);
	$text = preg_replace('/\(\<a href\=\"(.*)\)"\ target\=\"\_blank\">(.*)\)\<\/a>/i', '(<a href="$1" target="_blank">$2</a>)', $text);
	$text = preg_replace('/\<a href\=\"(.*)\."\ target\=\"\_blank\">(.*)\.\<\/a>/i', '<a href="$1" target="_blank">$2</a>.', $text);
	$text = preg_replace('/\<a href\=\"(.*)\,"\ target\=\"\_blank\">(.*)\,\<\/a>/i', '<a href="$1" target="_blank">$2</a>,', $text);

	return $text;
}

function shortenFilename($name, $max = 10) {
	$name = trim(html_entity_decode((string)$name, ENT_QUOTES, 'UTF-8'));
	if ($name === '') {
		return $name;
	}
	$chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
	if ($chars === false) {
		$chars = str_split($name);
	}
	if (count($chars) <= $max) {
		return $name;
	}
	return '...' . implode('', array_slice($chars, -$max));
}


function backlinks($post) {
	if (!SCHAN_BACKLINKS) {
		return '';
	}

	$posts = postsInThreadByID(getParent($post));
	$needle = '&gt;&gt;' . $post['id'];
	$return = '';
	foreach ($posts as $reply) {
		if (strpos($reply['message'], $needle) !== false) {
			if ($return != '') {
				$return .= ', ';
			}
			$return .= postLink('&gt;&gt;' . $reply['id']);
		}
	}
	if ($return != '') {
		$return = '&nbsp;' . $return;
	}
	return ' <small><span id="backlinks' . $post['id'] . '" class="backlinks">' . $return . '</span></small>';
}

function parseBBCode($text) {
	if (strpos($text, '[') === false) {
		return $text;
	}

	$allowed_colors = array(
		'red','green','blue','yellow','orange','purple','pink','brown',
		'black','white','gray','grey','cyan','magenta','lime','maroon',
		'navy','olive','teal','silver','aqua','fuchsia'
	);

	$text = preg_replace('/\[b\](.*?)\[\/b\]/is', '<b>$1</b>', $text);
	$text = preg_replace('/\[b\](.*?)\[\/\]/is',  '<b>$1</b>', $text);

	$text = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $text);
	$text = preg_replace('/\[u\](.*?)\[\/\]/is',  '<u>$1</u>', $text);

	$text = preg_replace('/\[s\](.*?)\[\/s\]/is', '<s>$1</s>', $text);
	$text = preg_replace('/\[s\](.*?)\[\/\]/is',  '<s>$1</s>', $text);

	$text = preg_replace('/\[spoiler\](.*?)\[\/spoiler\]/is', '<span class="spoiler">$1</span>', $text);
	$text = preg_replace('/\[spoiler\](.*?)\[\/\]/is',        '<span class="spoiler">$1</span>', $text);

	$text = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote class="bbcquote">$1</blockquote>', $text);
	$text = preg_replace('/\[quote\](.*?)\[\/\]/is',      '<blockquote class="bbcquote">$1</blockquote>', $text);

	$text = preg_replace('/\[scroll\](.*?)\[\/scroll\]/is', '<div class="bbscroll">$1</div>', $text);
	$text = preg_replace('/\[scroll\](.*?)\[\/\]/is',       '<div class="bbscroll">$1</div>', $text);

	$color_replace = function ($m) use ($allowed_colors) {
		$c = strtolower($m[1]);
		if (preg_match('/^[a-z]+$/', $c) && !in_array($c, $allowed_colors)) {
			return $m[2];
		}
		return '<span style="color:' . $c . ';">' . $m[2] . '</span>';
	};
	$text = preg_replace_callback('/\[color=([a-z]+|#[0-9a-f]{3,6})\](.*?)\[\/color\]/is', $color_replace, $text);
	$text = preg_replace_callback('/\[color=([a-z]+|#[0-9a-f]{3,6})\](.*?)\[\/\]/is',      $color_replace, $text);

	$size_replace = function ($m) {
		$pct = max(50, min(400, intval($m[1])));
		return '<span style="font-size:' . $pct . '%;">' . $m[2] . '</span>';
	};
	$text = preg_replace_callback('/\[size=(\d{1,4})\](.*?)\[\/size\]/is', $size_replace, $text);
	$text = preg_replace_callback('/\[size=(\d{1,4})\](.*?)\[\/\]/is',      $size_replace, $text);

	return $text;
}

function buildPost($post, $res, $compact=false, $manage=false) {
	$return = "";
	if (function_exists('postEditsApply')) {
		$post = postEditsApply($post);
	}
	$threadid = ($post['parent'] == SCHAN_NEWTHREAD) ? $post['id'] : $post['parent'];
	$prefix = $res ? '../' : '';
	$root = installRootURL();

	$file_board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	// flags only if the extension is on
	if (extensionEnabled('flags') && function_exists('flagsRender')) {
		$post['nameblock'] = flagsRender($post['nameblock'], $file_board);
	}

	$file_prefix = ($file_board === '')
		? ($res ? '../' : '')
		: $root . '/' . $file_board . '/';

	$overboard = !empty($post['overboard']) && $file_board !== '';
	if ($overboard && (!defined('SCHAN_OVERBOARD_TAGS') || SCHAN_OVERBOARD_TAGS)) {
		$post['nameblock'] = '<span class="boardtag">[<a href="' . htmlentities($root . '/' . rawurlencode($file_board) . '/', ENT_QUOTES) . '">' . htmlentities($file_board) . '</a>]</span> ' . $post['nameblock'];
	}
	$delete_cb = '<input type="checkbox" name="delete[]" value="' . ($overboard ? $file_board . ':' : '') . $post['id'] . '"> ';

	if (SCHAN_REPORT) {
		$reflink = '<a href="' . $root . '/imgboard.php?board=' . urlencode(isset($post['board']) ? $post['board'] : current_board()) . '&amp;report=' . $post['id'] . '" title="' . __('Report') . '">R</a> ';
	} else {
		$reflink = '';
	}

	if ($res == SCHAN_RESPAGE) {
		$reflink .= "<a href=\"$threadid.html#{$post['id']}\">No.</a><a href=\"$threadid.html#q{$post['id']}\" onclick=\"return window.schanQuoteReply ? schanQuoteReply('{$post['id']}') : true;\">{$post['id']}</a>";
	} else {
		$reflink .= "<a href=\"{$file_prefix}res/$threadid.html#{$post['id']}\">No.</a><a href=\"{$file_prefix}res/$threadid.html#q{$post['id']}\">{$post['id']}</a>";
	}

	if ($post["stickied"] == 1) {
		$reflink .= ' <img src="' . $root . '/sticky.png" alt="' . __('Stickied') . '" title="' . __('Stickied') . '" width="16" height="16">';
	}

	if ($post["locked"] == 1) {
		$reflink .= ' <img src="' . $root . '/lock.png" alt="' . __('Locked') . '" title="' . __('Locked') . '" width="16" height="16">';
	}

	if (!isset($post["omitted"])) {
		$post["omitted"] = 0;
	}

	$filehtml = '';
	$filesize = '';
	$expandhtml = '';

	$is_spoiler = !empty($post['spoiler']) || (function_exists('spoilerIsPost') && spoilerIsPost($post));

	$direct_link = isEmbed($post["file_hex"]) ? "#" : ($file_prefix . "src/" . $post["file"]);

	if ($post['parent'] == SCHAN_NEWTHREAD && $post["file"] != '') {
		$filesize .= (isEmbed($post['file_hex']) ? __('Embed') : __('File')) . ': ';
	}

	$w = SCHAN_EXPANDWIDTH;
	if (isEmbed($post["file_hex"])) {
		$expandhtml = $post['file'];
	} else if (substr($post['file'], -5) == '.webm' || substr($post['file'], -4) == '.mp4') {
		$dimensions = 'width="500" height="50"';
		if ($post['image_width'] > 0 && $post['image_height'] > 0) {
			$dimensions = 'width="' . $post['image_width'] . '" height="' . $post['image_height'] . '"';
		}
		$expandhtml = <<<EOF
<video $dimensions style="position: static; pointer-events: inherit; display: inline; max-width: {$w}vw; height: auto; max-height: 100%;" controls autoplay loop>
	<source src="$direct_link"></source>
</video>
EOF;
	} else if (isExpandableImageFile($post['file'])) {
		$expandhtml = "<a href=\"$direct_link\" onclick=\"return expandFile(event, '{$post['id']}');\"><img src=\"{$file_prefix}src/{$post["file"]}\" width=\"{$post["image_width"]}\" style=\"min-width: {$post["thumb_width"]}px;min-height: {$post["thumb_height"]}px;max-width: {$w}vw;height: auto;\"></a>";
	} else if (substr($post['file'], -4) === '.swf') {
		$expandhtml = <<<EOF
<object type="application/x-shockwave-flash" data="$direct_link" width="550" height="400">
	<param name="movie" value="$direct_link" />
	<param name="quality" value="high" />
	<param name="wmode" value="opaque" />
	<embed src="$direct_link" type="application/x-shockwave-flash" width="550" height="400" quality="high" wmode="opaque"></embed>
</object>
EOF;
	}

	$thumblink = "<a href=\"$direct_link\" target=\"_blank\"" . ((isEmbed($post["file_hex"]) || isExpandableImageFile($post['file']) || in_array(substr($post['file'], -4), array('webm', '.mp4', '.swf'))) ? " onclick=\"return expandFile(event, '{$post['id']}');\"" : "") . ">";
	$expandhtml = rawurlencode($expandhtml);

	if (isEmbed($post["file_hex"])) {
		$filesize .= "<a href=\"$direct_link\" onclick=\"return expandFile(event, '{$post['id']}');\">{$post['file_original']}</a>&ndash;({$post['file_hex']})";
	} else if ($post["file"] != '') {
		$display_name = $post["file_original"] != "" ? $post["file_original"] : $post["file"];
		$display_name = shortenFilename($display_name, 10);
		$filesize .= $thumblink . htmlentities($display_name) . "</a>&ndash;({$post["file_size_formatted"]}";
		if ($post["image_width"] > 0 && $post["image_height"] > 0) {
			$filesize .= ", " . $post["image_width"] . "x" . $post["image_height"];
		}
		$filesize .= ")";
	}

	if ($filesize != '') {
		$filesize = '<span class="filesize">' . $filesize . '</span>';
	}

	if ($filesize != '') {
		if ($post['parent'] != SCHAN_NEWTHREAD) {
			$filehtml .= '<br>';
		}
		$filehtml .= $filesize . '<br><div id="thumbfile' . $post['id'] . '">';
		if ($post["thumb_width"] > 0 && $post["thumb_height"] > 0) {
			$thumb_dims = ' width="' . $post["thumb_width"] . '" height="' . $post["thumb_height"] . '"';
			$thumb_attr = '';
			if ($is_spoiler && !$manage) {
				list($thumb_src, $thumb_dims) = boardSpoilerImage($file_board, $post);
				$thumb_attr = ' data-spoiler="1"';
			} else {
				$thumb_src = thumbURL($post["thumb"], $file_prefix, $file_board);
			}
			$filehtml .= <<<EOF
$thumblink
	<img src="$thumb_src" alt="{$post["id"]}" class="thumb" id="thumbnail{$post['id']}"$thumb_dims$thumb_attr>
</a>
EOF;
		}
		$filehtml .= '</div>';

		if ($expandhtml != '') {
			$filehtml .= <<<EOF
<div id="expand{$post['id']}" style="display: none;">$expandhtml</div>
<div id="file{$post['id']}" class="thumb" style="display: none;"></div>
EOF;
		}
	}
	if ($post['file'] != '' && function_exists('mediaExtrasHTML')) {
		$filehtml .= mediaExtrasHTML($post, $file_prefix, $manage);
	}
	if ($post['file'] == '' && function_exists('postFileDeletedFor') && postFileDeletedFor($post['id'], isset($post['board']) ? $post['board'] : null)) {
		$filehtml = ($post['parent'] != SCHAN_NEWTHREAD ? '<br>' : '') . deletedPlaceholderHTML($post['id']);
	}
	if (!$manage && $post['file'] != '' && function_exists('postMediaPending') && postMediaPending($post)) {
		$filehtml = ($post['parent'] != SCHAN_NEWTHREAD ? '<br>' : '')
			. '<span class="filesize">' . __('Media pending approval') . '</span><br>'
			. '<div id="thumbfile' . $post['id'] . '">' . boardPendingImageHTML($file_board, 150, 150) . '</div>';
	}

	if ($post['parent'] == SCHAN_NEWTHREAD) {
		$return .= '<div id="post' . $post['id'] . '" class="op">';
		$return .= $filehtml;
	} else {
		if ($compact) {
			$return .= '<div id="' . $post['id'] . '" class="' . ($post['parent'] == SCHAN_NEWTHREAD ? 'op' : 'reply') . '">';
		} else {
			$return .= <<<EOF
<table>
<tbody>
<tr>
<td class="doubledash">
	&#0168;
</td>
<td class="reply" id="post{$post["id"]}">
EOF;
		}
	}

	$boardtag = '';
	if (!empty($post['show_boardtag']) && isset($post['board']) && $post['board'] !== '') {
		$boardtag = '<span class="boardtag">[' . htmlentities($post['board']) . ']</span> ';
	}

	$return .= <<<EOF
<a id="{$post['id']}"></a>
$boardtag<label>
	$delete_cb
EOF;

	if ($post['subject'] != '') {
		$return .= ' <span class="filetitle">' . $post['subject'] . '</span> ';
	}

	$return .= <<<EOF
{$post["nameblock"]}
</label>
<span class="reflink">
	$reflink
</span>
EOF;

	if ($manage && !empty($GLOBALS['isadmin']) && !empty($post['ip']) && function_exists('ipDisplayHTML')) {
		$return .= ' <small class="staffip">[IP: <a href="' . (defined('SCHAN_MODVIEW') ? htmlentities(modBoardURL($file_board), ENT_QUOTES) : '') . '?manage&amp;bans=' . rawurlencode($post['ip']) . '">' . ipDisplayHTML($post['ip']) . '</a>'
			. (function_exists('manageCountryTag') ? manageCountryTag($post['ip']) : '')
			. (banByIP($post['ip']) ? ' <b>(' . __('banned') . ')</b>' : '') . ']</small>';
	}

	if ($post['parent'] != SCHAN_NEWTHREAD) {
		$return .= backlinks($post);
	}

	if ($post['parent'] != SCHAN_NEWTHREAD) {
		$return .= $filehtml;
	}

	if ($post['parent'] == SCHAN_NEWTHREAD) {
		if ($res == SCHAN_INDEXPAGE) {
			$return .= "&nbsp;<a class=\"btnlink\" href=\"" . ($overboard ? $file_prefix : '') . "res/{$post["id"]}.html\">" . __("Reply") . "</a>";
		}
		$return .= backlinks($post);
	}

	if (SCHAN_TRUNCATE > 0 && !$res && _substr_count($post['message'], '<br>') > SCHAN_TRUNCATE) {
		$br_offsets = strallpos($post['message'], '<br>');
		$post['message'] = _substr($post['message'], 0, $br_offsets[SCHAN_TRUNCATE - 1]);
		$post['message'] .= '<br><span class="omittedposts">' . __('Post truncated. Click Reply to view.') . '</span><br>';
	}

	$message_html = $post['message'];
	if (!$manage && function_exists('postLinksPending') && postLinksPending($post)) {
		$message_html = maskPendingLinks($message_html);
	}
	if (function_exists('parseBBCode')) {
		$message_html = parseBBCode($message_html);
	}
	if (function_exists('parseEmoji')) {
		$message_html = parseEmoji($message_html);
	}

	if (function_exists('pollHTML')) {
		$return .= pollHTML($post);
	}

	$return .= <<<EOF
<div class="message">
$message_html
</div>
EOF;
	if (!empty($GLOBALS['schan_modview'])) {
		$return .= manageInlineModeration($post);
	}

	if ($post['parent'] == SCHAN_NEWTHREAD) {
		$return .= '</div>';
		if ($res == SCHAN_INDEXPAGE && $post['omitted'] > 0) {
			if ($post['omitted'] == 1) {
				$return .= '<span class="omittedposts">' . __('1 post omitted. Click Reply to view.') . '</span>';
			} else {
				$return .= '<span class="omittedposts">' . sprintf(__('%d posts omitted. Click Reply to view.'), $post['omitted']) . '</span>';
			}
		}
	} else if ($compact) {
		$return .= '</div>';
	} else {
		$return .= <<<EOF
</td>
</tr>
</tbody>
</table>
EOF;
	}

	return $return;
}
