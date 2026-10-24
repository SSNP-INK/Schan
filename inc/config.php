<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * settings editor for the manage panel (super admins only)
 *
 * reads settings.php with the tokenizer (nothing is eval'd), shows every define() and
 * $schan_* array in a form, writes changed values back in place, comments/layout stay
 *   - super admins only, CSRF token
 *   - values are written as PHP literals made by this file, never raw text
 *   - new file is syntax checked and re-parsed to confirm the saved values
 *   - settings.backup.php is written first (.php so it's never served as text)
 */

function configFile() {
	return 'settings.php';
}

function configH($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function configToken() {
	if (empty($_SESSION['schan_cfg_token'])) {
		$_SESSION['schan_cfg_token'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : md5(uniqid(mt_rand(), true));
	}
	return $_SESSION['schan_cfg_token'];
}

function configSecretName($name) {
	return (bool)preg_match('/PASS|SECRET|TRIPSEED/', $name);
}

// parsing

function configIsInsignificant($t) {
	return $t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT;
}

function configNextSig($tokens, $i) {
	$n = count($tokens);
	while ($i < $n && configIsInsignificant($tokens[$i])) {
		$i++;
	}
	return $i;
}

function configStringLiteral($lit) {
	$q = substr($lit, 0, 1);
	$inner = (string)substr($lit, 1, -1);
	if ($q === "'") {
		return strtr($inner, array("\\\\" => "\\", "\\'" => "'"));
	}
	if ($q === '"' && strpos($inner, '\\') === false && strpos($inner, '$') === false) {
		return $inner;
	}
	return null;
}

function configParseValue($sig, &$p) {
	$n = count($sig);
	if ($p >= $n) {
		throw new Exception('eof');
	}
	$t = $sig[$p];
	$text = $t[1];

	if ($t[0] === null && $text === '-') {
		$p++;
		if ($p < $n && $sig[$p][0] === T_LNUMBER) {
			$v = -intval($sig[$p][1], 0);
			$p++;
			return $v;
		}
		if ($p < $n && $sig[$p][0] === T_DNUMBER) {
			$v = -floatval($sig[$p][1]);
			$p++;
			return $v;
		}
		throw new Exception('bad number');
	}
	if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
		$v = configStringLiteral($text);
		if ($v === null) {
			throw new Exception('complex string');
		}
		$p++;
		return $v;
	}
	if ($t[0] === T_LNUMBER) {
		$p++;
		return intval($text, 0);
	}
	if ($t[0] === T_DNUMBER) {
		$p++;
		return floatval($text);
	}
	if ($t[0] === T_STRING) {
		$l = strtolower($text);
		if ($l === 'true' || $l === 'false' || $l === 'null') {
			$p++;
			return $l === 'true' ? true : ($l === 'false' ? false : null);
		}
		throw new Exception('constant');
	}

	$close = null;
	if ($t[0] === T_ARRAY && $p + 1 < $n && $sig[$p + 1][0] === null && $sig[$p + 1][1] === '(') {
		$close = ')';
		$p += 2;
	} else if ($t[0] === null && $text === '[') {
		$close = ']';
		$p += 1;
	}
	if ($close === null) {
		throw new Exception('unsupported');
	}

	$items = array();
	$auto = 0;
	while (true) {
		if ($p >= $n) {
			throw new Exception('eof');
		}
		if ($sig[$p][0] === null && $sig[$p][1] === $close) {
			$p++;
			break;
		}
		$v = configParseValue($sig, $p);
		if ($p < $n && $sig[$p][0] === T_DOUBLE_ARROW) {
			$p++;
			$val = configParseValue($sig, $p);
			if (!is_int($v) && !is_string($v)) {
				throw new Exception('bad key');
			}
			$items[$v] = $val;
			if (is_int($v) && $v >= $auto) {
				$auto = $v + 1;
			}
		} else {
			$items[$auto++] = $v;
		}
		if ($p < $n && $sig[$p][0] === null && $sig[$p][1] === ',') {
			$p++;
			continue;
		}
		if ($p < $n && $sig[$p][0] === null && $sig[$p][1] === $close) {
			$p++;
			break;
		}
		throw new Exception('syntax');
	}
	return $items;
}

// array(first significant index, last significant index, terminator index) or null
function configValueSpan($tokens, $from, $term) {
	$n = count($tokens);
	$depth = 0;
	$first = null;
	$last = null;
	for ($m = $from; $m < $n; $m++) {
		$t = $tokens[$m];
		if ($t[0] === null) {
			if ($depth === 0 && $t[1] === $term) {
				return $first === null ? null : array($first, $last, $m);
			}
			if ($t[1] === '(' || $t[1] === '[' || $t[1] === '{') {
				$depth++;
			} else if ($t[1] === ')' || $t[1] === ']' || $t[1] === '}') {
				$depth--;
			}
		}
		if (!configIsInsignificant($t)) {
			if ($first === null) {
				$first = $m;
			}
			$last = $m;
		}
	}
	return null;
}

function configTrailingComment($tokens, $idx) {
	$n = count($tokens);
	for ($i = $idx + 1; $i < $n; $i++) {
		$t = $tokens[$i];
		if ($t[0] === T_WHITESPACE) {
			if (strpos($t[1], "\n") !== false) {
				return '';
			}
			continue;
		}
		if ($t[0] === T_COMMENT) {
			$c = trim($t[1]);
			if (substr($c, 0, 2) === '//') {
				$c = substr($c, 2);
			} else if (substr($c, 0, 2) === '/*') {
				$c = substr($c, 2, -2);
			}
			return trim($c);
		}
		return '';
	}
	return '';
}

function configTypeOf($v) {
	if (is_bool($v)) {
		return 'bool';
	}
	if (is_int($v)) {
		return 'int';
	}
	if (is_float($v)) {
		return 'float';
	}
	if (is_string($v)) {
		return 'string';
	}
	if (is_array($v)) {
		return 'array';
	}
	return 'complex';
}

// list of entries: kind (define|var), name, start, end, raw, value, type, editable, help, section
function configParse($src) {
	$raw = token_get_all($src);
	$tokens = array();
	$off = 0;
	foreach ($raw as $t) {
		if (is_array($t)) {
			$tokens[] = array($t[0], $t[1], $off);
			$off += strlen($t[1]);
		} else {
			$tokens[] = array(null, $t, $off);
			$off += strlen($t);
		}
	}

	$n = count($tokens);
	$entries = array();
	$seen = array();
	$section = 'General';

	for ($i = 0; $i < $n; $i++) {
		$t = $tokens[$i];

		// section heading = a // comment after a blank line
		if ($t[0] === T_COMMENT && substr($t[1], 0, 2) === '//') {
			$before = substr($src, 0, $t[2]);
			$ws = substr($before, strlen(rtrim($before)));
			if (substr_count($ws, "\n") >= 2) {
				$title = trim(substr(trim($t[1]), 2));
				if ($title !== '') {
					$section = $title;
				}
			}
			continue;
		}

		$kind = null;
		$name = null;
		$span = null;
		$endtok = null;

		if ($t[0] === T_STRING && strtolower($t[1]) === 'define') {
			$j = configNextSig($tokens, $i + 1);
			if ($j >= $n || $tokens[$j][0] !== null || $tokens[$j][1] !== '(') {
				continue;
			}
			$k = configNextSig($tokens, $j + 1);
			if ($k >= $n || $tokens[$k][0] !== T_CONSTANT_ENCAPSED_STRING) {
				continue;
			}
			$name = configStringLiteral($tokens[$k][1]);
			if ($name === null || !preg_match('/^[A-Z0-9_]+$/', $name)) {
				continue;
			}
			$c = configNextSig($tokens, $k + 1);
			if ($c >= $n || $tokens[$c][0] !== null || $tokens[$c][1] !== ',') {
				continue;
			}
			$span = configValueSpan($tokens, $c + 1, ')');
			if ($span === null) {
				continue;
			}
			$kind = 'define';
			$semi = configNextSig($tokens, $span[2] + 1);
			$endtok = $semi < $n ? $semi : $span[2];
		} else if ($t[0] === T_VARIABLE && preg_match('/^\$schan_[a-z0-9_]+$/', $t[1])) {
			$e = configNextSig($tokens, $i + 1);
			if ($e >= $n || $tokens[$e][0] !== null || $tokens[$e][1] !== '=') {
				continue;
			}
			$span = configValueSpan($tokens, $e + 1, ';');
			if ($span === null) {
				continue;
			}
			$kind = 'var';
			$name = substr($t[1], 1);
			$endtok = $span[2];
		} else {
			continue;
		}

		$key = $kind . ':' . $name;
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key] = true;

		$start = $tokens[$span[0]][2];
		$end = $tokens[$span[1]][2] + strlen($tokens[$span[1]][1]);

		// work out the value without eval()
		$sig = array();
		for ($m = $span[0]; $m <= $span[1]; $m++) {
			if (!configIsInsignificant($tokens[$m])) {
				$sig[] = $tokens[$m];
			}
		}
		$ok = false;
		$value = null;
		try {
			$p = 0;
			$value = configParseValue($sig, $p);
			$ok = ($p === count($sig));
		} catch (Exception $ex) {
			$ok = false;
		}

		$type = $ok ? configTypeOf($value) : 'complex';
		if ($kind === 'var' && $type !== 'array') {
			$type = 'complex';
		}
		if ($kind === 'define' && $name === 'SCHAN_DBMIGRATE' && $ok && (is_string($value) || $value === false)) {
			$type = 'dbmigrate';
		}

		$entries[] = array(
			'kind' => $kind,
			'name' => $name,
			'start' => $start,
			'end' => $end,
			'raw' => substr($src, $start, $end - $start),
			'value' => $value,
			'type' => $type,
			'editable' => ($type !== 'complex'),
			'help' => configTrailingComment($tokens, $endtok),
			'section' => $section,
		);
	}

	return $entries;
}

// exporting values as PHP literals

function configScalarExport($v) {
	if (is_bool($v)) {
		return $v ? 'true' : 'false';
	}
	if ($v === null) {
		return 'null';
	}
	return var_export($v, true);
}

function configExport($v, $depth = 0) {
	if (!is_array($v)) {
		return configScalarExport($v);
	}
	if (empty($v)) {
		return 'array()';
	}
	$list = (array_keys($v) === range(0, count($v) - 1));
	$parts = array();
	foreach ($v as $k => $item) {
		$s = configExport($item, $depth + 1);
		$parts[] = $list ? $s : configScalarExport($k) . ' => ' . $s;
	}
	if ($depth > 0) {
		return 'array(' . implode(', ', $parts) . ')';
	}
	return "array(\n\t" . implode(",\n\t", $parts) . "\n)";
}

function configSame($a, $b) {
	if (is_float($a) || is_float($b)) {
		return is_numeric($a) && is_numeric($b) && abs($a - $b) < 1e-9;
	}
	return $a === $b;
}

// form definitions

function configDbModes() {
	return array('flatfile', 'mysql', 'mysqli', 'sqlite', 'sqlite3', 'pdo');
}

function configChoices($entries) {
	$captcha = array('', 'simple', 'hcaptcha', 'recaptcha');
	$choices = array(
		'SCHAN_CAPTCHA' => $captcha,
		'SCHAN_REPLYCAPTCHA' => $captcha,
		'SCHAN_REPORTCAPTCHA' => $captcha,
		'SCHAN_MANAGECAPTCHA' => $captcha,
		'SCHAN_REQMOD' => array('', 'files', 'all'),
		'SCHAN_THUMBNAIL' => array('gd', 'ffmpeg', 'imagemagick'),
		'SCHAN_DBMODE' => configDbModes(),
	);
	foreach ($entries as $e) {
		if ($e['kind'] === 'var' && $e['name'] === 'schan_stylesheets' && is_array($e['value']) && !empty($e['value'])) {
			$choices['SCHAN_DEFAULTSTYLE'] = array_map('strval', array_keys($e['value']));
		}
	}
	return $choices;
}

// settings the code knows about that aren't in the default settings.php
function configOptional() {
	return array(
		array('kind' => 'define', 'name' => 'SCHAN_ROOTURL', 'type' => 'string', 'help' => 'URL path of the install root, e.g. /imgb  (leave blank to auto-detect)'),
		array('kind' => 'define', 'name' => 'SCHAN_EMOJI_MAXKB', 'type' => 'int', 'help' => 'Maximum emoji upload size in KB  (default 512)'),
		array('kind' => 'var', 'name' => 'schan_boardbar_links', 'type' => 'array', 'help' => 'Extra links in the board bar'),
	);
}

function configArrayHelp($name) {
	$help = array(
		'schan_stylesheets' => 'Themes visitors can choose from: the CSS file name (in css/, without .css) and the title shown to visitors.',
		'schan_capcodes' => 'Label and colour for staff posts. The first is Admin, the second is Mod.',
		'schan_uploads' => 'Allowed file types: MIME type, file extension, and an optional thumbnail image for types that cannot be thumbnailed.',
		'schan_embeds' => 'Embed services: a name and its oEmbed URL, which must contain SCHANEMBED.',
		'schan_hidefieldsop' => 'Tick the fields to hide on the new thread form.',
		'schan_hidefields' => 'Tick the fields to hide on the reply form.',
		'schan_anonymous' => 'Default names given to posts with no name.',
		'schan_reserved_slugs' => 'Names that cannot be used as board slugs.',
		'schan_boardbar_links' => 'Extra links in the board bar: a name and a URL.',
	);
	return isset($help[$name]) ? $help[$name] : 'JSON list [...] or object {...}';
}

function configWarning($name) {
	if ($name === 'SCHAN_TRIPSEED') {
		return 'Must not change once set. Changing it breaks existing tripcodes and hashed passwords.';
	}
	if ($name === 'SCHAN_ADMINPASS' || $name === 'SCHAN_MODPASS') {
		return 'Only used to create the account on install. Blank it once the account exists.';
	}
	if (preg_match('/^SCHAN_DB(MODE|HOST|PORT|USERNAME|PASSWORD|NAME|DRIVER|DSN|PATH)$/', $name)) {
		return 'Wrong values here take the whole site offline until settings.php is fixed by hand.';
	}
	return '';
}

// editors for array settings ("Add theme", "Add board link"...)
// rows are edited in the browser into a hidden JSON field, so saving still goes through the same
// validation. anything that doesn't fit an editor falls back to the plain JSON box

function configArraySpecs() {
	return array(
		'schan_stylesheets' => array('mode' => 'map', 'add' => 'Add theme',
			'heads' => array('CSS file (without .css)', 'Title'), 'ph' => array('futaba', 'Futaba'), 'sizes' => array(22, 26)),
		'schan_boardbar_links' => array('mode' => 'tuples', 'add' => 'Add board link',
			'heads' => array('Name', 'URL'), 'ph' => array('Rules', '/imgb/rules.html'), 'sizes' => array(18, 40)),
		'schan_capcodes' => array('mode' => 'tuples', 'add' => 'Add capcode',
			'heads' => array('Label', 'Color'), 'ph' => array('Admin', 'red'), 'sizes' => array(18, 14)),
		'schan_embeds' => array('mode' => 'map', 'add' => 'Add embed service',
			'heads' => array('Service', 'oEmbed URL (contains SCHANEMBED)'), 'ph' => array('YouTube', 'https://www.youtube.com/oembed?url=SCHANEMBED&format=json'), 'sizes' => array(14, 50)),
		'schan_uploads' => array('mode' => 'maptuples', 'add' => 'Add upload type',
			'heads' => array('MIME type', 'Extension', 'Thumbnail (optional)'), 'ph' => array('image/png', 'png', 'thumbnail.png'), 'sizes' => array(26, 8, 20)),
		'schan_anonymous' => array('mode' => 'strings', 'add' => 'Add name',
			'heads' => array('Name'), 'ph' => array('Anonymous'), 'sizes' => array(24)),
		'schan_reserved_slugs' => array('mode' => 'strings', 'add' => 'Add slug',
			'heads' => array('Slug'), 'ph' => array('css'), 'sizes' => array(24)),
		'schan_hidefieldsop' => array('mode' => 'checks'),
		'schan_hidefields' => array('mode' => 'checks'),
	);
}

function configIsList($v) {
	return is_array($v) && (empty($v) || array_keys($v) === range(0, count($v) - 1));
}

function configAllStrings($v) {
	foreach ($v as $x) {
		if (!is_string($x)) {
			return false;
		}
	}
	return true;
}

function configArrayFits($spec, $value) {
	if (!is_array($value)) {
		return false;
	}
	if (empty($value)) {
		return true;
	}
	$n = isset($spec['heads']) ? count($spec['heads']) : 0;
	switch ($spec['mode']) {
		case 'strings':
		case 'checks':
			return configIsList($value) && configAllStrings($value);
		case 'tuples':
			if (!configIsList($value)) {
				return false;
			}
			foreach ($value as $row) {
				if (!configIsList($row) || empty($row) || count($row) > $n || !configAllStrings($row)) {
					return false;
				}
			}
			return true;
		case 'map':
			return !configIsList($value) && configAllStrings($value);
		case 'maptuples':
			if (configIsList($value)) {
				return false;
			}
			foreach ($value as $row) {
				if (!configIsList($row) || empty($row) || count($row) > $n - 1 || !configAllStrings($row)) {
					return false;
				}
			}
			return true;
	}
	return false;
}

function configEditorRow($spec, $cells) {
	$html = '<tr>';
	for ($i = 0; $i < count($spec['heads']); $i++) {
		$v = isset($cells[$i]) ? $cells[$i] : '';
		$html .= '<td style="padding:0 6px 3px 0;"><input type="text" class="cfg-cell" value="' . configH($v) . '" placeholder="' . configH($spec['ph'][$i]) . '" size="' . intval($spec['sizes'][$i]) . '"></td>';
	}
	return $html . '<td style="padding:0 0 3px 0;"><a href="#" class="cfg-del" title="Remove">[&times;]</a></td></tr>';
}

// editor html, or '' to use the JSON box
function configArrayEditor($name, $value, $fname) {
	$specs = configArraySpecs();
	if (!isset($specs[$name]) || !configArrayFits($specs[$name], $value)) {
		return '';
	}
	$spec = $specs[$name];
	$is_obj = ($spec['mode'] === 'map' || $spec['mode'] === 'maptuples');
	$json = empty($value) ? ($is_obj ? '{}' : '[]') : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$hidden = '<input type="hidden" class="cfg-json" name="' . $fname . '" value="' . configH($json) . '">';

	if ($spec['mode'] === 'checks') {
		$known = array('name', 'email', 'subject', 'message', 'file', 'embed', 'password', 'memeflag', 'oekaki');
		$opts = array_values(array_unique(array_merge($known, $value)));
		$html = '<div class="cfg-editor" data-mode="checks">' . $hidden;
		foreach ($opts as $o) {
			$html .= '<label style="margin-right:12px;white-space:nowrap;"><input type="checkbox" class="cfg-check" value="' . configH($o) . '"' . (in_array($o, $value, true) ? ' checked' : '') . '> ' . configH($o) . '</label> ';
		}
		return $html . '</div>';
	}

	$rows = '';
	switch ($spec['mode']) {
		case 'strings':
			foreach ($value as $v) {
				$rows .= configEditorRow($spec, array($v));
			}
			break;
		case 'tuples':
			foreach ($value as $row) {
				$rows .= configEditorRow($spec, $row);
			}
			break;
		case 'map':
			foreach ($value as $k => $v) {
				$rows .= configEditorRow($spec, array((string)$k, $v));
			}
			break;
		case 'maptuples':
			foreach ($value as $k => $row) {
				$cells = array((string)$k);
				foreach ($row as $c) {
					$cells[] = $c;
				}
				$rows .= configEditorRow($spec, $cells);
			}
			break;
	}

	$head = '<tr>';
	foreach ($spec['heads'] as $h) {
		$head .= '<th style="text-align:left;font-weight:normal;font-size:smaller;padding:0 6px 2px 0;">' . configH($h) . '</th>';
	}
	$head .= '<th></th></tr>';

	return '<div class="cfg-editor" data-mode="' . configH($spec['mode']) . '" data-ph="' . configH(json_encode($spec['ph'])) . '" data-sizes="' . configH(json_encode($spec['sizes'])) . '">'
		. $hidden
		. '<table border="0" cellspacing="0" cellpadding="0"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>'
		. '<a href="#" class="cfg-add">' . configH($spec['add']) . '</a>'
		. '</div>';
}

// extra checks for settings that end up in paths or links, returns an error or ''
function configValidateArray($name, $data) {
	if ($name === 'schan_stylesheets') {
		if (empty($data)) {
			return 'schan_stylesheets needs at least one theme.';
		}
		foreach ($data as $k => $v) {
			if (!is_string($v) || trim($v) === '') {
				return 'Every theme needs a title (' . $k . ').';
			}
			if (!preg_match('/^[A-Za-z0-9_-]+$/', (string)$k)) {
				return 'Theme CSS file names may only contain letters, numbers, - and _ (' . $k . ').';
			}
		}
	}
	if ($name === 'schan_boardbar_links') {
		foreach ($data as $row) {
			if (!is_array($row) || !isset($row[0]) || !isset($row[1]) || !is_string($row[0]) || !is_string($row[1])) {
				return 'Each board link needs a name and a URL.';
			}
			if (trim($row[0]) === '') {
				return 'Each board link needs a name.';
			}
			if (preg_match('/^\s*(javascript|data|vbscript):/i', $row[1])) {
				return 'Board link URLs cannot use javascript:, data: or vbscript:.';
			}
		}
	}
	return '';
}

function configEditorScript() {
	return <<<'CFGJS'
<script type="text/javascript">
(function(){
	var editors = document.querySelectorAll('.cfg-editor');
	function cells(tr){
		var out = [], c = tr.querySelectorAll('.cfg-cell');
		for (var i = 0; i < c.length; i++) { out.push(c[i].value.replace(/^\s+|\s+$/g, '')); }
		return out;
	}
	function serialize(ed){
		var mode = ed.getAttribute('data-mode'), out, i, c;
		if (mode === 'checks') {
			out = [];
			var boxes = ed.querySelectorAll('.cfg-check');
			for (i = 0; i < boxes.length; i++) { if (boxes[i].checked) { out.push(boxes[i].value); } }
		} else {
			var rows = ed.querySelectorAll('tbody tr');
			out = (mode === 'map' || mode === 'maptuples') ? {} : [];
			for (i = 0; i < rows.length; i++) {
				c = cells(rows[i]);
				if (mode === 'strings') {
					if (c[0] !== '') { out.push(c[0]); }
				} else if (mode === 'tuples') {
					if (c.join('') !== '') { out.push(c); }
				} else if (mode === 'map') {
					if (c[0] !== '') { out[c[0]] = c[1]; }
				} else if (mode === 'maptuples') {
					if (c[0] !== '' && c[1] !== '') {
						var t = [c[1]];
						if (c[2]) { t.push(c[2]); }
						out[c[0]] = t;
					}
				}
			}
		}
		ed.querySelector('.cfg-json').value = JSON.stringify(out);
	}
	function addRow(ed){
		var ph = JSON.parse(ed.getAttribute('data-ph') || '[]'), sizes = JSON.parse(ed.getAttribute('data-sizes') || '[]');
		var tr = document.createElement('tr'), first = null;
		for (var i = 0; i < ph.length; i++) {
			var td = document.createElement('td'), inp = document.createElement('input');
			td.style.cssText = 'padding:0 6px 3px 0;';
			inp.type = 'text';
			inp.className = 'cfg-cell';
			inp.placeholder = ph[i];
			inp.size = sizes[i] || 20;
			td.appendChild(inp);
			tr.appendChild(td);
			if (!first) { first = inp; }
		}
		var td2 = document.createElement('td'), a = document.createElement('a');
		td2.style.cssText = 'padding:0 0 3px 0;';
		a.href = '#';
		a.className = 'cfg-del';
		a.title = 'Remove';
		a.innerHTML = '[&times;]';
		td2.appendChild(a);
		tr.appendChild(td2);
		ed.querySelector('tbody').appendChild(tr);
		if (first) { first.focus(); }
	}
	for (var n = 0; n < editors.length; n++) {
		(function(ed){
			ed.addEventListener('input', function(){ serialize(ed); });
			ed.addEventListener('change', function(){ serialize(ed); });
			ed.addEventListener('click', function(e){
				var t = e.target;
				if (!t.classList) { return; }
				if (t.classList.contains('cfg-add')) {
					e.preventDefault();
					addRow(ed);
				} else if (t.classList.contains('cfg-del')) {
					e.preventDefault();
					var tr = t.parentNode.parentNode;
					tr.parentNode.removeChild(tr);
					serialize(ed);
				}
			});
		})(editors[n]);
	}
})();
</script>
CFGJS;
}

// saving

// returns array('value' => x), array('skip' => true) or array('error' => msg)
function configCoerce($e, $raw, $clear, $choices) {
	$name = $e['name'];
	switch ($e['type']) {
		case 'bool':
			return array('value' => ($raw === '1'));

		case 'int':
			$raw = trim($raw);
			if (!preg_match('/^-?[0-9]+$/', $raw)) {
				return array('error' => $name . ' must be a whole number.');
			}
			return array('value' => intval($raw));

		case 'float':
			$raw = trim($raw);
			if (!is_numeric($raw)) {
				return array('error' => $name . ' must be a number.');
			}
			return array('value' => floatval($raw));

		case 'dbmigrate':
			$raw = trim($raw);
			if ($raw === '') {
				return array('value' => false);
			}
			if (!in_array($raw, configDbModes(), true)) {
				return array('error' => $name . ' must be blank or a database mode.');
			}
			return array('value' => $raw);

		case 'array':
			if (trim($raw) === '') {
				return array('value' => array());
			}
			$data = json_decode($raw, true);
			if (!is_array($data)) {
				return array('error' => $name . ' must be valid JSON: a list [...] or an object {...}.');
			}
			$bad = configValidateArray($name, $data);
			if ($bad !== '') {
				return array('error' => $bad);
			}
			return array('value' => $data);

		default:
			$raw = str_replace(array("\r", "\n", "\0"), '', $raw);
			if (strlen($raw) > 4096) {
				return array('error' => $name . ' is too long.');
			}
			if (configSecretName($name)) {
				if ($clear) {
					if ($name === 'SCHAN_TRIPSEED') {
						return array('error' => 'SCHAN_TRIPSEED must be configured and cannot be cleared.');
					}
					return array('value' => '');
				}
				if ($raw === '') {
					return array('skip' => true);
				}
				return array('value' => $raw);
			}
			if (isset($choices[$name])) {
				$allowed = $choices[$name];
				$allowed[] = isset($e['value']) && is_string($e['value']) ? $e['value'] : '';
				if (!in_array($raw, $allowed, true)) {
					return array('error' => $name . ' has an invalid choice.');
				}
			}
			return array('value' => $raw);
	}
}

// new file contents, null if nothing changed
function configApplyPost($src, $entries, $post, &$errors, &$changed, &$expected) {
	$cfg = (isset($post['cfg']) && is_array($post['cfg'])) ? $post['cfg'] : array();
	$cfgv = (isset($post['cfgv']) && is_array($post['cfgv'])) ? $post['cfgv'] : array();
	$clear = (isset($post['cfgclear']) && is_array($post['cfgclear'])) ? $post['cfgclear'] : array();
	$choices = configChoices($entries);

	$repl = array();
	$present = array();

	foreach ($entries as $e) {
		$isdef = ($e['kind'] === 'define');
		$key = ($isdef ? 'd:' : 'v:') . $e['name'];
		$present[$key] = true;
		if (!$e['editable']) {
			continue;
		}
		$bucket = $isdef ? $cfg : $cfgv;
		if (!array_key_exists($e['name'], $bucket) || is_array($bucket[$e['name']])) {
			continue;
		}
		$res = configCoerce($e, (string)$bucket[$e['name']], !empty($clear[$e['name']]), $choices);
		if (isset($res['error'])) {
			$errors[] = $res['error'];
			continue;
		}
		if (isset($res['skip'])) {
			continue;
		}
		$value = $res['value'];
		if (configSame($e['value'], $value)) {
			continue;
		}
		$repl[] = array($e['start'], $e['end'], is_array($value) ? configExport($value) : configScalarExport($value));
		$changed[] = ($isdef ? '' : '$') . $e['name'];
		$expected[$key] = $value;
	}

	// optional settings not in the file yet get appended if filled in
	$append = '';
	foreach (configOptional() as $o) {
		$isdef = ($o['kind'] === 'define');
		$key = ($isdef ? 'd:' : 'v:') . $o['name'];
		if (isset($present[$key])) {
			continue;
		}
		$bucket = $isdef ? $cfg : $cfgv;
		if (!array_key_exists($o['name'], $bucket) || is_array($bucket[$o['name']])) {
			continue;
		}
		$raw = trim((string)$bucket[$o['name']]);
		if ($raw === '') {
			continue;
		}
		$res = configCoerce(array('name' => $o['name'], 'type' => $o['type'], 'value' => null), $raw, false, array());
		if (isset($res['error'])) {
			$errors[] = $res['error'];
			continue;
		}
		if (!isset($res['value']) || (is_array($res['value']) && empty($res['value']))) {
			continue;
		}
		$literal = is_array($res['value']) ? configExport($res['value']) : configScalarExport($res['value']);
		if ($isdef) {
			$append .= "define('" . $o['name'] . "', " . $literal . ");\n";
		} else {
			$append .= '$' . $o['name'] . ' = ' . $literal . ";\n";
		}
		$changed[] = ($isdef ? '' : '$') . $o['name'];
		$expected[$key] = $res['value'];
	}

	if (empty($repl) && $append === '') {
		return null;
	}

	usort($repl, function ($a, $b) {
		return $b[0] - $a[0];
	});
	$new = $src;
	foreach ($repl as $r) {
		$new = substr($new, 0, $r[0]) . $r[2] . substr($new, $r[1]);
	}

	if ($append !== '') {
		$block = "\n// Added from the management panel\n" . $append;
		if (preg_match('/\?>\s*$/', $new, $m, PREG_OFFSET_CAPTURE)) {
			$new = substr($new, 0, $m[0][1]) . ltrim($block, "\n") . "\n" . substr($new, $m[0][1]);
		} else {
			$new = rtrim($new) . "\n" . $block;
		}
	}

	return $new;
}

function handleConfigManage() {
	global $account;

	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['saveconfig'])) {
		return '';
	}
	if (!isset($account['role']) || $account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
		fancyDie(__('Access denied'));
	}
	if (!isset($_POST['cfg_token']) || !hash_equals(configToken(), (string)$_POST['cfg_token'])) {
		return manageInfo('Invalid or expired form token. Reload the page and try again.');
	}

	$file = configFile();
	if (!is_readable($file)) {
		return manageInfo('settings.php could not be read.');
	}
	if (!is_writable($file)) {
		return manageInfo('settings.php is not writable by the web server. Change its permissions (for example 664) and try again.');
	}

	$src = file_get_contents($file);
	$entries = configParse($src);

	$errors = array();
	$changed = array();
	$expected = array();
	$new = configApplyPost($src, $entries, $_POST, $errors, $changed, $expected);

	if (!empty($errors)) {
		return manageInfo(implode('<br>', array_map('configH', $errors)) . '<br>Nothing was saved.');
	}
	if ($new === null) {
		return manageInfo('No changes to save.');
	}

	// 1. new file has to be valid PHP
	try {
		token_get_all($new, TOKEN_PARSE);
	} catch (Throwable $ex) {
		return manageInfo('Refused to save: the result would not be valid PHP (' . configH($ex->getMessage()) . '). Nothing was changed.');
	}

	// 2. re-read it, every setting must keep its value
	$after = array();
	foreach (configParse($new) as $e) {
		$after[($e['kind'] === 'define' ? 'd:' : 'v:') . $e['name']] = $e['value'];
	}
	foreach ($entries as $e) {
		if (!array_key_exists(($e['kind'] === 'define' ? 'd:' : 'v:') . $e['name'], $after)) {
			return manageInfo('Refused to save: a setting would have been lost (' . configH($e['name']) . '). Nothing was changed.');
		}
	}
	foreach ($expected as $key => $value) {
		if (!array_key_exists($key, $after) || !configSame($after[$key], $value)) {
			return manageInfo('Refused to save: verification failed for ' . configH(substr($key, 2)) . '. Nothing was changed.');
		}
	}

	// 3. backup, then atomic write
	if (!@copy($file, 'settings.backup.php')) {
		return manageInfo('Could not write settings.backup.php. Nothing was changed.');
	}
	@chmod('settings.backup.php', 0600);

	$tmp = @tempnam('.', 'cfg');
	if ($tmp === false || file_put_contents($tmp, $new) === false) {
		if ($tmp !== false) {
			@unlink($tmp);
		}
		return manageInfo('Could not write the new settings. Nothing was changed.');
	}
	@chmod($tmp, fileperms($file) & 0777);
	if (!@rename($tmp, $file)) {
		if (file_put_contents($file, $new) === false) {
			@unlink($tmp);
			return manageInfo('Could not replace settings.php. Restore settings.backup.php if the site is broken.');
		}
		@unlink($tmp);
	}
	if (function_exists('opcache_invalidate')) {
		@opcache_invalidate($file, true);
	}

	if (function_exists('manageLogAction')) {
		manageLogAction('Edited settings: ' . implode(', ', $changed));
	}

	return manageInfo('Saved ' . count($changed) . ' setting(s): ' . configH(implode(', ', $changed)) . '. Previous file kept as settings.backup.php. Run Rebuild All for changes that affect board pages.');
}

// page

function configInput($e, $fname, $choices) {
	$name = $e['name'];
	$value = $e['value'];

	switch ($e['type']) {
		case 'bool':
			return '<input type="hidden" name="' . $fname . '" value="0">'
				. '<label><input type="checkbox" name="' . $fname . '" value="1"' . ($value ? ' checked' : '') . '> enabled</label>';

		case 'int':
		case 'float':
			return '<input type="text" name="' . $fname . '" value="' . configH($value) . '" size="14">';

		case 'dbmigrate':
			$html = '<select name="' . $fname . '">';
			foreach (array_merge(array(''), configDbModes()) as $mode) {
				$sel = ((string)$value === $mode || ($value === false && $mode === '')) ? ' selected' : '';
				$html .= '<option value="' . configH($mode) . '"' . $sel . '>' . ($mode === '' ? '(disabled)' : configH($mode)) . '</option>';
			}
			return $html . '</select>';

		case 'array':
			$editor = configArrayEditor($name, $value, $fname);
			if ($editor !== '') {
				return $editor;
			}
			$json = empty($value) ? '[]' : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$rows = min(14, max(3, substr_count($json, "\n") + 1));
			return '<textarea name="' . $fname . '" rows="' . $rows . '" cols="70" style="font-family:monospace;" spellcheck="false">' . configH($json) . '</textarea>';

		default:
			if (configSecretName($name)) {
				return '<input type="password" name="' . $fname . '" value="" size="30" autocomplete="new-password" placeholder="(unchanged)"> '
					. '<label><input type="checkbox" name="cfgclear[' . configH($name) . ']" value="1"> clear</label> '
					. '<small>' . ($value === '' ? 'currently empty' : 'currently set') . '</small>';
			}
			if (isset($choices[$name])) {
				$opts = $choices[$name];
				if (!in_array($value, $opts, true)) {
					$opts[] = $value;
				}
				$html = '<select name="' . $fname . '">';
				foreach ($opts as $o) {
					$html .= '<option value="' . configH($o) . '"' . ($o === $value ? ' selected' : '') . '>' . ($o === '' ? '(none)' : configH($o)) . '</option>';
				}
				return $html . '</select>';
			}
			return '<input type="text" name="' . $fname . '" value="' . configH($value) . '" size="60">';
	}
}

function configRow($label, $input, $help, $warn) {
	$extra = '';
	if ($help !== '') {
		$extra .= '<br><small>' . configH($help) . '</small>';
	}
	if ($warn !== '') {
		$extra .= '<br><small><b>' . configH($warn) . '</b></small>';
	}
	return '<tr><td valign="top" style="padding:3px 12px 6px 0;white-space:nowrap;"><code>' . configH($label) . '</code></td>'
		. '<td style="padding:3px 0 6px 0;">' . $input . $extra . '</td></tr>';
}

function manageConfigPage() {
	global $account;
	if (!isset($account['role']) || $account['role'] != SCHAN_SUPER_ADMINISTRATOR) {
		fancyDie(__('Access denied'));
	}

	$file = configFile();
	$src = @file_get_contents($file);
	if ($src === false) {
		return manageInfo('settings.php could not be read.');
	}
	$entries = configParse($src);
	$choices = configChoices($entries);

	$sections = array();
	$present = array();
	foreach ($entries as $e) {
		$isdef = ($e['kind'] === 'define');
		$present[($isdef ? 'd:' : 'v:') . $e['name']] = true;
		if (!isset($sections[$e['section']])) {
			$sections[$e['section']] = '';
		}
		if ($e['editable']) {
			$fname = ($isdef ? 'cfg[' : 'cfgv[') . $e['name'] . ']';
			$input = configInput($e, $fname, $choices);
			$help = $e['help'];
			if ($e['type'] === 'array') {
				$hint = configArrayHelp($e['name']);
				$help = $help === '' ? $hint : $help . ' - ' . $hint;
			}
		} else {
			$input = '<code>' . configH($e['raw']) . '</code>';
			$help = 'Not a simple value, so it cannot be edited here. Edit settings.php by hand.';
		}
		$sections[$e['section']] .= configRow(($isdef ? '' : '$') . $e['name'], $input, $help, configWarning($e['name']));
	}

	$optional = '';
	foreach (configOptional() as $o) {
		$isdef = ($o['kind'] === 'define');
		if (isset($present[($isdef ? 'd:' : 'v:') . $o['name']])) {
			continue;
		}
		$fname = ($isdef ? 'cfg[' : 'cfgv[') . $o['name'] . ']';
		$fake = array('name' => $o['name'], 'type' => $o['type'], 'value' => $o['type'] === 'int' ? '' : ($o['type'] === 'array' ? array() : ''));
		$input = configInput($fake, $fname, array());
		$help = $o['help'];
		if ($o['type'] === 'array') {
			$help .= ' - ' . configArrayHelp($o['name']);
		}
		$optional .= configRow(($isdef ? '' : '$') . $o['name'], $input, $help . '  (not in settings.php yet; filled-in values are added)', '');
	}
	if ($optional !== '') {
		$sections['Optional settings'] = $optional;
	}

	$writable = is_writable($file);
	$html = '<form method="post" action="?manage&config" autocomplete="off">'
		. '<input type="hidden" name="cfg_token" value="' . configH(configToken()) . '">'
		. '<p>Edits <code>settings.php</code> directly. The previous file is saved as <code>settings.backup.php</code> before every save, '
		. 'and nothing is written unless the result is valid. Many appearance settings need <b>Rebuild All</b> afterwards.</p>';

	if (!$writable) {
		$html .= '<p><b>settings.php is not writable by the web server, so saving is disabled.</b> Change its permissions (for example 664).</p>';
	}

	foreach ($sections as $title => $rows) {
		$html .= '<fieldset><legend>' . configH($title) . '</legend><table border="0" cellspacing="0" cellpadding="0">' . $rows . '</table></fieldset><br>';
	}

	$html .= '<input type="submit" name="saveconfig" value="Save settings" class="managebutton"' . ($writable ? '' : ' disabled') . '>'
		. '</form><br>' . configEditorScript();

	return $html;
}
