<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// random banner from static/banners, click it to swap

function bannerListJSON() {
	static $cached = null;
	if ($cached !== null) {
		return $cached;
	}

	$dir = dirname(dirname(dirname(__FILE__))) . '/static/banners';
	$exts = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'avif');
	$urls = array();

	if (is_dir($dir) && ($h = @opendir($dir))) {
		while (($f = readdir($h)) !== false) {
			$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
			if (is_file($dir . '/' . $f) && in_array($ext, $exts, true)) {
				$urls[] = installRootURL() . '/static/banners/' . rawurlencode($f);
			}
		}
		closedir($h);
	}

	$cached = json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return $cached;
}

function bannerHTML() {
	$json = bannerListJSON();
	if ($json === '[]') {
		return '';
	}

	static $n = 0;
	$id = 'boardbanner' . (++$n);

	return <<<EOF
<div class="boardbanner" style="text-align:center;margin:8px 0;">
<a href="#" id="{$id}link" style="display:inline-block;cursor:pointer;"><img id="$id" src="" width="300" height="100" alt="Banner" style="border:0;max-width:100%;height:auto;display:block;"></a>
<script type="text/javascript">
(function () {
	var banners = $json, cur = -1;
	var img = document.getElementById("$id");
	function show() {
		var i;
		do { i = Math.floor(Math.random() * banners.length); } while (i === cur && banners.length > 1);
		cur = i;
		img.src = banners[i];
	}
	document.getElementById("{$id}link").addEventListener("click", function (e) {
		e.preventDefault();
		show();
	});
	show();
})();
</script>
</div>
EOF;
}
