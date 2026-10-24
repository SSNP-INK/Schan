<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

$multibyte_enabled = function_exists('mb_strlen');

// thumbnail code is in element/thumbnailing.php
require_once __DIR__ . '/element/thumbnailing.php';

if (!function_exists('array_column')) {
	function array_column($array, $column_name) {
		return array_map(function ($element) use ($column_name) {
			return $element[$column_name];
		}, $array);
	}
}

// exclusive lock so db access doesn't race
function lockDatabase() {
	if (SCHAN_LOCKFILE == '') {
		return true;
	}
	$fp = fopen(SCHAN_LOCKFILE, 'c+');
	if (!flock($fp, LOCK_EX)) {
		fancyDie('Failed to lock control file.');
	}
	return $fp;
}

function _strlen($string) {
	global $multibyte_enabled;
	if ($multibyte_enabled) {
		return mb_strlen($string);
	}
	return strlen($string);
}

function _strpos($haystack, $needle, $offset=0) {
	global $multibyte_enabled;
	if ($multibyte_enabled) {
		return mb_strpos($haystack, $needle, $offset);
	}
	return strpos($haystack, $needle, $offset);
}

function _substr($string, $start, $length=null) {
	global $multibyte_enabled;
	if ($multibyte_enabled) {
		return mb_substr($string, $start, $length);
	}
	return substr($string, $start, $length);
}

function _substr_count($haystack, $needle) {
	global $multibyte_enabled;
	if ($multibyte_enabled) {
		return mb_substr_count($haystack, $needle);
	}
	return substr_count($haystack, $needle);
}

function hashData($data, $force = false) {
	global $bcrypt_salt;
	if (substr($data, 0, 4) == '$2y$' && !$force) {
		return $data;
	}
	return crypt($data, $bcrypt_salt);
}

function cleanString($string) {
	$search = array("&", "<", ">");
	$replace = array("&amp;", "&lt;", "&gt;");

	return str_replace($search, $replace, $string);
}

function cleanQuotes($string) {
	$search = array("'", "\"");
	$replace = array("&apos;", "&quot;");

	return str_replace($search, $replace, $string);
}

function plural($count, $singular, $plural) {
	if ($plural == 's') {
		$plural = $singular . $plural;
	}
	return ($count == 1 ? $singular : $plural);
}



function newPost($parent = SCHAN_NEWTHREAD) {
	return array(
		'parent' => $parent,
		'timestamp' => '0',
		'bumped' => '0',
		'ip' => '',
		'name' => '',
		'tripcode' => '',
		'email' => '',
		'nameblock' => '',
		'subject' => '',
		'message' => '',
		'password' => '',
		'file' => '',
		'file_hex' => '',
		'file_original' => '',
		'file_size' => '0',
		'file_size_formatted' => '',
		'image_width' => '0',
		'image_height' => '0',
		'thumb' => '',
		'thumb_width' => '0',
		'thumb_height' => '0',
		'stickied' => '0',
		'locked' => '0',
		'moderated' => '1',
		'board' => ''
	);
}

function convertBytes($number) {
	$len = strlen($number);
	if ($len < 4) {
		return sprintf("%dB", $number);
	} elseif ($len <= 6) {
		return sprintf("%0.2fKB", $number / 1024);
	} elseif ($len <= 9) {
		return sprintf("%0.2fMB", $number / 1024 / 1024);
	}

	return sprintf("%0.2fGB", $number / 1024 / 1024 / 1024);
}

function nameAndTripcode($name) {
	if (preg_match("/(#|!)(.*)/", $name, $regs)) {
		$cap = $regs[2];
		$cap_full = '#' . $regs[2];

		if (function_exists('mb_convert_encoding')) {
			$recoded_cap = mb_convert_encoding($cap, 'SJIS', 'UTF-8');
			if ($recoded_cap != '') {
				$cap = $recoded_cap;
			}
		}

		if (strpos($name, '#') === false) {
			$cap_delimiter = '!';
		} elseif (strpos($name, '!') === false) {
			$cap_delimiter = '#';
		} else {
			$cap_delimiter = (strpos($name, '#') < strpos($name, '!')) ? '#' : '!';
		}

		if (preg_match("/(.*)(" . $cap_delimiter . ")(.*)/", $cap, $regs_secure)) {
			$cap = $regs_secure[1];
			$cap_secure = $regs_secure[3];
			$is_secure_trip = true;
		} else {
			$is_secure_trip = false;
		}

		$tripcode = "";
		if ($cap != "") { // Copied from Futabally
			$cap = strtr($cap, "&amp;", "&");
			$cap = strtr($cap, "&#44;", ", ");
			$salt = substr($cap . "H.", 1, 2);
			$salt = preg_replace("/[^\.-z]/", ".", $salt);
			$salt = strtr($salt, ":;<=>?@[\\]^_`", "ABCDEFGabcdef");
			$tripcode = substr(crypt($cap, $salt), -10);
		}

		if ($is_secure_trip) {
			if ($cap != "") {
				$tripcode .= "!";
			}

			$tripcode .= "!" . substr(md5($cap_secure . SCHAN_TRIPSEED), 2, 10);
		}

		return array(preg_replace("/(" . $cap_delimiter . ")(.*)/", "", $name), $tripcode);
	}

	return array($name, "");
}

function nameBlock($name, $tripcode, $email, $timestamp, $capcode, $flag = '') {
	global $schan_anonymous;
	$anonymous = $schan_anonymous[array_rand($schan_anonymous)];

	$output = '<span class="postername">';
	$output .= ($name == '' && $tripcode == '') ? $anonymous : $name;

	if ($tripcode != '') {
		$output .= '</span><span class="postertrip">!' . $tripcode;
	}

	$output .= '</span>';

	if ($email != '' && strtolower($email) != 'noko') {
		$output = '<a href="mailto:' . $email . '">' . $output . '</a>';
	}

	return $output . $capcode . $flag . ' ' . formatDate($timestamp);
}

function writePage($filename, $contents) {
	// make sure the directory exists, otherwise writing to a new board folder (or res/ before
	// its first rebuild) throws copy/chmod warnings
	$dir = dirname($filename);
	if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
		if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
			fancyDie(sprintf(__("Directory '%s' could not be created."), $dir));
		}
	}

	// temp file in the same dir so rename() stays on one filesystem and can't turn into a cross-device copy
	$tempfile = @tempnam($dir === '' ? '.' : $dir, 'schan');
	if ($tempfile === false) {
		fancyDie(sprintf(__("Could not create a temporary file in '%s'."), $dir));
	}

	$fp = @fopen($tempfile, 'w');
	if ($fp === false) {
		@unlink($tempfile);
		fancyDie(sprintf(__("Could not open '%s' for writing."), $tempfile));
	}
	fwrite($fp, $contents);
	fclose($fp);

	// rename failed, fall back
	if (!@rename($tempfile, $filename)) {
		if (!@copy($tempfile, $filename)) {
			@unlink($tempfile);
			fancyDie(sprintf(__("Could not write to '%s'."), $filename));
		}
		@unlink($tempfile);
	}

	@chmod($filename, 0664);
}

function fixLinksInRes($html) {
	$search = array(' href="css/', ' src="js/', ' href="src/', ' href="thumb/', ' href="res/', ' href="imgboard.php', ' href="catalog.html', ' href="favicon.ico', 'src="thumb/', 'src="inc/', 'src="sticky.png', 'src="lock.png', ' action="imgboard.php', ' action="catalog.html');
	$replace = array(' href="../css/', ' src="../js/', ' href="../src/', ' href="../thumb/', ' href="../res/', ' href="../imgboard.php', ' href="../catalog.html', ' href="../favicon.ico', 'src="../thumb/', 'src="../inc/', 'src="../sticky.png', 'src="../lock.png', ' action="../imgboard.php', ' action="../catalog.html');

	return str_replace($search, $replace, $html);
}

function _postLink($matches) {
	$post = postByID($matches[1]);
	if ($post) {
		$is_op = $post['parent'] == SCHAN_NEWTHREAD;
		return '<a href="res/' . ($is_op ? $post['id'] : $post['parent']) . '.html#' . $matches[1] . '" class="' . ($is_op ? 'refop' : 'refreply') . '">' . $matches[0] . '</a>';
	}
	return $matches[0];
}

function postLink($message) {
	return preg_replace_callback('/&gt;&gt;([0-9]+)/', '_postLink', $message);
}

function _finishWordBreak($matches) {
	return '<a' . $matches[1] . 'href="' . str_replace(SCHAN_WORDBREAK_IDENTIFIER, '', $matches[2]) . '"' . $matches[3] . '>' . str_replace(SCHAN_WORDBREAK_IDENTIFIER, '<br>', $matches[4]) . '</a>';
}

function finishWordBreak($message) {
	return str_replace(SCHAN_WORDBREAK_IDENTIFIER, '<br>', preg_replace_callback('/<a(.*?)href="([^"]*?)"(.*?)>(.*?)<\/a>/', '_finishWordBreak', $message));
}

function colorQuote($message) {
	if (substr($message, -1, 1) != "\n") {
		$message .= "\n";
	}
	return preg_replace('/^(&gt;[^\>](.*))\n/m', '<span class="unkfunc">\\1</span>' . "\n", $message);
}

function deletePostImages($post) {
	$board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();
	if (function_exists('mediaDeleteExtras') && isset($post['id'])) {
		mediaDeleteExtras($board, $post['id']);
	}
	if (!isEmbed($post['file_hex']) && $post['file'] != '') {
		@unlink($board . '/src/' . $post['file']);
	}
	if ($post['thumb'] != '') {
		@unlink($board . '/thumb/' . $post['thumb']);
	}
}

function deletePost($id) {
	$id = intval($id);

	$is_op = false;
	$parent = 0;
	$op = array();
	$posts = postsInThreadByID($id, false);
	foreach ($posts as $post) {
		if ($post['parent'] == SCHAN_NEWTHREAD) {
			if ($post['id'] == $id) {
				$is_op = true;
			}
			$op = $post;
			continue;
		} else if ($post['id'] == $id) {
			$parent = $post['parent'];
		}

		deletePostImages($post);
		deleteReportsByPost($post['id']);
		deletePostByID($post['id']);
		if (function_exists('pollDelete')) {
			pollDelete($post['id']);
		}
		if (function_exists('spoilerClear')) {
			spoilerClear($post['id']);
		}
	}
	if (!empty($op)) {
		deletePostImages($op);
		deleteReportsByPost($op['id']);
		deletePostByID($op['id']);
		if (function_exists('pollDelete')) {
			pollDelete($op['id']);
		}
		if (function_exists('spoilerClear')) {
			spoilerClear($op['id']);
		}
	}

	if ($is_op) {
		$board = current_board();
		@unlink($board . '/res/' . $id . '.html');
		return;
	}

	$current_bumped = 0;
	$new_bumped = 0;
	$posts = postsInThreadByID($parent, false);
	foreach ($posts as $post) {
		if ($post['parent'] == SCHAN_NEWTHREAD) {
			$current_bumped = $post['bumped'];
		} else if ($post['id'] == $id || strtolower($post['email']) == 'sage') {
			continue;
		}
		$new_bumped = $post['timestamp'];
	}
	if ($new_bumped >= $current_bumped) {
		return;
	}
	updatePostBumped($parent, $new_bumped);
	rebuildIndexes();
}

function checkCAPTCHA($mode) {
	if ($mode === 'hcaptcha') {
		$captcha = isset($_POST['h-captcha-response']) ? $_POST['h-captcha-response'] : '';
		if ($captcha == '') {
			fancyDie('Failed CAPTCHA. Reason:<br>Please click the checkbox labeled "I am human".');
		}

		$data = array(
			'secret' => SCHAN_HCAPTCHA_SECRET,
			'response' => $captcha
		);
		$verify = curl_init();
		curl_setopt($verify, CURLOPT_URL, "https://hcaptcha.com/siteverify");
		curl_setopt($verify, CURLOPT_POST, true);
		curl_setopt($verify, CURLOPT_POSTFIELDS, http_build_query($data));
		curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);
		$verifyResponse = curl_exec($verify);
		$responseData = json_decode($verifyResponse);
		if (!isset($responseData->success) || !$responseData->success) {
			fancyDie('Failed CAPTCHA.');
		}
	} else if ($mode === 'recaptcha') {
		require_once 'inc/recaptcha/autoload.php';

		$captcha = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
		$failed_captcha = true;

		$recaptcha = new \ReCaptcha\ReCaptcha(SCHAN_RECAPTCHA_SECRET);
		$resp = $recaptcha->verify($captcha, remoteAddress());
		if ($resp->isSuccess()) {
			$failed_captcha = false;
		}

		if ($failed_captcha) {
			$captcha_error = 'Failed CAPTCHA.';
			$error_reason = '';

			if (count($resp->getErrorCodes()) == 1) {
				$error_codes = $resp->getErrorCodes();
				$error_reason = $error_codes[0];
			}

			if ($error_reason == 'missing-input-response') {
				$captcha_error .= ' Please click the checkbox labeled "I\'m not a robot".';
			} else {
				$captcha_error .= ' Reason:';
				foreach ($resp->getErrorCodes() as $error) {
					$captcha_error .= '<br>' . $error;
				}
			}
			fancyDie($captcha_error);
		}
	} else if ($mode) { // Simple CAPTCHA
		$captcha = isset($_POST['captcha']) ? strtolower(trim($_POST['captcha'])) : '';
		$captcha_solution = isset($_SESSION['schancaptcha']) ? strtolower(trim($_SESSION['schancaptcha'])) : '';

		if ($captcha == '') {
			fancyDie(__('Please enter the CAPTCHA text.'));
		} else if ($captcha != $captcha_solution) {
			fancyDie(__('Incorrect CAPTCHA text entered.  Please try again.<br>Click the image to retrieve a new CAPTCHA.'));
		}
	}
}

function checkBanned() {
	$ban = banByIP(remoteAddress());
	if ($ban) {
		if ($ban['expire'] == 0 || $ban['expire'] > time()) {
			$expire = ($ban['expire'] > 0) ? ('<br>This ban will expire ' . formatDate($ban['expire'])) : '<br>This ban is permanent and will not expire.';
			$reason = ($ban['reason'] == '') ? '' : ('<br>Reason: ' . $ban['reason']);
			$appeal = function_exists('appealBoxHTML') ? appealBoxHTML($ban) : '';
			fancyDie('Your IP address ' . remoteAddress() . ' has been banned from posting on this image board.  ' . $expire . $reason . $appeal);
		} else {
			clearExpiredBans();
		}
	}
}

function checkKeywords($text) {
	$keywords = allKeywords();
	foreach ($keywords as $keyword) {
		if (substr($keyword['text'], 0, 7) == 'regexp:') {
			if (preg_match(substr($keyword['text'], 7), $text)) {
				$keyword['text'] = substr($keyword['text'], 7);
				return $keyword;
			}
			continue;
		}

		if (stripos($text, $keyword['text']) !== false) {
			return $keyword;
		}
	}
	return array();
}

function checkFlood() {
	if (SCHAN_DELAY > 0) {
		$lastpost = lastPostByIP();
		if ($lastpost) {
			if ((time() - $lastpost['timestamp']) < SCHAN_DELAY) {
				fancyDie("Please wait a moment before posting again.  You will be able to make another post in " . (SCHAN_DELAY - (time() - $lastpost['timestamp'])) . " " . plural(SCHAN_DELAY - (time() - $lastpost['timestamp']), "second", "seconds") . ".");
			}
		}
	}
}

function checkMessageSize() {
	if (SCHAN_MAXMESSAGE > 0 && _strlen($_POST['message']) > SCHAN_MAXMESSAGE) {
		fancyDie(sprintf(__('Please shorten your message, or post it in multiple parts. Your message is %1$d characters long, and the maximum allowed is %2$d.'), _strlen($_POST['message']), SCHAN_MAXMESSAGE));
	}
}

function checkAutoHide($post) {
	if (SCHAN_AUTOHIDE <= 0) {
		return;
	}

	$reports = reportsByPost($post['id']);
	if (count($reports) >= SCHAN_AUTOHIDE) {
		approvePostByID($post['id'], 0);

		$parent_id = $post['parent'] == SCHAN_NEWTHREAD ? $post['id'] : $post['parent'];
		threadUpdated($parent_id);
	}
}

function manageCheckLogIn($requireKey) {
	$account = array();
	$loggedin = false;
	$isadmin = false;

	$key = (isset($_GET['manage']) && $_GET['manage'] != '') ? hashData($_GET['manage']) : '';
	if ($key == '' && isset($_SESSION['schan_key'])) {
		$key = $_SESSION['schan_key'];
	}
	if (SCHAN_MANAGEKEY != '' && $key !== hashData(SCHAN_MANAGEKEY)) {
		$_SESSION['schan_key'] = '';
		$_SESSION['schan_account'] = '';
		session_destroy();

		if ($requireKey) {
			fancyDie(__('Invalid key.'));
		}

		return array($account, $loggedin, $isadmin);
	}

	if (isset($_POST['username']) && isset($_POST['managepassword']) && $_POST['username'] != '' && $_POST['managepassword'] != '') {
		checkCAPTCHA(SCHAN_MANAGECAPTCHA);

		$a = accountByUsername($_POST['username']);
		if (empty($a) || hashData($_POST['managepassword'], true) !== $a['password']) {
			fancyDie(__('Invalid username or password.'));
		}
		$_SESSION['schan_key'] = hashData(SCHAN_MANAGEKEY);
		$_SESSION['schan_username'] = $a['username'];
		$_SESSION['schan_password'] = $a['password'];

		// Prevent reauthentication
		$_POST['username'] = '';
		$_POST['managepassword'] = '';
	}

	if (isset($_SESSION['schan_username']) && isset($_SESSION['schan_password'])) {
		$a = accountByUsername($_SESSION['schan_username']);
		if (!empty($a) && $a['password'] == $_SESSION['schan_password'] && $a['role'] != SCHAN_DISABLED) {
			$account = $a;
			$loggedin = true;
			if ($account['role'] == SCHAN_SUPER_ADMINISTRATOR || $account['role'] == SCHAN_ADMINISTRATOR) {
				$isadmin = true;
			}

			$account['lastactive'] = time();
			updateAccount($account);
		}
	}

	return array($account, $loggedin, $isadmin);
}

function manageLogAction($action) {
	global $account;
	$account_id = 0;
	if (isset($account['id'])) {
		$account_id = $account['id'];
	}
	$log = array(
		'timestamp' => time(),
		'account' => $account_id,
		'message' => $action,
		'board' => current_board()
	);
	insertLog($log);
}
function threadUpdated($id) {
	rebuildThread($id);
	rebuildIndexes();
}
function setParent() {
	if (isset($_POST["parent"])) {
		if ($_POST["parent"] != SCHAN_NEWTHREAD) {
			if (!threadExistsByID($_POST['parent'])) {
				fancyDie(__('Invalid parent thread ID supplied, unable to create post.'));
			}

			return $_POST["parent"];
		}
	}

	return SCHAN_NEWTHREAD;
}

function getParent($post) {
	if ($post['parent'] == SCHAN_NEWTHREAD) {
		return $post['id'];
	}
	return $post['parent'];
}

function isStaffPost() {
	if (isset($_POST['staffpost'])) {
		list($staff_account, $loggedin, $isadmin) = manageCheckLogIn(false);
		// Janitors have no posting rights
		if ($loggedin && function_exists('roleIsJanitor') && roleIsJanitor($staff_account)) {
			return false;
		}
		return $loggedin;
	}

	return false;
}

function validateFileUpload() {
	switch ($_FILES['file']['error']) {
		case UPLOAD_ERR_OK:
			break;
		case UPLOAD_ERR_FORM_SIZE:
			fancyDie(sprintf(__('That file is larger than %s.'), SCHAN_MAXKBDESC));
			break;
		case UPLOAD_ERR_INI_SIZE:
			fancyDie(sprintf(__('The uploaded file exceeds the upload_max_filesize directive (%s) in php.ini.'), ini_get('upload_max_filesize')));
			break;
		case UPLOAD_ERR_PARTIAL:
			fancyDie(__('The uploaded file was only partially uploaded.'));
			break;
		case UPLOAD_ERR_NO_FILE:
			fancyDie(__('No file was uploaded.'));
			break;
		case UPLOAD_ERR_NO_TMP_DIR:
			fancyDie(__('Missing a temporary folder.'));
			break;
		case UPLOAD_ERR_CANT_WRITE:
			fancyDie(__('Failed to write file to disk'));
			break;
		default:
			fancyDie(__('Unable to save the uploaded file.'));
	}
}

// svg support
// svg is xml and can carry <script>, event handlers, external refs, so uploads get parsed and
// rewritten on the way in, anything not on the allow-list is dropped
// (also serve /src and /thumb svgs with a sandboxing CSP header, see the .htaccess note)
function svgCleanCss($css) {
	if (preg_match('/@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $css)) {
		return '';
	}
	return preg_replace('/url\(\s*(["\']?)(?!#|data:image\/(?:png|jpe?g|gif|webp)[;,])[^)]*\)/i', 'none', $css);
}

function svgLengthToPx($value) {
	if (!is_string($value) || !preg_match('/^\s*([0-9]*\.?[0-9]+)\s*(px|pt|pc|mm|cm|in)?\s*$/i', $value, $m)) {
		return null;
	}
	$factors = array('' => 1, 'px' => 1, 'pt' => 4 / 3, 'pc' => 16, 'mm' => 3.7795, 'cm' => 37.795, 'in' => 96);
	$unit = isset($m[2]) ? strtolower($m[2]) : '';
	return (float)$m[1] * $factors[$unit];
}

function svgSanitizeNode($node) {
	$svg_ns = 'http://www.w3.org/2000/svg';
	$xlink_ns = 'http://www.w3.org/1999/xlink';
	$bad_elements = array('script', 'foreignobject', 'iframe', 'object', 'embed', 'handler', 'listener', 'audio', 'video', 'canvas');

	$children = array();
	foreach ($node->childNodes as $child) {
		$children[] = $child;
	}
	foreach ($children as $child) {
		if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || ($child->nodeType === XML_CDATA_SECTION_NODE && $node->localName !== 'style')) {
			$node->removeChild($child);
			continue;
		}
		if ($child->nodeType !== XML_ELEMENT_NODE) {
			continue;
		}

		$name = strtolower($child->localName);
		if ($child->namespaceURI !== $svg_ns || in_array($name, $bad_elements)) {
			$node->removeChild($child);
			continue;
		}

		// animations can't touch link attributes
		if ($name === 'set' || $name === 'animate') {
			$target = strtolower($child->getAttribute('attributeName'));
			if (strpos($target, 'href') !== false || stripos($child->getAttribute('to') . $child->getAttribute('values'), 'javascript:') !== false) {
				$node->removeChild($child);
				continue;
			}
		}

		$attrs = array();
		foreach ($child->attributes as $attr) {
			$attrs[] = $attr;
		}
		foreach ($attrs as $attr) {
			$aname = strtolower($attr->localName);
			$value = $attr->value;
			$drop = false;

			if (strpos($aname, 'on') === 0) {
				$drop = true;									// onclick, onload, ...
			} else if ($attr->namespaceURI !== null && $attr->namespaceURI !== '' && $attr->namespaceURI !== $xlink_ns && $attr->namespaceURI !== 'http://www.w3.org/XML/1998/namespace') {
				$drop = true;									// inkscape:, sodipodi:, ...
			} else if ($aname === 'base' && $attr->namespaceURI === 'http://www.w3.org/XML/1998/namespace') {
				$drop = true;
			} else if ($aname === 'href' || $aname === 'src') {
				// only #fragments and embedded raster images
				if (!preg_match('/^\s*(#|data:image\/(?:png|jpe?g|gif|webp)[;,])/i', $value)) {
					$drop = true;
				}
			} else if ($aname === 'style') {
				$clean = svgCleanCss($value);
				if ($clean !== $value) {
					$attr->value = $clean;
				}
			} else if (preg_match('/javascript:/i', $value)) {
				$drop = true;
			}

			if ($drop) {
				$child->removeAttributeNode($attr);
			}
		}

		if ($name === 'style') {
			$child->textContent = svgCleanCss($child->textContent);
		}

		svgSanitizeNode($child);
	}
}

// cleans an svg file in place, returns array(width, height) in px or false if it's not a usable svg
function sanitizeSvgFile($path) {
	$data = @file_get_contents($path);
	if ($data === false || $data === '') {
		return false;
	}
	// no entity tricks (XXE / billion laughs), a plain external DOCTYPE just gets dropped
	if (stripos($data, '<!ENTITY') !== false) {
		return false;
	}
	$data = preg_replace('/<!DOCTYPE[^>\[]*>/i', '', $data);
	if (stripos($data, '<!DOCTYPE') !== false) {
		return false;
	}

	$previous = libxml_use_internal_errors(true);
	$doc = new DOMDocument();
	$loaded = $doc->loadXML($data, LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	if (!$loaded || !$doc->documentElement) {
		return false;
	}
	$root = $doc->documentElement;
	if (strtolower($root->localName) !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
		return false;
	}

	// walks the root and everything under it, applying the allow-list
	svgSanitizeNode($doc);
	if (!$doc->documentElement) {
		return false;
	}
	$root = $doc->documentElement;

	$w = svgLengthToPx($root->getAttribute('width'));
	$h = svgLengthToPx($root->getAttribute('height'));
	if (($w === null || $h === null || $w <= 0 || $h <= 0) && preg_match('/^\s*[-0-9.eE]+[\s,]+[-0-9.eE]+[\s,]+([0-9.eE]+)[\s,]+([0-9.eE]+)\s*$/', $root->getAttribute('viewBox'), $m)) {
		$vw = (float)$m[1];
		$vh = (float)$m[2];
		if ($vw > 0 && $vh > 0) {
			if ($w !== null && $w > 0) {
				$h = $w * $vh / $vw;
			} else if ($h !== null && $h > 0) {
				$w = $h * $vw / $vh;
			} else {
				$w = $vw;
				$h = $vh;
			}
		}
	}
	if ($w === null || $h === null || $w <= 0 || $h <= 0) {
		$w = 300;
		$h = 150;
	}
	$w = max(1, min(65535, (int)round($w)));
	$h = max(1, min(65535, (int)round($h)));

	$out = $doc->saveXML();
	if ($out === false || file_put_contents($path, $out) === false) {
		return false;
	}
	return array($w, $h);
}

function isExpandableImageFile($filename) {
	return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'));
}

function strallpos($haystack, $needle, $offset = 0) {
	$result = array();
	for ($i = $offset; $i < _strlen($haystack); $i++) {
		$pos = _strpos($haystack, $needle, $i);
		if ($pos !== False) {
			$offset = $pos;
			if ($offset >= $i) {
				$i = $offset;
				$result[] = $offset;
			}
		}
	}
	return $result;
}

function url_get_contents($url) {
	if (!function_exists('curl_init')) {
		return file_get_contents($url);
	}

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	$output = curl_exec($ch);
	$responsecode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	curl_close($ch);

	if (intval($responsecode) != 200) {
		return '';
	}
	return $output;
}

function isEmbed($file_hex) {
	global $schan_embeds;
	return in_array($file_hex, array_keys($schan_embeds));
}

function getEmbed($url) {
	global $schan_embeds;
	foreach ($schan_embeds as $service => $service_url) {
		$service_url = str_ireplace("SCHANEMBED", urlencode($url), $service_url);
		$data = url_get_contents($service_url);
		if ($data != '') {
			$result = json_decode($data, true);
			if (!empty($result)) {
				return array($service, $result);
			}
		}
	}

	return array('', array());
}

function attachFile($post, $filepath, $filename, $uploaded, $spoiler) {
	global $schan_uploads;

	if (!is_file($filepath) || !is_readable($filepath)) {
		@unlink($filepath);
		fancyDie(__('File transfer failure. Please retry the submission.'));
	}

	$file_mime_split = explode(' ', trim(mime_content_type($filepath)));
	if (count($file_mime_split) > 0) {
		$file_mime = strtolower(array_pop($file_mime_split));
	} else {
		if (!@getimagesize($filepath)) {
			@unlink($filepath);
			fancyDie(__('Failed to read the MIME type and size of the uploaded file. Please retry the submission.'));
		}
		$file_mime = mime_content_type($filepath);
	}
	if (in_array($file_mime, array('text/xml', 'application/xml', 'text/plain')) && isset($schan_uploads['image/svg+xml']) && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
		$file_mime = 'image/svg+xml';   // validated for real by sanitizeSvgFile() below
	}
	if (empty($file_mime) || !isset($schan_uploads[$file_mime])) {
		fancyDie(supportedFileTypes());
	}

	$board = isset($post['board']) && $post['board'] !== '' ? $post['board'] : current_board();

	$file_name_pre = time() . substr(microtime(), 2, 3);
	while (count(glob($board . '/src/' . $file_name_pre . '.*')) > 0 || count(glob($board . '/thumb/' . $file_name_pre . 's.*')) > 0) {
		usleep(1000);
		$file_name_pre = time() . substr(microtime(), 2, 3);
	}
	$file_name = $file_name_pre . '.' . $schan_uploads[$file_mime][0];
	$file_src = $board . '/src/' . $file_name;

	if ($uploaded) {
		if (!move_uploaded_file($filepath, $file_src)) {
			fancyDie(__('Could not copy uploaded file.'));
		}
	} else {
		if (!rename($filepath, $file_src)) {
			@unlink($filepath);
			fancyDie(__('Could not copy uploaded file.'));
		}
	}
	$filepath = $file_src;

	$filesize = filesize($filepath);
	if (filesize($filepath) != $filesize) {
		@unlink($filepath);
		fancyDie(__('File transfer failure. Please go back and try again.'));
	} else if (SCHAN_MAXKB > 0 && $filesize > (SCHAN_MAXKB * 1024)) {
		@unlink($filepath);
		fancyDie(sprintf(__('That file is larger than %s.'), SCHAN_MAXKBDESC));
	}

	if (SCHAN_STRIPMETADATA) {
		stripMetadata($filepath);
	}

	$svg_dims = false;
	if ($file_mime == 'image/svg+xml') {
		$svg_dims = sanitizeSvgFile($file_src);
		if ($svg_dims === false) {
			@unlink($file_src);
			fancyDie(__('That SVG file is invalid or contains unsupported content.'));
		}
		clearstatcache(true, $file_src);
		$filesize = filesize($file_src);
	}

	$post['file'] = $file_name;
	$post['file_original'] = trim(htmlentities(_substr($filename, 0, 50), ENT_QUOTES));
	$post['file_hex'] = md5_file($filepath);
	$post['file_size'] = $filesize;
	$post['file_size_formatted'] = convertBytes($post['file_size']);

	if (in_array($file_mime, array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'application/x-shockwave-flash'))) {
		$file_info = getimagesize($file_src);
		$post['image_width'] = $file_info[0] != '' ? $file_info[0] : 0;
		$post['image_height'] = $file_info[1] != '' ? $file_info[1] : 0;
	} else if ($svg_dims !== false) {
		list($post['image_width'], $post['image_height']) = $svg_dims;
	}

	$post = createPostThumbnail($post, $file_mime, $file_src, $file_name_pre, $board, $spoiler);

	return $post;
}

function stripMetadata($filename) {
	$discard = '';
	$exit_status = 1;
	exec("exiftool -ver", $discard, $exit_status);
	if ($exit_status != 0) {
		fancyDie('ExifTool is not installed, or the <i>exiftool</i> executable is not in the server\'s $PATH.<br>Install ExifTool, or set SCHAN_STRIPMETADATA to false.');
	}

	$discard = '';
	$exit_status = 1;
	exec("exiftool -All= -overwrite_original_in_place " . escapeshellarg($filename), $discard, $exit_status);
}

function formatDate($timestamp) {
	return @strftime(SCHAN_DATEFMT, $timestamp);
}

function remoteAddress() {
	if (SCHAN_CLOUDFLARE && isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
		return $_SERVER['HTTP_CF_CONNECTING_IP'];
	}
	return $_SERVER['REMOTE_ADDR'];
}

function installedViaGit() {
	return is_dir('.git');
}
