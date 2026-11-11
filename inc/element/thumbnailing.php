<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// thumbnail code, loaded by inc/functions.php (lives in inc/element/)
//
//   thumbnailDimensions()      thumb size for a post
//   createThumbnail()          GD / ImageMagick / ffmpeg thumb of an image
//   fastimagecopyresampled()   resize helper for createThumbnail()
//   videoDimensions(), videoDuration(), ffmpegThumbnail()   ffmpeg / ffprobe helpers
//   addVideoOverlay()          play button (video_overlay.png) on a thumb
//   createPostThumbnail()      builds the right thumb for an upload, attachFile() calls it
//
// thumbs go in <board>/thumb/, older ones in the shared static/thumb/ are still found
//   thumbWritePath()         where a thumb is written: <board>/thumb/<name>
//   thumbPath()              where it's found: <board>/thumb/<name>, else static/thumb/<name>
//   thumbURL()               thumb address for pages
//   thumbExisting()          path of a valid thumb already on disk, else '' (createPostThumbnail reuses it)
//   boardOverridePath()      board's own copy of an image (pending.png, spoiler.png, video_overlay.png,
//                            placeholder icons) or '' if it has none
//   boardPendingImageHTML()  "media pending approval" image for a board
//   boardSpoilerImage()      spoiler image for a board

// <board>/thumb/<name>
function thumbDir($board = null) {
	if ($board === null) {
		$board = current_board();
	}
	return ($board === '' ? '' : $board . '/') . 'thumb/';
}

function thumbEnsureDir($board = null) {
	$dir = thumbDir($board);
	if (!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
}

function thumbWritePath($name, $board = null) {
	return thumbDir($board) . basename($name);
}

// old thumbs from the shared static/thumb/ folder
function thumbLegacyDir() {
	return 'static/thumb/';
}

function thumbPath($board, $name) {
	$own = thumbWritePath($name, $board);
	if (is_file($own)) {
		return $own;
	}
	$old = thumbLegacyDir() . basename($name);
	return is_file($old) ? $old : $own;
}

function thumbURL($name, $file_prefix, $board = null) {
	if ($board === null) {
		// board comes from the page prefix (<root>/<board>/), else current board
		$root = installRootURL() . '/';
		$board = (strpos($file_prefix, $root) === 0) ? trim(substr($file_prefix, strlen($root)), '/') : current_board();
	}
	if (!is_file(thumbWritePath($name, $board)) && is_file(thumbLegacyDir() . basename($name))) {
		return installRootURL() . '/' . thumbLegacyDir() . basename($name);
	}
	return $file_prefix . 'thumb/' . $name;
}

function thumbExisting($board, $name, $is_image = true) {
	$path = thumbPath($board, $name);
	if (!is_file($path)) {
		return '';
	}
	if (!$is_image) {
		return filesize($path) > 0 ? $path : '';
	}
	$info = @getimagesize($path);
	return ($info && $info[0] > 0 && $info[1] > 0) ? $path : '';
}

// per-board image overrides
// a board can keep its own copy of these next to its index.html (like <board>/thumb/)
// each is checked separately, missing or unreadable ones fall back to the site image, no errors
//   pending.png        instead of media waiting for approval
//   spoiler.png        instead of a spoilered thumb
//   video_overlay.png  play button on video thumbs (new thumbs only)
//   <placeholder>      copy of a placeholder from $schan_uploads, by file name (e.g. swf.png)

// full path of the board's own copy of an image, '' if it has none or it isn't a valid image
function boardOverridePath($board, $name) {
	if ($board === '' || $board === null || $name === '') {
		return '';
	}
	$path = dirname(__DIR__, 2) . '/' . basename($board) . '/' . basename($name);
	if (!is_file($path)) {
		return '';
	}
	$info = @getimagesize($path);
	return ($info && $info[0] > 0 && $info[1] > 0) ? $path : '';
}

// url of the board's own copy, '' if none
function boardOverrideURL($board, $name) {
	$path = boardOverridePath($board, $name);
	if ($path === '') {
		return '';
	}
	return installRootURL() . '/' . rawurlencode(basename($board)) . '/' . rawurlencode(basename($name)) . '?v=' . filemtime($path);
}

// width/height of the board's image, scaled down to fit $max_w x $max_h (never enlarged)
function boardOverrideSize($path, $max_w, $max_h) {
	$info = @getimagesize($path);
	if (!$info || $info[0] <= 0 || $info[1] <= 0) {
		return array(0, 0);
	}
	$scale = min($max_w / $info[0], $max_h / $info[1], 1);
	return array(max(1, (int)round($info[0] * $scale)), max(1, (int)round($info[1] * $scale)));
}

function boardPendingImagePath($board) {
	return boardOverridePath($board, 'pending.png');
}

function boardPendingImageHTML($board, $max_w = 150, $max_h = 150) {
	$path = boardPendingImagePath($board);
	if ($path !== '') {
		list($w, $h) = boardOverrideSize($path, $max_w, $max_h);
		if ($w > 0) {
			return '<img src="' . htmlentities(boardOverrideURL($board, 'pending.png'), ENT_QUOTES) . '" alt="' . htmlentities(__('Media pending approval'), ENT_QUOTES) . '" class="thumb" width="' . $w . '" height="' . $h . '">';
		}
	}
	return function_exists('pendingImageHTML') ? pendingImageHTML($max_w, $max_h) : '';
}

// spoiler image, returns array(src, size attributes)
// board's spoiler.png if it has one, else the site's
function boardSpoilerImage($board, $post) {
	$path = boardOverridePath($board, 'spoiler.png');
	if ($path !== '') {
		list($max_w, $max_h) = thumbnailDimensions($post);
		list($w, $h) = boardOverrideSize($path, $max_w, $max_h);
		if ($w > 0) {
			return array(boardOverrideURL($board, 'spoiler.png'), ' width="' . $w . '" height="' . $h . '"');
		}
	}
	return array(installRootURL() . '/spoiler.png', function_exists('spoilerImgAttrs') ? spoilerImgAttrs() : '');
}

function thumbnailDimensions($post) {
	if ($post['parent'] == SCHAN_NEWTHREAD) {
		$max_width = SCHAN_MAXWOP;
		$max_height = SCHAN_MAXHOP;
	} else {
		$max_width = SCHAN_MAXW;
		$max_height = SCHAN_MAXH;
	}
	return ($post['image_width'] > $max_width || $post['image_height'] > $max_height) ? array($max_width, $max_height) : array($post['image_width'], $post['image_height']);
}

function videoDimensions($file_location) {
	$discard = '';
	$exit_status = 1;
	exec("ffprobe -version", $discard, $exit_status);
	if ($exit_status != 0) {
		fancyDie('FFMPEG is not installed, or the commands ffmpeg and ffprobe are not in the server\'s $PATH.<br>Install FFMPEG, or set SCHAN_THUMBNAIL to \'gd\' or \'imagemagick\'.');
	}

	$dimensions = '';
	$exit_status = 1;
	exec("ffprobe -hide_banner -loglevel error -of csv=p=0 -select_streams v -show_entries stream=width,height " . escapeshellarg($file_location), $dimensions, $exit_status);
	if ($exit_status != 0) {
		return array(0, 0);
	}
	if (is_array($dimensions)) {
		$dimensions = $dimensions[0];
	}
	$split = explode(',', $dimensions);
	if (count($split) != 2) {
		return array(0, 0);
	}
	return array(intval($split[0]), intval($split[1]));
}

function videoDuration($file_location) {
	$discard = '';
	$exit_status = 1;
	exec("ffprobe -version", $discard, $exit_status);
	if ($exit_status != 0) {
		fancyDie('FFMPEG is not installed, or the commands ffmpeg and ffprobe are not in the server\'s $PATH.<br>Install FFMPEG, or set SCHAN_THUMBNAIL to \'gd\' or \'imagemagick\'.');
	}

	$duration = '';
	$exit_status = 1;
	exec("ffprobe -hide_banner -loglevel error -of csv=p=0 -show_entries format=duration " . escapeshellarg($file_location), $duration, $exit_status);
	if ($exit_status != 0) {
		return 0;
	}
	if (is_array($duration)) {
		$duration = $duration[0];
	}
	return floatval($duration);
}

function ffmpegThumbnail($file_location, $thumb_location, $new_w, $new_h) {
	$discard = '';
	$exit_status = 1;
	exec("ffmpeg -version", $discard, $exit_status);
	if ($exit_status != 0) {
		fancyDie('FFMPEG is not installed, or the commands ffmpeg and ffprobe are not in the server\'s $PATH.<br>Install FFMPEG, or set SCHAN_THUMBNAIL to \'gd\' or \'imagemagick\'.');
	}

	$quarter = videoDuration($file_location) / 4;

	$exit_status = 1;
	$quarter = escapeshellarg($quarter);
	$new_w = (int)$new_w;
	$new_h = (int)$new_h;
	exec("ffmpeg -hide_banner -loglevel error -ss $quarter -i " . escapeshellarg($file_location) . " -frames:v 1 -vf scale=w=$new_w:h=$new_h:force_original_aspect_ratio=decrease " . escapeshellarg($thumb_location), $discard, $exit_status);
	if ($exit_status != 0) {
		return false;
	}
}

function createThumbnail($file_location, $thumb_location, $new_w, $new_h, $spoiler) {
	$system = explode(".", $thumb_location);
	$system = array_reverse($system);
	if (SCHAN_THUMBNAIL == 'gd' || (SCHAN_THUMBNAIL == 'ffmpeg' && preg_match("/jpg|jpeg/", $system[0]))) {
		if (preg_match("/jpg|jpeg/", $system[0])) {
			$src_img = imagecreatefromjpeg($file_location);
		} else if (preg_match("/png/", $system[0])) {
			$src_img = imagecreatefrompng($file_location);
		} else if (preg_match("/gif/", $system[0])) {
			$src_img = imagecreatefromgif($file_location);
		} else if (preg_match("/webp/", $system[0])) {
			if (!function_exists('imagecreatefromwebp')) {
				fancyDie('PHP GD was built without WebP support.<br>Install/enable GD with WebP, or set SCHAN_THUMBNAIL to \'imagemagick\'.');
			}
			$src_img = @imagecreatefromwebp($file_location);
		} else {
			return false;
		}

		if (!$src_img) {
			fancyDie(__('Unable to read the uploaded file while creating its thumbnail. A common cause for this is an incorrect extension when the file is actually of a different type.'));
		}

		$old_x = imageSX($src_img);
		$old_y = imageSY($src_img);
		$percent = ($old_x > $old_y) ? ($new_w / $old_x) : ($new_h / $old_y);
		$thumb_w = round($old_x * $percent);
		$thumb_h = round($old_y * $percent);

		$dst_img = imagecreatetruecolor($thumb_w, $thumb_h);
		if (preg_match("/webp/", $system[0])) {
			imagealphablending($dst_img, false);
			imagesavealpha($dst_img, true);
			imagefill($dst_img, 0, 0, imagecolorallocatealpha($dst_img, 0, 0, 0, 127));
			imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_x, $old_y);
		} else if (preg_match("/png/", $system[0])) {
			imagealphablending($dst_img, false);
			imagesavealpha($dst_img, true);

			$color = imagecolorallocatealpha($dst_img, 0, 0, 0, 0);
			imagefilledrectangle($dst_img, 0, 0, $thumb_w, $thumb_h, $color);
			imagecolortransparent($dst_img, $color);

			imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_x, $old_y);
		} else {
			fastimagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_x, $old_y);
		}

		if (preg_match("/png/", $system[0])) {
			if (!imagepng($dst_img, $thumb_location)) {
				return false;
			}
		} else if (preg_match("/jpg|jpeg/", $system[0])) {
			if (!imagejpeg($dst_img, $thumb_location, 70)) {
				return false;
			}
		} else if (preg_match("/gif/", $system[0])) {
			if (!imagegif($dst_img, $thumb_location)) {
				return false;
			}
		} else if (preg_match("/webp/", $system[0])) {
			if (!imagewebp($dst_img, $thumb_location, 80)) {
				return false;
			}
		}

		if (PHP_VERSION_ID < 80000) {
			imagedestroy($dst_img);
		}
		if (PHP_VERSION_ID < 80000) {
			imagedestroy($src_img);
		}
	} else if (SCHAN_THUMBNAIL == 'ffmpeg') {
		ffmpegThumbnail($file_location, $thumb_location, $new_w, $new_h);
	} else { // ImageMagick
		$discard = '';

		$exit_status = 1;
		exec("convert -version", $discard, $exit_status);
		if ($exit_status != 0) {
			fancyDie('ImageMagick is not installed, or the convert command is not in the server\'s $PATH.<br>Install ImageMagick, or set SCHAN_THUMBNAIL to \'gd\' or \'ffmpeg\'.');
		}

		$exit_status = 1;
		exec("convert " . escapeshellarg($file_location) . " -auto-orient -thumbnail " . escapeshellarg(((int)$new_w) . "x" . ((int)$new_h)) . " -coalesce -layers OptimizeFrame -depth 4 -type palettealpha " . escapeshellarg($thumb_location), $discard, $exit_status);

		if ($exit_status != 0) {
			return false;
		}
	}

	if (!$spoiler) {
		return true;
	}

	if (preg_match("/jpg|jpeg/", $system[0])) {
		$src_img = imagecreatefromjpeg($thumb_location);
	} else if (preg_match("/png/", $system[0])) {
		$src_img = imagecreatefrompng($thumb_location);
	} else if (preg_match("/gif/", $system[0])) {
		$src_img = imagecreatefromgif($thumb_location);
	} else if (preg_match("/webp/", $system[0]) && function_exists('imagecreatefromwebp')) {
		$src_img = @imagecreatefromwebp($thumb_location);
	} else {
		return true;
	}

	if (!$src_img) {
		fancyDie(__('Unable to read the uploaded file while creating its thumbnail. A common cause for this is an incorrect extension when the file is actually of a different type.'));
	}

	$gaussian = array(array(1.0, 2.0, 1.0), array(2.0, 4.0, 2.0), array(1.0, 2.0, 1.0));
	for ($x = 1; $x <= 149; $x++) {
		imageconvolution($src_img, $gaussian, 16, 0);
	}

	if (preg_match("/png/", $system[0])) {
		if (!imagepng($src_img, $thumb_location)) {
			return false;
		}
	} else if (preg_match("/jpg|jpeg/", $system[0])) {
		if (!imagejpeg($src_img, $thumb_location, 70)) {
			return false;
		}
	} else if (preg_match("/gif/", $system[0])) {
		if (!imagegif($src_img, $thumb_location)) {
			return false;
		}
	} else if (preg_match("/webp/", $system[0])) {
		if (!imagewebp($src_img, $thumb_location, 80)) {
			return false;
		}
	}
	if (PHP_VERSION_ID < 80000) {
		imagedestroy($src_img);
	}
	return true;
}

function fastimagecopyresampled(&$dst_image, &$src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h, $quality = 3) {
	// Author: Tim Eckel - Date: 12/17/04 - Project: FreeRingers.net - Freely distributable.
	if (empty($src_image) || empty($dst_image)) {
		return false;
	}

	if ($quality <= 1) {
		$temp = imagecreatetruecolor($dst_w + 1, $dst_h + 1);

		imagecopyresized($temp, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w + 1, $dst_h + 1, $src_w, $src_h);
		imagecopyresized($dst_image, $temp, 0, 0, 0, 0, $dst_w, $dst_h, $dst_w, $dst_h);
		if (PHP_VERSION_ID < 80000) {
			imagedestroy($temp);
		}
	} elseif ($quality < 5 && (($dst_w * $quality) < $src_w || ($dst_h * $quality) < $src_h)) {
		$tmp_w = $dst_w * $quality;
		$tmp_h = $dst_h * $quality;
		$temp = imagecreatetruecolor($tmp_w + 1, $tmp_h + 1);

		imagecopyresized($temp, $src_image, $dst_x * $quality, $dst_y * $quality, $src_x, $src_y, $tmp_w + 1, $tmp_h + 1, $src_w, $src_h);
		imagecopyresampled($dst_image, $temp, 0, 0, 0, 0, $dst_w, $dst_h, $tmp_w, $tmp_h);
		if (PHP_VERSION_ID < 80000) {
			imagedestroy($temp);
		}
	} else {
		imagecopyresampled($dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);
	}

	return true;
}

function addVideoOverlay($thumb_location, $board = '') {
	// board's video_overlay.png, else the site's
	$overlay_file = boardOverridePath($board, 'video_overlay.png');
	if ($overlay_file === '') {
		$overlay_file = 'video_overlay.png';
	}
	if (!file_exists($overlay_file)) {
		return;
	}

	if (SCHAN_THUMBNAIL == 'gd' || SCHAN_THUMBNAIL == 'ffmpeg') {
		if (substr($thumb_location, -4) == ".jpg") {
			$thumbnail = imagecreatefromjpeg($thumb_location);
		} else {
			$thumbnail = imagecreatefrompng($thumb_location);
		}
		list($width, $height, $type, $attr) = getimagesize($thumb_location);

		$overlay_play = imagecreatefrompng($overlay_file);
		imagealphablending($overlay_play, false);
		imagesavealpha($overlay_play, true);
		list($overlay_width, $overlay_height, $overlay_type, $overlay_attr) = getimagesize($overlay_file);

		if (substr($thumb_location, -4) == ".png") {
			imagecolortransparent($thumbnail, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
			imagealphablending($thumbnail, true);
			imagesavealpha($thumbnail, true);
		}

		imagecopy($thumbnail, $overlay_play, ($width / 2) - ($overlay_width / 2), ($height / 2) - ($overlay_height / 2), 0, 0, $overlay_width, $overlay_height);

		if (substr($thumb_location, -4) == ".jpg") {
			imagejpeg($thumbnail, $thumb_location);
		} else {
			imagepng($thumbnail, $thumb_location);
		}
	} else { // imagemagick
		$discard = '';
		$exit_status = 1;
		exec("convert $thumb_location " . escapeshellarg($overlay_file) . " -gravity center -composite -quality 75 $thumb_location", $discard, $exit_status);
	}
}

// builds the thumb for an upload and fills in $post['thumb'], ['thumb_width'], ['thumb_height']
// attachFile() calls it once the file is in <board>/src/
//   $file_mime      detected mime type
//   $file_src       path of the stored file
//   $file_name_pre  unique name without extension
//   $board          board slug
// written to <board>/thumb/. a valid thumb with that name already there gets reused, unless it's a spoiler
// only webm / mp4 get the play button. swf thumbs are just the placeholder from $schan_uploads
function createPostThumbnail($post, $file_mime, $file_src, $file_name_pre, $board, $spoiler) {
	global $schan_uploads;

	thumbEnsureDir($board);

	if (isset($schan_uploads[$file_mime][1])) {
		$thumbfile_split = explode('.', $schan_uploads[$file_mime][1]);
		$post['thumb'] = $file_name_pre . 's.' . array_pop($thumbfile_split);
		// board's own placeholder (same file name) wins
		$placeholder = boardOverridePath($board, basename($schan_uploads[$file_mime][1]));
		if ($placeholder === '') {
			$placeholder = $schan_uploads[$file_mime][1];
		}
		if (thumbExisting($board, $post['thumb']) === '' && !copy($placeholder, thumbWritePath($post['thumb'], $board))) {
			@unlink($file_src);
			fancyDie(__('Could not create thumbnail.'));
		}
	} else if ($file_mime == 'image/svg+xml') {
		// GD can't do svg, so the sanitized svg is its own thumb
		$post['thumb'] = $file_name_pre . 's.svg';
		list($thumb_maxwidth, $thumb_maxheight) = thumbnailDimensions($post);
		$scale = min($thumb_maxwidth / $post['image_width'], $thumb_maxheight / $post['image_height'], 1);
		$post['thumb_width'] = max(1, (int)round($post['image_width'] * $scale));
		$post['thumb_height'] = max(1, (int)round($post['image_height'] * $scale));
		if (thumbExisting($board, $post['thumb'], false) === '' && !copy($file_src, thumbWritePath($post['thumb'], $board))) {
			@unlink($file_src);
			fancyDie(__('Could not create thumbnail.'));
		}
	} else if (in_array($file_mime, array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp'))) {
		$post['thumb'] = $file_name_pre . 's.' . $schan_uploads[$file_mime][0];
		list($thumb_maxwidth, $thumb_maxheight) = thumbnailDimensions($post);

		$reuse = !$spoiler && thumbExisting($board, $post['thumb']) !== '';
		if (!$reuse && !createThumbnail($file_src, thumbWritePath($post['thumb'], $board), $thumb_maxwidth, $thumb_maxheight, $spoiler)) {
			@unlink($file_src);
			fancyDie(__('Could not create thumbnail.'));
		}
	} else if ($file_mime == 'audio/webm' || $file_mime == 'video/webm' || $file_mime == 'audio/mp4' || $file_mime == 'video/mp4') {
		list($post['image_width'], $post['image_height']) = videoDimensions($file_src);

		if ($post['image_width'] > 0 && $post['image_height'] > 0) {
			list($thumb_maxwidth, $thumb_maxheight) = thumbnailDimensions($post);
			$post['thumb'] = $file_name_pre . 's.jpg';
			$thumb_dest = thumbWritePath($post['thumb'], $board);

			// existing thumb already has the play button, use as is
			$reuse = !$spoiler && thumbExisting($board, $post['thumb']) !== '';
			if (!$reuse) {
				ffmpegThumbnail($file_src, $thumb_dest, $thumb_maxwidth, $thumb_maxheight);
			}

			$thumb_info = @getimagesize(thumbPath($board, $post['thumb']));
			$post['thumb_width'] = $thumb_info ? $thumb_info[0] : 0;
			$post['thumb_height'] = $thumb_info ? $thumb_info[1] : 0;

			if ($post['thumb_width'] <= 0 || $post['thumb_height'] <= 0) {
				@unlink($file_src);
				@unlink($thumb_dest);
				fancyDie(__('Sorry, your video appears to be corrupt.'));
			}

			if (!$reuse) {
				addVideoOverlay($thumb_dest, $board);
			}
		}

		$duration = videoDuration($file_src);
		if ($duration > 0) {
			$mins = floor(round($duration / 1000) / 60);
			$secs = str_pad(floor(round($duration / 1000) % 60), 2, '0', STR_PAD_LEFT);

			$post['file_original'] = "$mins:$secs" . ($post['file_original'] != '' ? (', ' . $post['file_original']) : '');
		}
	}

	if ($post['thumb'] != '' && $file_mime != 'image/svg+xml') {
		$thumb_info = @getimagesize(thumbPath($board, $post['thumb']));
		$post['thumb_width'] = $thumb_info ? $thumb_info[0] : 0;
		$post['thumb_height'] = $thumb_info ? $thumb_info[1] : 0;

		if ($post['thumb_width'] <= 0 || $post['thumb_height'] <= 0) {
			@unlink($file_src);
			@unlink(thumbWritePath($post['thumb'], $board));
			fancyDie(__('Sorry, your video appears to be corrupt.'));
		}
	}

	return $post;
}
