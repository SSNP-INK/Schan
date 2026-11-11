<?php
require_once __DIR__ . '/storage.php';
/*
 * multiple files per post (up to SCHAN_MAXFILES, default 4)
 *
 * - the first file stays in the normal post columns so thumbnails, catalog, moderation etc keep working
 * - files 2-4 are described in the database (<posts>_media table, one row per file)
 */

if (!defined('SCHAN_MAXFILES')) {
	define('SCHAN_MAXFILES', 4);
}
// storage: extra files live in the database (<posts>_media), one row per file, keyed by board + post id + position

function mediaStoreWritable() {
	return true;
}

function mediaKey($board, $id) {
	return $board . ':' . intval($id);
}

function mediaExtrasFor($board, $id, $reset = false) {
	static $cache = array();
	$key = mediaKey($board, $id);
	if ($reset) {
		unset($cache[$key]);
	}
	if (!isset($cache[$key])) {
		$cache[$key] = array();
		$rows = storeRows("SELECT * FROM `" . storeTable('media') . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id) . " ORDER BY `position` ASC");
		foreach ($rows as $r) {
			$cache[$key][] = array(
				'file' => $r['file'], 'orig' => $r['orig'], 'hex' => $r['file_hex'],
				'size' => $r['size_text'], 'w' => (int)$r['w'], 'h' => (int)$r['h'],
				'thumb' => $r['thumb'], 'tw' => (int)$r['tw'], 'th' => (int)$r['th'],
				'spoiler' => (int)$r['spoiler'],
			);
		}
	}
	return $cache[$key];
}

function mediaSaveExtras($board, $id, $records) {
	storeEnsure();
	$table = storeTable('media');
	storeQuery("DELETE FROM `" . $table . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id));
	$ok = true;
	$pos = 0;
	foreach ($records as $r) {
		$pos++;
		$ok = (bool)storeQuery("INSERT INTO `" . $table . "` (`board`, `post_id`, `position`, `file`, `orig`, `file_hex`, `size_text`, `w`, `h`, `thumb`, `tw`, `th`, `spoiler`) VALUES ('" . storeEsc($board) . "', " . intval($id) . ", " . $pos . ", '" . storeEsc(isset($r['file']) ? $r['file'] : '') . "', '" . storeEsc(isset($r['orig']) ? $r['orig'] : '') . "', '" . storeEsc(isset($r['hex']) ? $r['hex'] : '') . "', '" . storeEsc(isset($r['size']) ? $r['size'] : '') . "', " . intval(isset($r['w']) ? $r['w'] : 0) . ", " . intval(isset($r['h']) ? $r['h'] : 0) . ", '" . storeEsc(isset($r['thumb']) ? $r['thumb'] : '') . "', " . intval(isset($r['tw']) ? $r['tw'] : 0) . ", " . intval(isset($r['th']) ? $r['th'] : 0) . ", " . (!empty($r['spoiler']) ? 1 : 0) . ")") && $ok;
	}
	mediaExtrasFor($board, $id, true);
	return $ok;
}

// deletes the extra files of a post and forgets them. Called from deletePostImages()
function mediaDeleteExtras($board, $id) {
	$records = mediaExtrasFor($board, $id);
	if (empty($records)) {
		return;
	}
	foreach ($records as $r) {
		if (!empty($r['file'])) {
			@unlink($board . '/src/' . basename($r['file']));
		}
		if (!empty($r['thumb'])) {
			@unlink($board . '/thumb/' . basename($r['thumb']));
		}
	}
	storeExec("DELETE FROM `" . storeTable('media') . "` WHERE `board` = '" . storeEsc($board) . "' AND `post_id` = " . intval($id));
	mediaExtrasFor($board, $id, true);
}

// posting

// makes $_FILES['file'] the first selected file, returns the rest as an array of single-file arrays
// the form sends all files in one field: <input type="file" name="files[]" multiple>
function mediaCollectUploads() {
	$found = array();
	if (isset($_FILES['file']) && is_array($_FILES['file']) && isset($_FILES['file']['name']) && is_string($_FILES['file']['name']) && $_FILES['file']['name'] !== '') {
		$found[] = $_FILES['file'];
	}
	if (isset($_FILES['files']) && is_array($_FILES['files']) && isset($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
		foreach (array_keys($_FILES['files']['name']) as $i) {
			$name = $_FILES['files']['name'][$i];
			if (!is_string($name) || $name === '') {
				continue;
			}
			$found[] = array(
				'name' => $name,
				'type' => isset($_FILES['files']['type'][$i]) ? $_FILES['files']['type'][$i] : '',
				'tmp_name' => isset($_FILES['files']['tmp_name'][$i]) ? $_FILES['files']['tmp_name'][$i] : '',
				'error' => isset($_FILES['files']['error'][$i]) ? $_FILES['files']['error'][$i] : UPLOAD_ERR_NO_FILE,
				'size' => isset($_FILES['files']['size'][$i]) ? $_FILES['files']['size'][$i] : 0,
			);
		}
	}
	unset($_FILES['files']);
	if (count($found) > SCHAN_MAXFILES) {
		fancyDie(sprintf(__('You can attach up to %d files.'), SCHAN_MAXFILES));
	}
	if (empty($found)) {
		return array();
	}
	$_FILES['file'] = array_shift($found);
	return $found;
}

function mediaTrackFile($path) {
	if (!isset($GLOBALS['schan_upload_cleanup'])) {
		$GLOBALS['schan_upload_cleanup'] = array();
		$GLOBALS['schan_upload_committed'] = false;
		register_shutdown_function(function () {
			if (empty($GLOBALS['schan_upload_committed'])) {
				foreach ($GLOBALS['schan_upload_cleanup'] as $p) {
					@unlink($p);
				}
			}
		});
	}
	$GLOBALS['schan_upload_cleanup'][] = $path;
}

// called once the post row exists: files are kept from now on
function mediaCommit() {
	$GLOBALS['schan_upload_committed'] = true;
}

function mediaCheckUpload($f) {
	switch ($f['error']) {
		case UPLOAD_ERR_OK:
			return;
		case UPLOAD_ERR_FORM_SIZE:
			fancyDie(sprintf(__('That file is larger than %s.'), SCHAN_MAXKBDESC));
		case UPLOAD_ERR_INI_SIZE:
			fancyDie(sprintf(__('The uploaded file exceeds the upload_max_filesize directive (%s) in php.ini.'), ini_get('upload_max_filesize')));
		case UPLOAD_ERR_PARTIAL:
			fancyDie(__('The uploaded file was only partially uploaded.'));
		default:
			fancyDie(__('Unable to save the uploaded file.'));
	}
}

// validates and stores the extra uploads. Returns an array of records
function mediaAttachExtras($post, $uploads, $spoiler) {
	if (empty($uploads)) {
		return array();
	}
	$board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	$records = array();
	// which files had their Spoiler box ticked (0 = main file, 1 = first extra file, ...)
	$spoiler_flags = function_exists('spoilerRequestedIndexes') ? spoilerRequestedIndexes() : array();
	$position = 0;
	foreach (array_slice($uploads, 0, SCHAN_MAXFILES - 1) as $f) {
		$position++;
		mediaCheckUpload($f);
		$tmp = array(
			'parent' => $post['parent'], 'board' => $board,
			'file' => '', 'file_original' => '', 'file_hex' => '', 'file_size' => 0, 'file_size_formatted' => '',
			'image_width' => 0, 'image_height' => 0, 'thumb' => '', 'thumb_width' => 0, 'thumb_height' => 0,
		);
		$tmp = attachFile($tmp, $f['tmp_name'], $f['name'], true, $spoiler);
		mediaTrackFile($board . '/src/' . $tmp['file']);
		if ($tmp['thumb'] != '') {
			mediaTrackFile($board . '/thumb/' . $tmp['thumb']);
		}
		$records[] = array(
			'file' => $tmp['file'], 'orig' => $tmp['file_original'], 'hex' => $tmp['file_hex'],
			'size' => $tmp['file_size_formatted'], 'w' => (int)$tmp['image_width'], 'h' => (int)$tmp['image_height'],
			'thumb' => $tmp['thumb'], 'tw' => (int)$tmp['thumb_width'], 'th' => (int)$tmp['thumb_height'],
			'spoiler' => in_array($position, $spoiler_flags, true) ? 1 : 0,
		);
	}
	return $records;
}

// rendering

function mediaExtrasHTML($post, $file_prefix, $manage = false) {
	$board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	$records = mediaExtrasFor($board, $post['id']);
	if (empty($records)) {
		return '';
	}
	$out = '<div class="extramedia" style="margin:4px 0;">';
	foreach ($records as $r) {
		$file = basename($r['file']);
		$href = htmlentities($file_prefix . 'src/' . $file, ENT_QUOTES);
		// show the original file name, shortened to "...last10chars" when it is longer than 10 characters
		$shown_name = ($r['orig'] != '') ? $r['orig'] : $file;
		if (function_exists('shortenFilename')) {
			$shown_name = shortenFilename($shown_name, 10);
		}
		$info = htmlentities($shown_name, ENT_QUOTES) . '&ndash;(' . htmlentities($r['size'], ENT_QUOTES);
		if ($r['w'] > 0 && $r['h'] > 0) {
			$info .= ', ' . intval($r['w']) . 'x' . intval($r['h']);
		}
		$info .= ')';
		$is_image = in_array(strtolower(substr($file, -4)), array('.jpg', '.png', '.gif'));
		$out .= '<div style="display:inline-block;vertical-align:top;margin:0 10px 8px 0;max-width:100%;"><span class="filesize"><a href="' . $href . '" target="_blank">' . $info . '</a></span><br>';
		if ($r['thumb'] != '' && $r['tw'] > 0 && $r['th'] > 0) {
			// spoilered files show /spoiler.png on public pages; staff ($manage) see the real thumbnail
			$thumb = htmlentities($file_prefix . 'thumb/' . basename($r['thumb']), ENT_QUOTES);
			$dims_attr = ' width="' . intval($r['tw']) . '" height="' . intval($r['th']) . '"';
			$spoil_attr = '';
			if (!empty($r['spoiler']) && !$manage) {
				$thumb = htmlentities(installRootURL() . '/spoiler.png', ENT_QUOTES);
				$dims_attr = function_exists('spoilerImgAttrs') ? spoilerImgAttrs() : ''; // always the image's own size
				$spoil_attr = ' data-spoiler="1"';
			}
			if ($is_image) {
				// click toggles between thumbnail and full size
				$out .= '<a href="' . $href . '" target="_blank" data-full="' . $href . '" data-thumb="' . $thumb . '" onclick="var i=this.firstChild,f=this.getAttribute(\'data-full\');if(this.getAttribute(\'data-open\')){i.src=this.getAttribute(\'data-thumb\');i.style.maxWidth=\'\';this.removeAttribute(\'data-open\');}else{i.src=f;i.style.maxWidth=\'' . intval(SCHAN_EXPANDWIDTH) . 'vw\';i.removeAttribute(\'width\');i.removeAttribute(\'height\');this.setAttribute(\'data-open\',1);}return false;">'
					. '<img src="' . $thumb . '" class="thumb" alt=""' . $spoil_attr . $dims_attr . '></a>';
			} else {
				$out .= '<a href="' . $href . '" target="_blank"><img src="' . $thumb . '" class="thumb" alt=""' . $spoil_attr . $dims_attr . '></a>';
			}
		}
		$out .= '</div>';
	}
	return $out . '</div>';
}
