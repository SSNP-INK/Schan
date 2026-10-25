<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// click-to-quote and hover previews, buildPage() in html.php calls both
// SCHAN_CLICKQUOTE / SCHAN_HOVERPREVIEW in settings.php turn them off

// click a post number -> drops >>number (and any selected text) into the comment box
function clickQuoteJS() {
	return '<script type="text/javascript">' . <<<'JSEOF'
(function () {
	if (window.schanQuoteClick || !document.addEventListener || !Element.prototype.closest) { return; }
	window.schanQuoteClick = true;

	function commentBox() {
		var all = document.querySelectorAll('textarea[name="message"]');
		for (var i = 0; i < all.length; i++) {
			if (!all[i].closest('.schan-pv')) { return all[i]; }
		}
		return null;
	}

	// selected text inside that post, quoted
	function selectedQuote(id) {
		var sel = window.getSelection && window.getSelection();
		if (!sel || sel.isCollapsed || !sel.anchorNode) { return ''; }
		var text = sel.toString().replace(/\r/g, '').replace(/^\s+|\s+$/g, '');
		if (!text) { return ''; }
		var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode;
		var msg = node && node.closest ? node.closest('.message') : null;
		if (!msg) { return ''; }
		var host = document.getElementById('post' + id);
		if (host && !host.contains(msg)) { return ''; }
		return '>' + text.split('\n').join('\n>');
	}

	document.addEventListener('click', function (e) {
		// ctrl/shift/middle click still follows the link
		if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || !e.target.closest) { return; }
		var a = e.target.closest('.reflink a');
		if (!a) { return; }
		var m = (a.getAttribute('href') || '').match(/#q(\d+)$/);
		if (!m) { return; }
		var ta = commentBox();
		if (!ta) { return; }
		e.preventDefault();
		e.stopPropagation();

		var quote = selectedQuote(m[1]);
		var add = '>>' + m[1] + '\n' + (quote ? quote + '\n' : '');
		var v = ta.value, s, en;
		if (document.activeElement === ta && typeof ta.selectionStart === 'number') {
			s = ta.selectionStart;
			en = ta.selectionEnd;
		} else {
			s = en = v.length;
		}
		var pre = v.slice(0, s);
		if (pre && !/\n$/.test(pre)) { add = '\n' + add; }
		ta.value = pre + add + v.slice(en);
		var pos = pre.length + add.length;
		ta.focus();
		if (ta.setSelectionRange) { ta.setSelectionRange(pos, pos); }
		try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (err) {}
	}, true);
})();
JSEOF
	. '</script>';
}

// hover a >>link and a floating copy of the post follows the mouse
// posts on the page get copied, anything else comes from imgboard.php?preview
function hoverPreviewJS($render_board) {
	$slugs = array();
	if (function_exists('allBoards')) {
		foreach (allBoards() as $b) {
			$slugs[] = is_array($b) ? $b['slug'] : $b;
		}
	}
	$cfg = array(
		'root' => installRootURL(),
		'board' => (string)$render_board,
		'boards' => $slugs,
		'overboard' => defined('SCHAN_OVERBOARD') ? SCHAN_OVERBOARD : '',
	);
	$out = '<script type="text/javascript">window.schanPreview = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
	$out .= '<script type="text/javascript">' . <<<'JSEOF'
(function () {
	if (window.schanHover || !document.addEventListener || !Element.prototype.closest) { return; }
	window.schanHover = true;
	var cfg = window.schanPreview || {};
	var root = cfg.root || '', pageBoard = cfg.board || '', boards = cfg.boards || [], overboard = cfg.overboard || '';
	var cache = {};

	var st = document.createElement('style');
	st.textContent = '.schan-pv{position:fixed;z-index:100000;display:block;pointer-events:none;user-select:none;-webkit-user-select:none;box-sizing:border-box;max-width:min(75vw,900px);'
		+ 'padding:6px 8px;margin:0;overflow:hidden;border:1px solid rgba(128,128,128,.7);box-shadow:0 2px 12px rgba(0,0,0,.55);}'
		+ '.schan-pv .message{display:block}.schan-pv img.thumb{max-width:100%}'
		+ '.schan-hl{box-shadow:inset 0 0 0 9999px rgba(232,165,0,.18) !important;}';
	document.head.appendChild(st);

	function transparent(c) { return !c || c === 'transparent' || /,\s*0\)$/.test(c); }

	// steal the theme's colors so the box matches
	function colors() {
		var probe = document.querySelector('td.reply, div.reply:not(.schan-pv)');
		var bg = probe ? getComputedStyle(probe).backgroundColor : '';
		if (transparent(bg)) { bg = getComputedStyle(document.body).backgroundColor; }
		if (transparent(bg)) { bg = getComputedStyle(document.documentElement).backgroundColor; }
		if (transparent(bg)) { bg = '#fff'; }
		return { bg: bg, fg: getComputedStyle(document.body).color };
	}

	// figure out which post a link points at from its text (>>123 or >>>/b/123)
	function parse(a) {
		var t = (a.textContent || '').replace(/^\s+|\s+$/g, '');
		var m = t.match(/^>>(?:>\/([a-z0-9_-]+)\/)?(\d+)/i);
		if (!m) { return null; }
		var board = pageBoard;
		if (m[1]) {
			board = m[1].toLowerCase();
		} else if (overboard && pageBoard === overboard) {
			var hm = (a.getAttribute('href') || '').match(/\/([a-z0-9_-]+)\/res\/\d+\.html/i);
			if (hm) { board = hm[1].toLowerCase(); }
		}
		if (boards.length && boards.indexOf(board) < 0) { board = pageBoard; }
		return { id: m[2], board: board };
	}

	function findLocal(info) {
		if (info.board !== pageBoard || (overboard && pageBoard === overboard)) { return null; }
		var el = document.getElementById('post' + info.id) || document.getElementById(info.id);
		if (!el || el.closest('.schan-pv')) { return null; }
		if (el.tagName === 'A') { el = el.closest('td.reply, div.op, div.reply') || el.parentNode; }
		return el;
	}

	// strip stuff that shouldn't be in a preview, and ids so we don't get duplicates
	function clean(node) {
		var junk = node.querySelectorAll('script, video, input, button, select, textarea, iframe, object, embed, [style*="display: none"], [style*="display:none"]');
		for (var i = 0; i < junk.length; i++) { if (junk[i].parentNode) { junk[i].parentNode.removeChild(junk[i]); } }
		var ids = node.querySelectorAll('[id]');
		for (var j = 0; j < ids.length; j++) { ids[j].removeAttribute('id'); }
	}

	// sits right of the mouse, flips left/below near the edges
	var mx = 0, my = 0, raf = 0;
	function place(box) {
		var w = box.offsetWidth, h = box.offsetHeight;
		var vw = window.innerWidth, vh = window.innerHeight, left, top;
		left = mx + 18;
		if (left + w > vw - 8) { left = mx - w - 18; }
		if (left < 8) {
			left = Math.max(8, Math.min(mx - 20, vw - w - 8));
			top = my + 22;
			if (top + h > vh - 8) { top = Math.max(8, my - h - 22); }
		} else {
			top = Math.max(8, Math.min(my - 12, vh - h - 8));
		}
		box.style.left = left + 'px';
		box.style.top = top + 'px';
	}

	function setContent(box, a, node, cls) {
		box.innerHTML = '';
		clean(node);
		box.className = 'schan-pv ' + (cls || 'reply');
		while (node.firstChild) { box.appendChild(node.firstChild); }
		var imgs = box.getElementsByTagName('img');
		for (var i = 0; i < imgs.length; i++) { imgs[i].addEventListener('load', function () { place(box); }); }
		place(box);
	}

	function fromHTML(box, a, html) {
		var tmp = document.createElement('div');
		tmp.innerHTML = html;
		var inner = tmp.children.length === 1 ? tmp.firstElementChild : null;
		var cls = 'reply';
		if (inner && /(^|\s)(reply|op)(\s|$)/.test(inner.className)) {
			cls = /(^|\s)op(\s|$)/.test(inner.className) ? 'op' : 'reply';
			tmp = inner;
		}
		if (!tmp.children.length && !/<[a-z]/i.test(html)) {
			box.textContent = html;
			box.className = 'schan-pv reply';
			place(box);
			return;
		}
		setContent(box, a, tmp, cls);
	}

	// one request per post, hover and prefetch share it
	var inflight = {};
	function load(info, cb) {
		var key = info.board + ':' + info.id;
		if (typeof cache[key] === 'string') { cb(cache[key]); return; }
		if (inflight[key]) { inflight[key].push(cb); return; }
		inflight[key] = [cb];
		var done = function (html) {
			var cbs = inflight[key];
			delete inflight[key];
			for (var i = 0; i < cbs.length; i++) { cbs[i](html); }
		};
		var xhr = new XMLHttpRequest();
		xhr.open('GET', root + '/imgboard.php?board=' + encodeURIComponent(info.board) + '&preview=' + encodeURIComponent(info.id) + '&res=1');
		xhr.onload = function () {
			var html = xhr.status === 200 ? xhr.responseText : 'Post not found';
			cache[key] = html;
			done(html);
		};
		xhr.onerror = function () { done(null); };
		xhr.send();
	}

	function fill(box, a, info) {
		var local = findLocal(info);
		if (local) {
			setContent(box, a, local.cloneNode(true), /(^|\s)op(\s|$)/.test(local.className) ? 'op' : 'reply');
			return;
		}
		load(info, function (html) {
			if (!box.parentNode) { return; }
			if (html === null) { box.textContent = 'Could not load post'; place(box); return; }
			fromHTML(box, a, html);
		});
	}

	// preload links to posts that aren't on the page so the hover is instant
	var queue = [], active = 0, seen = {};
	function pump() {
		while (active < 3 && queue.length) {
			active++;
			load(queue.shift(), function () { active--; pump(); });
		}
	}
	function scan() {
		var links = document.getElementsByTagName('a');
		for (var i = 0; i < links.length; i++) {
			var a = links[i];
			if (a.closest('.schan-pv') || !/^\s*>>/.test(a.textContent || '')) { continue; }
			var info = parse(a);
			if (!info) { continue; }
			var key = info.board + ':' + info.id;
			if (seen[key]) { continue; }
			seen[key] = 1;
			if (findLocal(info) || queue.length >= 150) { continue; }
			queue.push(info);
		}
		pump();
	}
	var scanTimer = null;
	function scanSoon(ms) {
		clearTimeout(scanTimer);
		scanTimer = setTimeout(scan, ms);
	}

	// only one box (or highlight) at a time
	var current = null, hl = null, hlOwner = null;
	function hide() {
		if (current && current.parentNode) { current.parentNode.removeChild(current); }
		current = null;
		if (hl) { hl.className = hl.className.replace(/(^|\s)schan-hl(?=\s|$)/g, ''); }
		hl = hlOwner = null;
	}

	// true if enough of the post is on screen to see a highlight
	function inView(el) {
		var r = el.getBoundingClientRect(), vh = window.innerHeight || document.documentElement.clientHeight;
		var visible = Math.min(r.bottom, vh) - Math.max(r.top, 0);
		return visible > 0 && visible >= Math.min(r.height, vh * 0.5);
	}

	function show(a, info) {
		var c = colors();
		var b = document.createElement('div');
		b.className = 'schan-pv reply';
		b.style.background = c.bg;
		b.style.color = c.fg;
		b.setAttribute('aria-hidden', 'true');
		b.owner = a;
		b.textContent = '...';
		document.body.appendChild(b);
		current = b;
		place(b);
		fill(b, a, info);
	}

	document.addEventListener('mouseover', function (e) {
		mx = e.clientX;
		my = e.clientY;
		var t = e.target;
		if (!t || !t.closest) { return; }
		var a = t.closest('a');
		var info = a ? parse(a) : null;
		if (!info) { hide(); return; }
		if ((current && current.owner === a) || hlOwner === a) { return; }
		hide();
		// target is already visible on the page -> just highlight it
		var local = findLocal(info);
		if (local && inView(local)) {
			hl = local;
			hlOwner = a;
			local.className += ' schan-hl';
			return;
		}
		show(a, info);
	});
	document.addEventListener('mouseout', function (e) {
		if (!e.relatedTarget) { hide(); }
	});
	document.addEventListener('mousemove', function (e) {
		mx = e.clientX;
		my = e.clientY;
		if (!current || raf) { return; }
		raf = window.requestAnimationFrame(function () {
			raf = 0;
			if (current) { place(current); }
		});
	}, { passive: true });

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { scanSoon(0); });
	} else {
		scanSoon(0);
	}
	// live updates add posts, rescan when that happens
	if (window.MutationObserver) {
		var postsEl = document.getElementById('posts');
		if (postsEl) {
			new MutationObserver(function () { scanSoon(400); }).observe(postsEl, { childList: true, subtree: true });
		}
	}
})();
JSEOF
	. '</script>';
	return $out;
}
