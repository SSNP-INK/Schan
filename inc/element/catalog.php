<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// catalog + file board pages, loaded by html.php

function buildCatalogPost($post) {
	if (function_exists('postEditsApply')) {
		$post = postEditsApply($post);
	}
	$cp = (!empty($post['overboard']) && !empty($post['board'])) ? installRootURL() . '/' . $post['board'] . '/' : '';
	$maxwidth = max(100, $post['thumb_width']);
	$thumb = '#' . $post['id'];

	if ($post['thumb'] != '') {
		if (!empty($post['spoiler']) || (function_exists('spoilerIsPost') && spoilerIsPost($post))) {
			$sp_size = function_exists('spoilerImageSize') ? spoilerImageSize() : array(0, 0);
			$thumb = '<img src="' . installRootURL() . '/spoiler.png" alt="' . $post['id'] . '"' . (function_exists('spoilerImgAttrs') ? spoilerImgAttrs() : '') . ' border="0">';
			if ($sp_size[0] > 0) {
				$maxwidth = max($maxwidth, $sp_size[0]);
			}
		} else {
			$thumb = '<img src="' . $cp . 'thumb/' . $post['thumb'] . '" alt="' . $post['id'] . '" width="' . $post['thumb_width'] . '" height="' . $post['thumb_height'] . '" border="0">';
		}
		if (function_exists('postMediaPending') && postMediaPending($post)) {
			$thumb = pendingImageHTML(150, 150);
			$maxwidth = max($maxwidth, 150);
		}
	}

	$replies = numRepliesToThreadByID($post['id']);
	$cat_tag = ($cp !== '' && (!defined('SCHAN_OVERBOARD_TAGS') || SCHAN_OVERBOARD_TAGS)) ? '<span class="boardtag">[' . htmlentities($post['board']) . ']</span> ' : '';
	$subject = trim($post['subject']) != '' ? $post['subject'] : _substr(trim(str_ireplace("\n", '', strip_tags($post['message']))), 0, 75);

	return <<<EOF
<div class="catalogpost" style="max-width: {$maxwidth}px;">
	<a href="{$cp}res/{$post['id']}.html">
		$thumb
	</a><br>
	<b>$replies</b><br>
	$cat_tag$subject
</div>
EOF;
}

function rebuildCatalog($board = null) {
	if ($board === null) {
		$board = current_board();
	}
	if ($board === '') {
		return;
	}

	$html = '';
	foreach (allThreads() as $post) {
		$html .= buildCatalogPost($post);
	}

	$out = boardOutputDirFor($board, false) . 'catalog.html';
	writePage($out, buildPage($html, -1, 0, 0, 0, $board));
}

function boardOutputDirFor($board, $res = false) {
	if ($board === '') {
		return $res ? 'res/' : '';
	}
	return $board . '/' . ($res ? 'res/' : '');
}

function fileboardRowHTML($thread, $replies, $rownum) {
	global $schan_anonymous;
	$id = intval($thread['id']);
	$res = 'res/' . $id . '.html';

	$name = $thread['name'];
	if ($name == '' && $thread['tripcode'] == '') {
		$name = $schan_anonymous[0];
	}
	$name_html = '<span class="postername">' . $name . '</span>';
	if ($thread['tripcode'] != '') {
		$name_html .= '<span class="postertrip">!' . $thread['tripcode'] . '</span>';
	}

	$file_html = '&nbsp;';
	$size_html = '&nbsp;';
	if ($thread['file'] != '') {
		$label = $thread['file_original'] != '' ? $thread['file_original'] : $thread['file'];
		$label = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $label);
		if (!empty($thread['file_hex']) && isEmbed($thread['file_hex'])) {
			$file_html = '[<a href="' . $res . '">' . $label . '</a>]';
		} else if (function_exists('postMediaPending') && postMediaPending($thread)) {
			$file_html = '[' . __('Media pending approval') . ']';
		} else {
			$file_html = '[<a href="src/' . htmlentities($thread['file'], ENT_QUOTES) . '" target="_blank">' . $label . '</a>]';
			if ($thread['file_size_formatted'] != '') {
				$size_html = $thread['file_size_formatted'];
			}
		}
	}

	$subject = '';
	if (!empty($thread['stickied'])) {
		$subject .= '<b>[' . __('Sticky') . ']</b> ';
	}
	if (!empty($thread['locked'])) {
		$subject .= '<b>[' . __('Locked') . ']</b> ';
	}
	if ($thread['subject'] != '') {
		$subject .= '<span class="filetitle">' . $thread['subject'] . '</span>';
	}
	if ($subject === '') {
		$subject = '&nbsp;';
	}

	$style = ($rownum % 2 == 0) ? '' : ' style="background:rgba(128,128,128,.14);"';
	return '<tr' . $style . '>'
		. '<td><label><input type="checkbox" name="delete[]" value="' . $id . '"> <a href="' . $res . '">' . $id . '</a></label></td>'
		. '<td>' . $name_html . '</td>'
		. '<td style="text-align:center;">' . $file_html . '</td>'
		. '<td>' . $subject . '</td>'
		. '<td style="text-align:right;white-space:nowrap;">' . $size_html . '</td>'
		. '<td style="white-space:nowrap;">' . formatDate($thread['timestamp']) . '</td>'
		. '<td style="text-align:right;">' . intval($replies) . '</td>'
		. '<td><a class="btnlink" href="' . $res . '">' . __('Reply') . '</a></td>'
		. '</tr>' . "\n";
}

function fileboardTable($rows) {
	if ($rows === '') {
		return '';
	}
	return '<table class="fileboard" cellspacing="0" cellpadding="2" style="margin:0 auto;max-width:100%;"><thead><tr>'
		. '<th class="postblock">' . __('No.') . '</th>'
		. '<th class="postblock">' . __('Name') . '</th>'
		. '<th class="postblock">' . __('File') . '</th>'
		. '<th class="postblock">' . __('Subject') . '</th>'
		. '<th class="postblock">' . __('Size') . '</th>'
		. '<th class="postblock">' . __('Date') . '</th>'
		. '<th class="postblock">' . __('Replies') . '</th>'
		. '<th class="postblock">&nbsp;</th>'
		. '</tr></thead><tbody>' . "\n" . $rows . '</tbody></table>';
}
