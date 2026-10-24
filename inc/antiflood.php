<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// anti-flood, tuned from settings.php (SCHAN_ANTIFLOOD and the SCHAN_FLOOD* lines)
// imgboard.php loads this. logged in staff are never limited
//
//   antiFloodCheck($is_thread, $message)   before a post is accepted, stops the request if a limit is hit
//   antiFloodRecord($is_thread, $message)  after a post is accepted, remembers it
//
// per IP, over the last SCHAN_FLOODWINDOW seconds:
//   - max SCHAN_FLOODPOSTS posts
//   - max SCHAN_FLOODTHREADS new threads
//   - same message can't be posted twice (SCHAN_FLOODDUPES)
//   - max SCHAN_FLOODGLOBAL posts from everyone together (0 = off)
// hitting a per-IP limit blocks that IP for SCHAN_FLOODCOOLDOWN seconds
//
// state lives in flood.dat.php in the install folder, just hashed IPs and timestamps
// the .php name and the first line keep the web server from showing it

function antiFloodOpt($name, $default) {
	return defined($name) ? constant($name) : $default;
}

function antiFloodEnabled() {
	return (bool)antiFloodOpt('SCHAN_ANTIFLOOD', false);
}

function antiFloodFile() {
	return dirname(__DIR__) . '/flood.dat.php';
}

function antiFloodKey($ip) {
	return substr(hash('sha256', (string)$ip . '|' . (defined('SCHAN_TRIPSEED') ? SCHAN_TRIPSEED : '')), 0, 20);
}

function antiFloodHash($message) {
	$m = preg_replace('/\s+/u', ' ', trim(strip_tags((string)$message)));
	return $m === '' ? '' : substr(hash('sha256', mb_strtolower($m, 'UTF-8')), 0, 16);
}

// runs $fn($state) with the state file locked, saves it back if $fn returns an array
// if the file can't be opened we skip flood checks instead of blocking posts
function antiFloodWith($fn) {
	$path = antiFloodFile();
	$fh = @fopen($path, 'c+');
	if (!$fh) {
		return null;
	}
	if (!flock($fh, LOCK_EX)) {
		fclose($fh);
		return null;
	}
	$raw = stream_get_contents($fh);
	$raw = preg_replace('/^<\?php[^\n]*\n/', '', (string)$raw);
	$state = json_decode($raw, true);
	if (!is_array($state)) {
		$state = array();
	}
	$state += array('ips' => array(), 'global' => array(), 'blocked' => array());

	// drop old entries
	$now = time();
	$window = max(1, intval(antiFloodOpt('SCHAN_FLOODWINDOW', 300)));
	foreach ($state['ips'] as $k => $rows) {
		$rows = array_values(array_filter($rows, function ($r) use ($now, $window) {
			return $now - $r[0] < $window;
		}));
		if ($rows) {
			$state['ips'][$k] = $rows;
		} else {
			unset($state['ips'][$k]);
		}
	}
	$state['global'] = array_values(array_filter($state['global'], function ($t) use ($now, $window) {
		return $now - $t < $window;
	}));
	foreach ($state['blocked'] as $k => $until) {
		if ($until <= $now) {
			unset($state['blocked'][$k]);
		}
	}

	$result = $fn($state, $now);

	if (is_array($result) && isset($result['state'])) {
		ftruncate($fh, 0);
		rewind($fh);
		fwrite($fh, "<?php exit; ?>\n" . json_encode($result['state']));
		fflush($fh);
	}
	flock($fh, LOCK_UN);
	fclose($fh);
	return is_array($result) ? (isset($result['value']) ? $result['value'] : null) : null;
}

function antiFloodCheck($is_thread, $message = '') {
	if (!antiFloodEnabled()) {
		return;
	}

	$ip = remoteAddress();
	$key = antiFloodKey($ip);
	$max_posts = intval(antiFloodOpt('SCHAN_FLOODPOSTS', 10));
	$max_threads = intval(antiFloodOpt('SCHAN_FLOODTHREADS', 3));
	$max_global = intval(antiFloodOpt('SCHAN_FLOODGLOBAL', 0));
	$cooldown = max(0, intval(antiFloodOpt('SCHAN_FLOODCOOLDOWN', 600)));
	$dupes = (bool)antiFloodOpt('SCHAN_FLOODDUPES', true);
	$hash = antiFloodHash($message);

	$reason = antiFloodWith(function ($state, $now) use ($key, $is_thread, $max_posts, $max_threads, $max_global, $cooldown, $dupes, $hash) {
		$block = null;
		if (isset($state['blocked'][$key])) {
			return array('state' => $state, 'value' => array(sprintf(__('You are posting too fast. Please wait %d seconds.'), $state['blocked'][$key] - $now), false));
		}

		$rows = isset($state['ips'][$key]) ? $state['ips'][$key] : array();
		$threads = 0;
		$same = false;
		foreach ($rows as $r) {
			if (!empty($r[1])) {
				$threads++;
			}
			if ($dupes && $hash !== '' && isset($r[2]) && $r[2] === $hash) {
				$same = true;
			}
		}

		if ($max_global > 0 && count($state['global']) >= $max_global) {
			return array('state' => $state, 'value' => array(__('The board is receiving too many posts right now. Please try again in a moment.'), false));
		}

		if ($max_posts > 0 && count($rows) >= $max_posts) {
			$block = __('You are posting too fast. Please slow down.');
		} else if ($is_thread && $max_threads > 0 && $threads >= $max_threads) {
			$block = __('You are creating threads too fast. Please slow down.');
		} else if ($same) {
			$block = __('Duplicate post detected. Please do not repeat the same message.');
		}

		if ($block !== null) {
			if ($cooldown > 0) {
				$state['blocked'][$key] = $now + $cooldown;
			}
			return array('state' => $state, 'value' => array($block, true));
		}
		return array('state' => $state, 'value' => null);
	});

	if (is_array($reason)) {
		fancyDie($reason[0]);
	}
}

function antiFloodRecord($is_thread, $message = '') {
	if (!antiFloodEnabled()) {
		return;
	}
	$key = antiFloodKey(remoteAddress());
	$hash = antiFloodHash($message);
	antiFloodWith(function ($state, $now) use ($key, $is_thread, $hash) {
		$state['ips'][$key][] = array($now, $is_thread ? 1 : 0, $hash);
		$state['global'][] = $now;
		return array('state' => $state);
	});
}
