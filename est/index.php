<?php
// ---- settings ----
$BOARD      = 'board';                  // board name
$ANON       = '名無しさん';              // default poster name
$SALT       = 'change-this-secret';     // secret used to make poster IDs and IP hashes (change it!)
$ADMIN_PASS = 'change';              // moderator password (change it!)

$f = __DIR__ . '/db.json';
date_default_timezone_set('UTC');

ini_set('session.cookie_httponly', '1');
session_start();

function new_token() {
    return function_exists('random_bytes') ? bin2hex(random_bytes(16)) : md5(uniqid(mt_rand(), true));
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = new_token();

$IS_ADMIN = !empty($_SESSION['admin']);
$CSRF     = $_SESSION['csrf'];
$IPH      = sha1($_SERVER['REMOTE_ADDR'] . $SALT . 'ip');   // hashed IP, used for bans
$CUR      = 0;                                              // thread being viewed (0 = board)

function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function cut($s, $n) {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n * 3);
}

function db($f, $write = null) {
    $fp = @fopen($f, 'c+');
    if (!$fp) {
        die('Cannot open db.json. Make it writable by the web server (touch db.json; chmod 666 db.json).');
    }
    flock($fp, $write ? LOCK_EX : LOCK_SH);
    $d = json_decode(stream_get_contents($fp), true);
    if (!is_array($d)) $d = array();

    // defaults
    if (!isset($d['next'])) $d['next'] = 1;
    if (!isset($d['threads']) || !is_array($d['threads'])) $d['threads'] = array();
    if (!isset($d['bans'])    || !is_array($d['bans']))    $d['bans'] = array();

    // old single-thread format (posts at top level) becomes a thread
    if (isset($d['posts'])) {
        if (is_array($d['posts']) && $d['posts']) {
            $last = end($d['posts']);
            $d['threads'][] = array(
                'id'    => 0,
                'title' => isset($d['thread']) ? $d['thread'] : 'Free talk thread',
                'bump'  => isset($last['time']) ? $last['time'] : time(),
                'posts' => $d['posts'],
            );
        }
        unset($d['posts'], $d['thread']);
    }

    // repair: every thread needs a unique id >= 1 and at least one post
    $max = $d['next'] - 1;
    foreach ($d['threads'] as $th) {
        if (isset($th['id']) && (int)$th['id'] > $max) $max = (int)$th['id'];
    }
    $seen = array();
    $clean = array();
    foreach ($d['threads'] as $th) {
        if (!isset($th['posts']) || !is_array($th['posts']) || !$th['posts']) continue;
        $id = isset($th['id']) ? (int)$th['id'] : 0;
        if ($id < 1 || isset($seen[$id])) $id = ++$max;
        $seen[$id] = true;
        $th['id'] = $id;
        $th['posts'] = array_values($th['posts']);
        if (!isset($th['title'])) $th['title'] = '';
        if (!isset($th['bump']))  $th['bump'] = time();
        $clean[] = $th;
    }
    $d['threads'] = $clean;
    if ($d['next'] < $max + 1) $d['next'] = $max + 1;

    if ($write) {
        $d = $write($d);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $d;
}

// a small moderator button (its own tiny form)
function mod_form($label, $fields, $confirm = true) {
    global $CSRF, $CUR;
    $o = '<form class="mod" method="post" action="' . h($_SERVER['PHP_SELF']) . '"'
       . ($confirm ? ' onsubmit="return confirm(\'Are you sure?\')"' : '') . '>';
    $fields['back'] = $CUR;
    foreach ($fields as $k => $v) {
        $o .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return $o . '<input type="hidden" name="csrf" value="' . h($CSRF) . '">'
              . '<input type="submit" value="' . h($label) . '"></form>';
}

// a greyed-out button (used when a post has no stored IP to ban)
function mod_off($label) {
    return '<input class="modoff" type="submit" value="' . h($label) . '" disabled title="No IP stored (posted before bans existed)">';
}

// one post: $full = true on the thread page (>>N link fills the reply form)
function post_html($tid, $i, $p, $full) {
    global $IS_ADMIN;
    $deleted = !empty($p['deleted']);
    $hasip = !empty($p['ip']);

    $ctl = '';
    if ($IS_ADMIN) {
        if (!$deleted && $i > 1) $ctl .= ' ' . mod_form('delete', array('action' => 'del_post', 't' => $tid, 'n' => $i));
        $ctl .= ' ' . ($hasip ? mod_form('ban', array('action' => 'ban', 't' => $tid, 'n' => $i)) : mod_off('ban'));
        if (!$deleted && $i > 1) {
            $ctl .= ' ' . ($hasip ? mod_form('delete+ban', array('action' => 'del_post', 'ban' => 1, 't' => $tid, 'n' => $i)) : mod_off('delete+ban'));
        }
    }

    $idattr = $full ? ' id="' . $i . '"' : '';
    if ($deleted) {
        return '<div class="reply"' . $idattr . '><h3><span class="replynum">' . $i . '</span> : '
             . '<span class="deleted">(moderator deleted post)</span>' . $ctl . '</h3></div>' . "\n";
    }

    $m = nl2br(preg_replace('/&gt;&gt;(\d+)/', '<a href="?t=' . $tid . '#$1">&gt;&gt;$1</a>', h($p['msg'])));
    if ($full) {
        $ref = '<a class="replylink" href="#form" onclick="document.f.msg.value+=\'>>' . $i . '\\n\'">&gt;&gt;' . $i . '</a>';
    } else {
        $ref = '<a class="replylink" href="?t=' . $tid . '#' . $i . '">&gt;&gt;' . $i . '</a>';
    }
    return '<div class="reply"' . $idattr . '>'
         . '<h3><span class="replynum">' . $i . '</span> : '
         . '<span class="postername">' . h($p['name']) . '</span> ' . $ref . ' : '
         . date('Y/m/d H:i:s', $p['time']) . ' ID:' . h($p['id']) . $ctl . '</h3>'
         . '<div class="replytext">' . $m . '</div>'
         . '</div>' . "\n";
}

// one thread box. $full = whole thread + reply form; otherwise preview + Reply button
function thread_html($th, $full) {
    global $IS_ADMIN;
    $cnt = count($th['posts']);
    $del = !empty($th['deleted']);
    $o = '<div class="thread">';
    if ($del) {
        $o .= '<h2><a href="?t=' . $th['id'] . '">' . $th['id'] . ': <span class="deleted">(moderator deleted post)</span></a></h2>';
        return $o . '</div>' . "\n";
    }
    $o .= '<h2><a href="?t=' . $th['id'] . '">' . $th['id'] . ': ' . h($th['title']) . '</a> <small>(' . $cnt . ')</small></h2>';
    if ($IS_ADMIN) {
        $o .= '<div class="modbar">' . mod_form('delete thread', array('action' => 'del_thread', 't' => $th['id']));
        if (!empty($th['posts'][0]['ip'])) {
            $o .= ' ' . mod_form('delete thread+ban OP', array('action' => 'del_thread', 'ban' => 1, 't' => $th['id']));
        } else {
            $o .= ' ' . mod_off('delete thread+ban OP');
        }
        $o .= '</div>';
    }
    $o .= '<div class="replies">';
    if ($full) {
        foreach ($th['posts'] as $k => $p) {
            $o .= post_html($th['id'], $k + 1, $p, true);
        }
    } else {
        $start = max(1, $cnt - 5);
        $show = array(0);
        for ($j = $start; $j < $cnt; $j++) $show[] = $j;
        foreach ($show as $j) {
            $o .= post_html($th['id'], $j + 1, $th['posts'][$j], false);
            if ($j == 0 && $start > 1) {
                $o .= '<div class="replyabbrev">' . ($start - 1) . ' posts omitted</div>';
            }
        }
    }
    $o .= '</div>';
    if ($full) {
        $o .= '<form name="f" id="form" method="post" action="' . h($_SERVER['PHP_SELF']) . '">'
            . '<input type="hidden" name="t" value="' . $th['id'] . '">'
            . 'name: <input name="name"><br>'
            . '<textarea name="msg" rows="5" cols="60"></textarea><br>'
            . '<input type="submit" value="Reply"> <a href="?">Back to board</a>'
            . '</form>';
    } else {
        $o .= '<form method="get" action=""><input type="hidden" name="t" value="' . $th['id'] . '"><input type="submit" value="Reply"></form>';
    }
    return $o . '</div>' . "\n";
}

// ---- POST requests ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // moderator actions
    if (isset($_POST['action'])) {
        $a = $_POST['action'];

        if ($a === 'login') {
            if ($ADMIN_PASS !== '' && isset($_POST['pass']) && hash_equals($ADMIN_PASS, (string)$_POST['pass'])) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['csrf'] = new_token();
                header('Location: ' . $_SERVER['PHP_SELF']);
            } else {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?admin&bad=1');
            }
            exit;
        }

        // everything below needs a logged-in moderator and a valid token
        if (!$IS_ADMIN || !isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            header('HTTP/1.1 403 Forbidden');
            die('Forbidden');
        }

        if ($a === 'logout') {
            $_SESSION = array();
            session_destroy();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        $t   = isset($_POST['t']) ? (int)$_POST['t'] : 0;
        $n   = isset($_POST['n']) ? (int)$_POST['n'] : 0;
        $ban = !empty($_POST['ban']);
        $hash = isset($_POST['hash']) ? (string)$_POST['hash'] : '';

        db($f, function ($d) use ($a, $t, $n, $ban, $hash) {
            if ($a === 'unban') {
                unset($d['bans'][$hash]);
                return $d;
            }
            foreach ($d['threads'] as $k => $th) {
                if ($th['id'] != $t) continue;
                $idx = $n - 1;
                if ($a === 'del_thread') {
                    if ($ban && !empty($th['posts'][0]['ip'])) $d['bans'][$th['posts'][0]['ip']] = time();
                    foreach ($th['posts'] as $j => $p) {
                        $d['threads'][$k]['posts'][$j]['msg'] = '';
                        $d['threads'][$k]['posts'][$j]['name'] = '';
                        $d['threads'][$k]['posts'][$j]['deleted'] = true;
                    }
                    $d['threads'][$k]['title'] = '';
                    $d['threads'][$k]['deleted'] = true;
                } elseif (isset($th['posts'][$idx])) {
                    $p = $th['posts'][$idx];
                    if (($a === 'ban' || $ban) && !empty($p['ip'])) $d['bans'][$p['ip']] = time();
                    if ($a === 'del_post' && $idx > 0) {
                        $d['threads'][$k]['posts'][$idx]['msg'] = '';
                        $d['threads'][$k]['posts'][$idx]['name'] = '';
                        $d['threads'][$k]['posts'][$idx]['deleted'] = true;
                    }
                }
            }
            return $d;
        });
        $back = isset($_POST['back']) ? (int)$_POST['back'] : 0;
        header('Location: ' . $_SERVER['PHP_SELF'] . ($back ? '?t=' . $back : ''));
        exit;
    }

    // normal posting: new thread (no t) or reply (t)
    $n = cut(trim(isset($_POST['name']) ? $_POST['name'] : ''), 40);
    if ($n === '') $n = $ANON;
    $m = cut(trim(isset($_POST['msg']) ? $_POST['msg'] : ''), 2000);
    $title = cut(trim(isset($_POST['title']) ? $_POST['title'] : ''), 80);
    $t = isset($_POST['t']) ? (int)$_POST['t'] : 0;
    $go = 0;
    $banned = false;
    if ($m !== '') {
        db($f, function ($d) use ($n, $m, $title, $t, $SALT, $IPH, &$go, &$banned) {
            if (isset($d['bans'][$IPH])) {
                $banned = true;
                return $d;
            }
            $post = array(
                'name' => $n,
                'msg'  => $m,
                'time' => time(),
                'id'   => substr(base64_encode(sha1($_SERVER['REMOTE_ADDR'] . date('Ymd') . $SALT, true)), 0, 8),
                'ip'   => $IPH,
            );
            if ($t) {
                foreach ($d['threads'] as $k => $th) {
                    if ($th['id'] != $t) continue;
                    $go = $t;
                    if (!empty($th['deleted'])) break;
                    $last = end($th['posts']);
                    // ignore an identical post made in the last 10 seconds (double submit)
                    if ($last['msg'] === $m && $last['id'] === $post['id'] && time() - $last['time'] < 10) break;
                    $d['threads'][$k]['posts'][] = $post;
                    $d['threads'][$k]['bump'] = time();
                }
            } elseif ($title !== '') {
                foreach ($d['threads'] as $th) {
                    if ($th['title'] === $title && $th['posts'][0]['msg'] === $m && time() - $th['bump'] < 10) {
                        $go = $th['id'];
                        return $d;
                    }
                }
                $go = $d['next'];
                $d['next'] = $go + 1;
                $d['threads'][] = array(
                    'id'    => $go,
                    'title' => $title,
                    'bump'  => time(),
                    'posts' => array($post),
                );
            }
            return $d;
        });
    }
    if ($banned) {
        header('HTTP/1.1 403 Forbidden');
        die('You are banned.');
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . ($go ? '?t=' . $go : ''));
    exit;
}

// ---- view ----
$db = db($f);
$tid = isset($_GET['t']) ? (int)$_GET['t'] : 0;
$CUR = $tid;
$thread = null;
foreach ($db['threads'] as $th) {
    if ($th['id'] == $tid) $thread = $th;
}
$show_login = isset($_GET['admin']) && !$IS_ADMIN;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($BOARD); ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body class="mainpage">

<div class="outerbox" id="titlebox"><div class="innerbox"><h1><?php echo h($BOARD); ?></h1></div></div>

<?php if ($show_login): ?>

<div class="outerbox"><div class="innerbox">
<?php if (isset($_GET['bad'])) echo '<p>Wrong password.</p>'; ?>
<form method="post" action="<?php echo h($_SERVER['PHP_SELF']); ?>">
<input type="hidden" name="action" value="login">
password: <input type="password" name="pass"> <input type="submit" value="login">
</form>
</div></div>

<?php elseif ($tid && !$thread): ?>

<div class="outerbox"><div class="innerbox">Thread not found. <a href="?">Back to board</a></div></div>

<?php elseif ($thread): ?>

<div id="posts">
<?php echo thread_html($thread, true); ?>
</div>

<?php else: ?>

<div id="posts">
<?php
$threads = $db['threads'];
usort($threads, function ($a, $b) { return $b['bump'] - $a['bump']; });
foreach ($threads as $th) echo thread_html($th, false);
if (!$threads) echo '<p>No threads yet.</p>';
?>
</div>

<div class="outerbox" id="createbox"><div class="innerbox">
<h2>New thread</h2>
<form method="post" action="<?php echo h($_SERVER['PHP_SELF']); ?>">
title: <input name="title" size="40"><br>
name: <input name="name"><br>
<textarea name="msg" rows="5" cols="60"></textarea><br>
<input type="submit" value="Create thread">
</form>
</div></div>

<?php if ($IS_ADMIN): ?>
<div class="outerbox"><div class="innerbox">
<h2>Bans</h2>
<?php
if (!$db['bans']) echo 'None.';
$bn = 0;
foreach ($db['bans'] as $hash => $when) {
    $bn++;
    echo '<div>Ban ' . $bn . ' (' . date('Y/m/d H:i', $when) . ' UTC) '
       . mod_form('unban', array('action' => 'unban', 'hash' => $hash)) . '</div>';
}
?>
</div></div>
<?php endif; ?>

<?php endif; ?>

<div id="footer">
<?php
if ($IS_ADMIN) {
    echo 'moderator mode ' . mod_form('logout', array('action' => 'logout'), false);
} else {
    echo '[<a href="?admin">admin</a>]';
}
?>
</div>

</body>
</html>
