<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}
require_once __DIR__ . '/extra/storage.php';

/*
 * extension registry
 *
 * some features are self-contained modules (home page, flags, stats, country blocking)
 * each can be turned on/off from ?manage&extensions. disabled ones aren't required, their
 * manage pages are hidden and the code that calls them is skipped
 *
 * state is in the database (settings table, name 'extensions')
 */

function extensionList() {
	return array(
		'polls' => array(
			'label' => __('Polls'),
			'desc'  => __('Let posters attach a poll (2-8 options, one vote per IP) to a post.'),
			'file'  => 'inc/extra/poll.php',
			'default' => true,
			'wipes' => __('Deletes every poll and all votes on every board.'),
		),
		'emoji' => array(
			'label' => __('Custom emoji and BBCode toolbar'),
			'desc'  => __('Custom :emoji: images, the emoji / kaomoji pickers and the BBCode toolbar in the post form. Manage the images from the Emoji section.'),
			'file'  => 'inc/extra/emoji.php',
			'default' => true,
			'wipes' => __('Deletes every uploaded emoji image (static/emojis).'),
		),
		'pending' => array(
			'label' => __('Image and link approval'),
			'desc'  => __('Hold media and/or links in new posts until staff approve them (SCHAN_REQMODMEDIA / SCHAN_REQMODLINKS in settings.php). Adds the Pending approval queue to the manage panel.'),
			'file'  => 'inc/extra/pending.php',
			'default' => true,
			'wipes' => __('Deletes the approval queue (pending-data.php); held media and links become visible.'),
		),
		'trust' => array(
			'label' => __('Trusted IPs'),
			'desc'  => __('Staff can whitelist a poster on a board so their posts skip approval. Adds the Whitelist button to posts in the manage view.'),
			'file'  => 'inc/extra/trust.php',
			'default' => true,
			'wipes' => __('Deletes the trusted-IP list (.trustedips.php).'),
		),
		'search' => array(
			'label' => __('Search'),
			'desc'  => __('Board search page (MySQL / mysqli only) and the search boxes on board pages and the manage dashboard. Stores no data.'),
			'file'  => 'inc/extra/search.php',
			'default' => true,
		),
		'globalmessage' => array(
			'label' => __('Global message'),
			'desc'  => __('One line of text shown on every board between the posting form and the threads. Edited from Manage > Boards.'),
			'file'  => 'inc/extra/globalmessage.php',
			'default' => true,
			'wipes' => __('Deletes the saved message (.globalmessage.json) and removes it from every board.'),
		),
		'home' => array(
			'label' => __('Home page'),
			'desc'  => __('Generate a custom home page (index.html) from blocks: logo, boards, recent posts, stats, custom HTML. Manage it from the Home page section.'),
			'file'  => 'inc/extra/home.php',
			'default' => false,
			'wipes' => __('Deletes the generated home page (index.html).'),
		),
		'flags' => array(
			'label' => __('Poster country flags'),
			'desc'  => __('Show a country flag next to each poster\'s name, from the GeoIP database or the Cloudflare header. Also handles custom (meme) flags and staff post edits.'),
			'file'  => 'inc/extra/flags.php',
			'default' => false,
			'wipes' => __('Deletes flags.html.'),
		),
		'stats' => array(
			'label' => __('Board statistics'),
			'desc'  => __('The stats.html page (posts per hour / day / 3 days / week / month for every board) and the Watching / Replies / Attachments line shown under threads.'),
			'file'  => null,
			'default' => false,
			'wipes' => __('Deletes stats.html.'),
		),
		'countries' => array(
			'label' => __('Country blocking'),
			'desc'  => __('Refuse posts from selected countries. Manage the list from the Countries section. Requires the GeoIP database (or Cloudflare). Depends on the flags extension for the country lookup.'),
			'file'  => null,
			'default' => false,
		),
	);
}

function extensionsLoad($force = false) {
	static $cache = null;
	if ($cache !== null && !$force) {
		return $cache;
	}
	$cache = storeJsonGet('extensions', array());
	return $cache;
}

function extensionsSave($data) {
	$ok = storeJsonSet('extensions', $data, JSON_UNESCAPED_UNICODE);
	extensionsLoad(true);
	return $ok;
}

function extensionEnabled($name) {
	$list = extensionList();
	if (!isset($list[$name])) {
		return false;
	}
	$all = extensionsLoad();
	if (array_key_exists($name, $all)) {
		return (bool)$all[$name];
	}
	return !empty($list[$name]['default']);
}

// toggle one extension, true on success
function extensionSet($name, $on) {
	$list = extensionList();
	if (!isset($list[$name])) {
		return false;
	}
	$all = extensionsLoad();
	$all[$name] = $on ? 1 : 0;
	return extensionsSave($all);
}

// uninstall: wipe everything an extension stored

function extensionRemovePath($path) {
	$root = realpath(dirname(__DIR__));
	$real = @realpath($path);
	if ($real === false) {
		return true; // nothing there
	}
	if ($root === false || $real === $root || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
		return false; // never delete outside the install dir
	}
	if (is_link($path) || is_file($real)) {
		return @unlink($real);
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($it as $f) {
		if ($f->isLink() || !$f->isDir()) {
			@unlink($f->getPathname());
		} else {
			@rmdir($f->getPathname());
		}
	}
	@rmdir($real);
	clearstatcache();
	return !file_exists($real);
}

// deletes one extension's data, true when it's all gone
// an extension's own file can define <name>ExtensionWipe() for data only it knows about
function extensionWipeData($name) {
	$root = dirname(__DIR__);
	$ok = true;

	switch ($name) {
		case 'polls':
			if (function_exists('allBoards')) {
				foreach (allBoards() as $b) {
					if (preg_match('/^[a-z0-9_-]{1,64}$/', $b['slug'])) {
						$ok = extensionRemovePath($root . '/' . $b['slug'] . '/polls') && $ok;
					}
				}
			}
			$ok = extensionRemovePath($root . '/polls') && $ok;
			break;
		case 'emoji':
			$ok = extensionRemovePath($root . '/static/emojis') && $ok;
			$ok = extensionRemovePath($root . '/emoji') && $ok;
			@rmdir($root . '/static');
			break;
		case 'globalmessage':
			$ok = extensionRemovePath($root . '/.globalmessage.json') && $ok;
			break;
		case 'pending':
			$ok = extensionRemovePath($root . '/pending-data.php') && $ok;
			break;
		case 'trust':
			$ok = extensionRemovePath($root . '/.trustedips.php') && $ok;
			break;
		case 'home':
			$ok = extensionRemovePath($root . '/index.html') && $ok;
			break;
		case 'flags':
			$ok = extensionRemovePath($root . '/flags.html') && $ok;
			break;
		case 'stats':
			$ok = extensionRemovePath($root . '/stats.html') && $ok;
			break;
	}

	$hook = $name . 'ExtensionWipe';
	if (function_exists($hook)) {
		$ok = (call_user_func($hook) !== false) && $ok;
	}
	return $ok;
}

// rebuild all boards so posts drop whatever the extension rendered
function extensionRebuildBoards() {
	@set_time_limit(0);
	foreach (allBoards() as $b) {
		$GLOBALS['schan_forced_board'] = $b['slug'];
		if (!defined('SCHAN_OVERBOARD') || $b['slug'] !== SCHAN_OVERBOARD) {
			foreach (allThreads() as $thread) {
				rebuildThread($thread['id'], $b['slug']);
			}
		}
		rebuildIndexes($b['slug']);
		$GLOBALS['schan_forced_board'] = null;
	}
}

// loads the extension's file if it's enabled and has no unmet deps
// true if the file was required (or it has none)
function extensionRequireFile($name) {
	$list = extensionList();
	if (!isset($list[$name]) || empty($list[$name]['file'])) {
		return true;
	}
	if (!extensionEnabled($name)) {
		return false;
	}
	$path = dirname(__DIR__) . '/' . $list[$name]['file'];
	if (!is_file($path)) {
		return false;
	}
	require_once $path;
	return true;
}

// country blocking needs the flags extension for the lookup
function countriesAvailable() {
	return extensionEnabled('countries') && extensionEnabled('flags');
}

// manage page, install/uninstall each extension as its own table (name, version, description, thumbnail, actions)
// thumbnail is static/extensions/<key>.png if it exists, installed ones get a red border
function manageExtensionsPage() {
	$list = extensionList();
	$th = 'style="background:#9b8ce8;color:#000;text-align:left;padding:4px 5px;white-space:nowrap;width:1%;vertical-align:top;border:2px solid #f0f0ff;"';
	$td = 'style="padding:4px 5px;vertical-align:top;"';
	$linkbtn = 'style="background:none;border:0;padding:0;margin:0;font:inherit;color:inherit;text-decoration:underline;cursor:pointer;"';

	$out = '<fieldset><legend>' . __('Extensions') . '</legend>'
		. '<p><small>' . __('Install (enable) or uninstall (disable) optional features. A disabled extension\'s files stay on disk, so it can be turned back on at any time.') . '</small></p>';

	$first = true;
	foreach ($list as $key => $ext) {
		$on = extensionEnabled($key);
		$missing = (!empty($ext['file']) && !is_file(dirname(__DIR__) . '/' . $ext['file']));

		$confirm = '';
		if ($on && !empty($ext['wipes'])) {
			$msg = sprintf(__('Uninstall %s? This permanently deletes its data. %s'), $ext['label'], $ext['wipes']);
			$confirm = ' onsubmit="return confirm(' . htmlspecialchars(json_encode($msg), ENT_QUOTES, 'UTF-8') . ');"';
		}
		$action = '<form method="post" action="?manage&amp;extensions" style="display:inline;"' . $confirm . '>'
			. '<input type="hidden" name="extension_action" value="' . ($on ? 'disable' : 'enable') . '">'
			. '<input type="hidden" name="extension_name" value="' . htmlentities($key, ENT_QUOTES) . '">'
			. '<input type="submit" value="' . ($on ? __('Uninstall') : __('Install')) . '" ' . $linkbtn . '>'
			. '</form>';

		$actions = '<li>' . $action . '</li>';
		if ($on && !$missing) {
			switch ($key) {
				case 'home':
					$actions = '<li><a href="?manage&amp;home">' . __('Settings') . '</a></li>' . $actions;
					break;
				case 'countries':
					$actions = '<li><a href="?manage&amp;countries">' . __('Settings') . '</a></li>' . $actions;
					break;
				case 'stats':
					$actions = '<li><a href="' . htmlentities(installRootURL() . '/stats.html', ENT_QUOTES) . '" target="_blank">' . __('View page') . '</a></li>' . $actions;
					break;
			}
		}

		$desc = htmlentities($ext['desc']);
		if (!empty($ext['wipes'])) {
			$desc .= '<br><small><i>' . __('Uninstalling:') . ' ' . htmlentities($ext['wipes']) . '</i></small>';
		}
		if (function_exists('flagsStatusNote') && $key === 'flags') {
			$desc .= flagsStatusNote();
		}
		if ($missing) {
			$desc .= '<br><small>(' . __('missing file') . ': ' . htmlentities($ext['file']) . ')</small>';
		}

		$rows = '<tr><th ' . $th . '>' . __('Name') . '</th><td ' . $td . '>' . htmlentities($ext['label'])
			. ($on ? ' <small>(' . __('Enabled') . ')</small>' : '') . '</td></tr>';
		if (!empty($ext['version'])) {
			$rows .= '<tr><th ' . $th . '>' . __('Version') . '</th><td ' . $td . '>' . htmlentities($ext['version']) . '</td></tr>';
		}
		$rows .= '<tr><th ' . $th . '>' . __('Description') . '</th><td ' . $td . '>' . $desc . '</td></tr>';

		// thumbnail, always shown, placeholder says which file to add
		$thumb_rel = 'static/extensions/' . preg_replace('/[^a-z0-9_-]/i', '', $key) . '.png';
		if (is_file(dirname(__DIR__) . '/' . $thumb_rel)) {
			$thumb_html = '<img src="' . htmlentities(installRootURL() . '/' . $thumb_rel, ENT_QUOTES) . '" alt="' . htmlentities($ext['label'], ENT_QUOTES) . '"'
				. ' style="max-width:150px;max-height:150px;padding:2px;border:2px solid ' . ($on ? '#e00' : '#ccc') . ';">';
		} else {
			$thumb_html = '<div style="display:inline-block;width:100px;height:60px;line-height:60px;text-align:center;border:2px dashed ' . ($on ? '#e00' : '#aaa') . ';font-size:10px;color:#666;" title="' . htmlentities($thumb_rel, ENT_QUOTES) . '">' . __('No image') . '</div>'
				. '<br><small>' . htmlentities($thumb_rel) . '</small>';
		}
		$rows .= '<tr><th ' . $th . '>' . __('Thumbnail') . '</th><td ' . $td . '>' . $thumb_html . '</td></tr>';

		$rows .= '<tr><th ' . $th . '>' . __('Actions') . '</th><td ' . $td . '><ul style="margin:0;padding-left:18px;">' . $actions . '</ul></td></tr>';

		if (!$first) {
			$out .= '<hr style="border:0;border-top:1px solid #b7c5d9;margin:14px 0;">';
		}
		$first = false;
		$out .= '<table cellspacing="0" cellpadding="0" style="border-collapse:separate;">' . $rows . '</table>';
	}

	return $out . '</fieldset>';
}

// ?manage&extensions, handles a toggle and returns the page
function handleExtensionsManage() {
	global $account, $isadmin;
	if (!isset($account['role']) || $account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
		fancyDie(__('Access denied'));
	}

	$notes = '';
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extension_action'], $_POST['extension_name'])
		&& is_string($_POST['extension_action']) && is_string($_POST['extension_name'])) {
		$name = $_POST['extension_name'];
		$list = extensionList();
		if (!isset($list[$name])) {
			$notes = manageInfo(__('Unknown extension.'));
		} else {
			$on = ($_POST['extension_action'] === 'enable');
			$wiped = true;
			if (!$on && extensionEnabled($name)) {
				$wiped = extensionWipeData($name);
			}
			if (extensionSet($name, $on)) {
				$label = $list[$name]['label'];
				$notes = manageInfo($on
					? sprintf(__('%s installed.'), htmlentities($label))
					: sprintf(__('%s uninstalled.'), htmlentities($label)));
				if (function_exists('manageLogAction')) {
					manageLogAction(($on ? __('Installed extension: ') : __('Uninstalled extension: ')) . $label);
				}
				// rebuild pages that depend on the toggle
				if (!$on && !$wiped) {
					$notes .= manageInfo(__('Some files could not be deleted. Check the folder permissions and remove them manually.'));
				}
				if ($on && in_array($name, array('polls', 'emoji', 'pending', 'trust', 'search', 'globalmessage'), true)) {
					extensionRequireFile($name);
				}
				if (!$on && in_array($name, array('polls', 'emoji', 'flags', 'stats', 'pending', 'search', 'home', 'globalmessage'), true)) {
					extensionRebuildBoards();
				} else if ($on && in_array($name, array('pending', 'search'), true)) {
					extensionRebuildBoards();
				}
				if (function_exists('rebuildPages')) {
					rebuildPages(true);
				}
			} else {
				$notes = manageInfo(__('The setting could not be saved. The install directory must be writable.'));
			}
		}
	}

	return $notes . manageExtensionsPage();
}
