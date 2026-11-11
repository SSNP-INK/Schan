<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// json output, loaded by html.php

function encodeJSON($array) {
	return json_encode($array, JSON_PRETTY_PRINT);
}

function buildSinglePostJSON($post) {
	if (function_exists('postEditsApply')) {
		$post = postEditsApply($post);
	}
	if (function_exists('postMediaPending') && postMediaPending($post)) {
		foreach (array('file', 'file_hex', 'file_original', 'file_size_formatted', 'thumb') as $k) {
			$post[$k] = '';
		}
		foreach (array('file_size', 'image_width', 'image_height', 'thumb_width', 'thumb_height') as $k) {
			$post[$k] = 0;
		}
	}
	if (function_exists('postLinksPending') && postLinksPending($post)) {
		$post['message'] = maskPendingLinks($post['message']);
	}
	$name = $post['name'];
	if ($name == '') {
		$name = 'Anonymous';
	}

	$output = array('id' => $post['id'], 'board' => isset($post['board']) ? $post['board'] : current_board(), 'parent' => $post['parent'], 'timestamp' => $post['timestamp'], 'bumped' => $post['bumped'], 'name' => $name, 'tripcode' => $post['tripcode'], 'subject' => $post['subject'], 'message' => $post['message'], 'file' => $post['file'], 'file_hex' => $post['file_hex'], 'file_original' => $post['file_original'], 'file_size' => $post['file_size'], 'file_size_formated' => $post['file_size_formatted'], 'image_width' => $post['image_width'], 'image_height' => $post['image_height'], 'thumb' => $post['thumb'], 'thumb_width' => $post['thumb_width'], 'thumb_height' => $post['thumb_height'], 'spoiler' => (!empty($post['spoiler']) || (function_exists('spoilerIsPost') && spoilerIsPost($post))) ? 1 : 0);

	if ($post['parent'] == SCHAN_NEWTHREAD) {
		$replies = count(postsInThreadByID($post['id'])) - 1;
		$images = imagesInThreadByID($post['id']);

		$output = array_merge($output, array('stickied' => $post['stickied'], 'locked' => $post['locked'], 'replies' => $replies, 'images' => $images));
	}

	return $output;
}

function buildIndexJSON() {
	$output = array('board' => current_board(), 'threads' => array());

	$threads = allThreads();
	foreach ($threads as $thread) {
		array_push($output['threads'], array('id' => $thread['id'], 'subject' => $thread['subject'], 'bumped' => $thread['bumped']));
	}

	return encodeJSON($output);
}

function buildCatalogJSON() {
	$output = array('board' => current_board(), 'threads' => array());

	$threads = allThreads();
	foreach ($threads as $post) {
		array_push($output['threads'], buildSinglePostJSON($post));
	}

	return encodeJSON($output);
}

function buildSingleThreadJSON($id) {
	$output = array('board' => current_board(), 'posts' => array());

	$posts = postsInThreadByID($id);
	foreach ($posts as $post) {
		array_push($output['posts'], buildSinglePostJSON($post));
	}

	return encodeJSON($output);
}
