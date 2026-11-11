<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schan installer</title>
</head>
<body>

<?php
/*
 * schan installer (barebones)
 * step 1: checks php, extensions, files, folder permissions
 * step 2: asks for db details, tests them, writes settings.php and creates .installed.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
session_start();

$root = __DIR__;
$settingsPath = $root . '/settings.php';
$lockPath = $root . '/.installed.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function page($title, $body) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' - Schan</title><style>'
        . 'body{font-family:monospace;margin:1em;}'
        . 'table{border-collapse:collapse;margin:0.5em 0;}'
        . 'th,td{border:1px solid #ccc;padding:0.2em 0.5em;text-align:left;vertical-align:top;}'
        . 'th{background:#f0f0f0;}'
        . '.ok{color:green;}.warn{color:orange;}.fail{color:red;}'
        . '.group td{background:#e8e8e8;font-weight:bold;}'
        . '.err{color:red;border:1px solid red;padding:0.5em;margin:0.5em 0;}'
        . '.note{border:1px solid #aaa;padding:0.5em;margin:0.5em 0;}'
        . 'label{display:block;margin-top:0.8em;}'
        . 'input[type=text],input[type=password],input[type=number]{width:100%;max-width:300px;}'
        . '.btn{display:inline-block;border:1px solid #888;padding:0.3em 0.8em;margin:0.5em 0.5em 0 0;text-decoration:none;color:black;background:#eee;}'
        . '.btn[disabled]{opacity:0.5;}'
        . 'small{color:#555;}'
        . '</style></head><body><h1>Schan installer</h1>' . $body . '</body></html>';
    exit;
}

// already installed?
function alreadyInstalled($settingsPath, $lockPath) {
    if (is_file($lockPath)) {
        return true;
    }
    if (is_file($settingsPath)) {
        $raw = @file_get_contents($settingsPath);
        if ($raw !== false && preg_match('/define\(\s*\'Schan_DBNAME\'\s*,\s*\'[^\']+\'/', $raw)) {
            return true;
        }
    }
    return false;
}

if (alreadyInstalled($settingsPath, $lockPath)) {
    http_response_code(403);
    page('Already installed', '<p>Schan is already installed. The installer is disabled.</p>'
        . '<p><a class="btn" href="imgboard.php">Board</a><a class="btn" href="imgboard.php?manage">Management</a></p>'
        . '<p><small>To run the installer again, delete <code>.installed.php</code> and blank the database name in <code>settings.php</code>.</small></p>');
}

// requirement checks
function ini_bytes($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '-1') return -1;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

function runChecks($root, $settingsPath) {
    $rows = array();
    $add = function ($name, $needed, $found, $status, $note = '') use (&$rows) {
        $rows[] = array(null, $name, $needed, $found, $status, $note);
    };
    $group = function ($title) use (&$rows) {
        $rows[] = array($title, '', '', '', '', '');
    };
    $ext = function ($name, $required, $why) use ($add) {
        $has = extension_loaded($name);
        $add('PHP extension: ' . $name, $required ? 'Required' : 'Recommended', $has ? 'Loaded' : 'Missing', $has ? 'ok' : ($required ? 'fail' : 'warn'), $why);
    };

    $group('PHP');
    $add('PHP version', '7.3+', PHP_VERSION, version_compare(PHP_VERSION, '7.3.0', '>=') ? 'ok' : 'fail');
    $ext('mysqli', true, 'Database access');
    $ext('gd', true, 'Thumbnails');
    $ext('json', true, 'Settings and JSON');
    $ext('session', true, 'Staff login');
    $ext('fileinfo', true, 'File type detection');
    $ext('mbstring', false, 'Non-English text');
    $ext('ctype', false, 'Search');
    $ext('curl', false, 'Embeds / upload-by-URL');
    $add('file_uploads', 'On', ini_get('file_uploads') ? 'On' : 'Off', ini_get('file_uploads') ? 'ok' : 'fail', 'Needed for uploads');
    $up = ini_bytes(ini_get('upload_max_filesize'));
    $post = ini_bytes(ini_get('post_max_size'));
    $add('upload_max_filesize', '2M+', ini_get('upload_max_filesize'), ($up === -1 || $up >= 2*1024*1024) ? 'ok' : 'warn', 'Max upload size');
    $add('post_max_size', '2M+', ini_get('post_max_size'), ($post === -1 || $post >= 2*1024*1024) ? 'ok' : 'warn', 'Must be >= upload limit');

    $group('Files');
    $files = array(
        'imgboard.php', 'settings.php',
        'inc/defines.php', 'inc/functions.php', 'inc/html.php', 'inc/extensions.php', 'inc/config.php',
        'inc/database/mysqli_link.php', 'inc/database/mysqli.php', 'inc/database/database.php',
    );
    foreach ($files as $f) {
        $ok = is_file($root . '/' . $f) && is_readable($root . '/' . $f);
        $add($f, 'Present & readable', $ok ? 'Found' : 'Missing', $ok ? 'ok' : 'fail');
    }
    $ok = is_dir($root . '/css');
    $add('css/', 'Present', $ok ? 'Found' : 'Missing', $ok ? 'ok' : 'warn', 'Stylesheets');

    $group('Writable folders');
    $wr = is_writable($root);
    $add('Install folder', 'Writable', $wr ? 'Writable' : 'Not writable', $wr ? 'ok' : 'fail', 'Board files, .installed.php');
    if (is_file($settingsPath)) {
        $wr = is_writable($settingsPath);
        $add('settings.php', 'Writable', $wr ? 'Writable' : 'Not writable', $wr ? 'ok' : 'fail', 'Database details go here');
    } else {
        $add('settings.php', 'Must exist', 'Missing', 'fail', 'Upload blank settings.php');
    }
    foreach (array('static', 'static/emojis', 'inc') as $d) {
        if (is_dir($root . '/' . $d)) {
            $wr = is_writable($root . '/' . $d);
            $add($d . '/', $d === 'inc' ? 'Readable' : 'Writable', $d === 'inc' ? 'Readable' : ($wr ? 'Writable' : 'Not writable'), ($d === 'inc' || $wr) ? 'ok' : 'warn', $d === 'static' || $d === 'static/emojis' ? 'Custom emoji' : '');
        } else if ($d !== 'inc') {
            $add($d . '/', 'Writable (or creatable)', 'Missing', is_writable($root) ? 'ok' : 'warn', 'Will be created when needed');
        }
    }
    $tmp = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
    $wr = is_dir($tmp) && is_writable($tmp);
    $add('Temporary upload folder', 'Writable', $tmp . ($wr ? '' : ' (not writable)'), $wr ? 'ok' : 'warn', 'PHP stores uploads here');

    $group('Optional');
    $geo = $root . '/inc/GeoLite2-Country.mmdb';
    $add('inc/GeoLite2-Country.mmdb', 'Only for flags', is_file($geo) ? 'Found' : 'Not found', is_file($geo) ? 'ok' : 'warn', 'Skip if not using flags');

    return $rows;
}

function checksFailed($rows) {
    foreach ($rows as $r) {
        if ($r[4] === 'fail') return true;
    }
    return false;
}

function renderChecks($rows) {
    $labels = array('ok' => '<span class="ok">OK</span>', 'warn' => '<span class="warn">Warning</span>', 'fail' => '<span class="fail">Fix</span>');
    $h = '<table><tr><th>Check</th><th>Needed</th><th>Found</th><th>Status</th></tr>';
    foreach ($rows as $r) {
        if ($r[0] !== null) {
            $h .= '<tr class="group"><td colspan="4">' . h($r[0]) . '</td></tr>';
            continue;
        }
        $h .= '<tr><td>' . h($r[1]) . ($r[5] !== '' ? '<br><small>' . h($r[5]) . '</small>' : '') . '</td><td>' . h($r[2]) . '</td><td>' . h($r[3]) . '</td><td>' . $labels[$r[4]] . '</td></tr>';
    }
    return $h . '</table>';
}

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$rows = runChecks($root, $settingsPath);
$blocked = checksFailed($rows);

// step 1
if ($step !== 2) {
    $body = '<p>Step 1 of 2: requirements and permissions.</p>' . renderChecks($rows);
    if ($blocked) {
        $body .= '<div class="err">Fix the items marked "Fix" above. On Linux: <code>chown -R www-data:www-data .</code> or <code>chmod -R u+rwX .</code>. Install missing PHP extensions, then retry.</div>'
            . '<a class="btn" href="install.php">Check again</a><span class="btn" disabled>Continue</span>';
    } else {
        $body .= '<p><small>Warnings are optional. All required checks pass.</small></p>'
            . '<a class="btn" href="install.php">Check again</a><a class="btn" href="install.php?step=2">Continue</a>';
    }
    page('Requirements', $body);
}

// step 2
if ($blocked) {
    header('Location: install.php');
    exit;
}

if (empty($_SESSION['inst_token'])) {
    $_SESSION['inst_token'] = bin2hex(random_bytes(16));
}

$detected_root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$v = array(
    'host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'pass' => '',
    'create' => '', 'admin' => '', 'admin2' => '', 'board' => 'b', 'tz' => 'UTC', 'rooturl' => $detected_root,
);
$error = '';

function setDefine($src, $key, $php) {
    $re = '/^(define\(\s*\'' . preg_quote($key, '/') . '\'\s*,\s*)(?:\'(?:[^\'\\\\]|\\\\.)*\'|-?[0-9]+|true|false)(\s*\);)/m';
    $n = 0;
    $out = preg_replace_callback($re, function ($m) use ($php) {
        return $m[1] . $php . $m[2];
    }, $src, 1, $n);
    if ($n !== 1 || $out === null) {
        throw new Exception('settings.php has no define for ' . $key . '. Upload the blank settings.php.');
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) {
            $v[$k] = trim($_POST[$k]);
        }
    }
    $v['pass'] = isset($_POST['pass']) && is_string($_POST['pass']) ? $_POST['pass'] : '';
    $v['admin'] = isset($_POST['admin']) && is_string($_POST['admin']) ? $_POST['admin'] : '';
    $v['admin2'] = isset($_POST['admin2']) && is_string($_POST['admin2']) ? $_POST['admin2'] : '';

    try {
        if (!isset($_POST['token']) || !hash_equals($_SESSION['inst_token'], (string)$_POST['token'])) {
            throw new Exception('Form expired. Reload and try again.');
        }
        if ($v['host'] === '' || preg_match('/[\s\'"]/', $v['host'])) {
            throw new Exception('Enter a valid database host.');
        }
        if (!ctype_digit($v['port']) || intval($v['port']) > 65535) {
            throw new Exception('Port must be 0-65535 (0 for UNIX socket).');
        }
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $v['name'])) {
            throw new Exception('Database name: letters, numbers, underscore, dollar, hyphen only.');
        }
        if ($v['user'] === '') {
            throw new Exception('Enter the database username.');
        }
        // no min length check on the admin password
        if ($v['admin'] !== $v['admin2']) {
            throw new Exception('Admin passwords do not match.');
        }
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $v['board'])) {
            throw new Exception('Board name: lowercase letters, numbers, underscore, hyphen only.');
        }
        $reserved = array('css','js','inc','res','src','thumb','emoji','static','boards','install','mod','search','stats','flags','catalog','index','imgboard','settings','sticky','lock');
        if (in_array($v['board'], $reserved, true)) {
            throw new Exception('That board name is reserved. Choose another.');
        }
        if (!in_array($v['tz'], timezone_identifiers_list(), true)) {
            throw new Exception('Unknown timezone.');
        }
        if ($v['rooturl'] !== '' && !preg_match('#^/[A-Za-z0-9._~/-]*$#', $v['rooturl'])) {
            throw new Exception('URL path must be empty or start with / (e.g. /board).');
        }
        $v['rooturl'] = rtrim($v['rooturl'], '/');

        mysqli_report(MYSQLI_REPORT_OFF);
        $port = intval($v['port']);
        $db = @new mysqli($v['host'], $v['user'], $v['pass'], '', $port > 0 ? $port : null);
        if ($db->connect_errno) {
            throw new Exception('Could not connect: ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        if (!empty($_POST['create'])) {
            if (!$db->query('CREATE DATABASE IF NOT EXISTS `' . $db->real_escape_string($v['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
                throw new Exception('Could not create database: ' . $db->error);
            }
        }
        if (!$db->select_db($v['name'])) {
            throw new Exception('Could not open database "' . $v['name'] . '": ' . $db->error . ' (tick "Create database" if needed).');
        }
        $probe = 'schan_probe_' . bin2hex(random_bytes(4));
        if (!$db->query('CREATE TABLE `' . $probe . '` (`id` int NOT NULL)') || !$db->query('DROP TABLE `' . $probe . '`')) {
            throw new Exception('Database user cannot create tables: ' . $db->error);
        }
        $db->close();

        $src = @file_get_contents($settingsPath);
        if ($src === false) {
            throw new Exception('Could not read settings.php.');
        }
        $geo = $root . '/inc/GeoLite2-Country.mmdb';
        $src = setDefine($src, 'SCHAN_ROOTURL', var_export($v['rooturl'], true));
        $src = setDefine($src, 'SCHAN_TIMEZONE', var_export($v['tz'], true));
        $src = setDefine($src, 'SCHAN_GEOIPDB', var_export(is_file($geo) ? $geo : '', true));
        $src = setDefine($src, 'SCHAN_DEFAULTBOARD', var_export($v['board'], true));
        $src = setDefine($src, 'SCHAN_TRIPSEED', var_export(bin2hex(random_bytes(16)), true));
        $src = setDefine($src, 'SCHAN_ADMINPASS', var_export($v['admin'], true));
        $src = setDefine($src, 'SCHAN_DBMODE', var_export('mysqli', true));
        $src = setDefine($src, 'SCHAN_DBHOST', var_export($v['host'], true));
        $src = setDefine($src, 'SCHAN_DBPORT', (string)$port);
        $src = setDefine($src, 'SCHAN_DBUSERNAME', var_export($v['user'], true));
        $src = setDefine($src, 'SCHAN_DBPASSWORD', var_export($v['pass'], true));
        $src = setDefine($src, 'SCHAN_DBNAME', var_export($v['name'], true));

        try {
            token_get_all($src, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new Exception('Generated settings.php is invalid PHP (' . $e->getMessage() . '). Nothing written.');
        }

        $tmpFile = $root . '/.settings.php.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmpFile, $src, LOCK_EX) === false || !@rename($tmpFile, $settingsPath)) {
            @unlink($tmpFile);
            throw new Exception('Could not write settings.php. Check permissions.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($settingsPath, true);
        }

        $lock = "<?php http_response_code(404); exit; ?>\n" . json_encode(array('installed' => date('c'), 'php' => PHP_VERSION));
        $lockOk = @file_put_contents($lockPath, $lock, LOCK_EX) !== false;

        unset($_SESSION['inst_token']);
        $body = '<h2>Installed</h2><p>Settings saved. Installer is locked.</p>'
            . ($lockOk ? '' : '<div class="err">Could not create <code>.installed.php</code>. Create an empty file with that name, or the installer stays open until you blank the database name again.</div>')
            . '<div class="note"><b>Login:</b> username <code>admin</code>, with your chosen password.<br>'
            . 'Database tables are created automatically on first board load.<br>'
            . 'Passwords are stored in plain text in <code>settings.php</code>. Keep it private. After creating staff accounts, blank <code>SCHAN_ADMINPASS</code>.<br>'
            . 'For safety, delete <code>install.php</code> from the server.</div>'
            . '<a class="btn" href="imgboard.php?manage">Management panel</a><a class="btn" href="imgboard.php">Board</a>';
        page('Installed', $body);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$tzoptions = '';
foreach (timezone_identifiers_list() as $tz) {
    $tzoptions .= '<option value="' . h($tz) . '"' . ($tz === $v['tz'] ? ' selected' : '') . '>' . h($tz) . '</option>';
}

$body = '<p>Step 2 of 2: database and administrator.</p>'
    . ($error !== '' ? '<div class="err">' . h($error) . '</div>' : '')
    . '<form method="post" action="install.php?step=2" autocomplete="off">'
    . '<input type="hidden" name="token" value="' . h($_SESSION['inst_token']) . '">'
    . '<h2>Database (MySQL / MariaDB)</h2>'
    . '<label>Host</label><input type="text" name="host" value="' . h($v['host']) . '">'
    . '<label>Port <small>(0 for UNIX socket)</small></label><input type="number" name="port" min="0" max="65535" value="' . h($v['port']) . '">'
    . '<label>Database name</label><input type="text" name="name" value="' . h($v['name']) . '">'
    . '<label>Username</label><input type="text" name="user" value="' . h($v['user']) . '">'
    . '<label>Password</label><input type="password" name="pass" value="">'
    . '<label style="font-weight:normal;"><input type="checkbox" name="create" value="1"' . (!empty($_POST['create']) ? ' checked' : '') . '> Create database if it does not exist</label>'
    . '<h2>Administrator</h2>'
    . '<label>Admin password <small>(username "admin")</small></label><input type="password" name="admin" value="">'
    . '<label>Repeat password</label><input type="password" name="admin2" value="">'
    . '<h2>Board</h2>'
    . '<label>Default board name <small>(e.g. b)</small></label><input type="text" name="board" value="' . h($v['board']) . '">'
    . '<label>Timezone</label><select name="tz">' . $tzoptions . '</select>'
    . '<label>URL path of this folder <small>(empty if at web root)</small></label><input type="text" name="rooturl" value="' . h($v['rooturl']) . '">'
    . '<p><button class="btn" type="submit">Install</button><a class="btn" href="install.php">Back</a></p>'
    . '</form>';
page('Database', $body);
?>
</body>
</html>
