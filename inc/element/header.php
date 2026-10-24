<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// head, board header, stylesheets, footer. html.php loads this

require_once __DIR__ . '/banner.php';

function pageHeader($render_board = null) {
	if ($render_board === null) {
		$render_board = current_board();
	}
	$board_row = currentBoardRow($render_board);

	$parts = array();

	$slug = isset($board_row['slug']) ? trim($board_row['slug']) : '';
	if ($slug === '') {
		$slug = $render_board;
	}
	if ($slug !== '') {
		$parts[] = $slug;
	}

	$btitle = isset($board_row['title']) ? trim($board_row['title']) : '';
	if ($btitle !== '') {
		$parts[] = $btitle;
	}

	$bdesc = isset($board_row['desc']) ? trim($board_row['desc']) : '';
	if ($bdesc !== '') {
		$parts[] = $bdesc;
	}

	if (empty($parts)) {
		$title = schanOpt('SCHAN_SITENAME', 'Schan');
	} else {
		$title = implode(' - ', $parts);
	}

	$js_captcha = '';
	if (SCHAN_CAPTCHA === 'hcaptcha' || SCHAN_REPLYCAPTCHA === 'hcaptcha' || SCHAN_MANAGECAPTCHA === 'hcaptcha') {
		$js_captcha .= '<script src="https://www.hcaptcha.com/1/api.js" async defer></script>';
	}
	if (SCHAN_CAPTCHA === 'recaptcha' || SCHAN_REPLYCAPTCHA === 'recaptcha' || SCHAN_MANAGECAPTCHA === 'recaptcha') {
		$js_captcha .= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
	}

	$stylesheets = pageStylesheets($render_board);

	$install_root = installRootURL();
	$favicon = schanOpt('SCHAN_FAVICON', '') !== '' ? SCHAN_FAVICON : $install_root . '/favicon.ico';
	$jquery = $install_root . '/js/jquery.js';
	$schanjs = $install_root . '/js/schan.js';

	$board_js  = 'var currentBoard = ' . json_encode($render_board) . ';';
	$board_js .= "\n" . 'var schanRoot = ' . json_encode($install_root) . ';';
	$board_js .= "\n" . 'var schanDefaults = ' . json_encode(array('hover' => (bool)schanOpt('SCHAN_HOVERDEFAULT', true))) . ';';

	$nocache_meta = '';
	if (schanOpt('SCHAN_NOCACHE', true)) {
		$nocache_meta = '<meta http-equiv="cache-control" content="max-age=0">' . "\n\t\t"
			. '<meta http-equiv="cache-control" content="no-cache">' . "\n\t\t"
			. '<meta http-equiv="expires" content="0">' . "\n\t\t"
			. '<meta http-equiv="expires" content="Tue, 01 Jan 1980 1:00:00 GMT">' . "\n\t\t"
			. '<meta http-equiv="pragma" content="no-cache">';
	}
	$text_css = '.unkfunc, .greentext { color: ' . schanOpt('SCHAN_GREENTEXT', '#789922') . ' !important; }' . "\n\t\t"
		. '.orangetext { color: ' . schanOpt('SCHAN_ORANGETEXT', '#e08a00') . ' !important; }' . "\n\t\t"
		. '.bluetext { color: ' . schanOpt('SCHAN_BLUETEXT', '#3b7dd8') . ' !important; }' . "\n\t\t"
		. '.quotebtn { text-decoration: none; }' . "\n\t\t"
		. schanOpt('SCHAN_CUSTOMCSS', '');
	$head_extra = schanOpt('SCHAN_HEADEXTRA', '');
	$ruffle_js = '';
	if (schanOpt('SCHAN_RUFFLE', true)) {
		$ruffle_js = '<script src="' . htmlentities(schanOpt('SCHAN_RUFFLEURL', 'https://unpkg.com/@ruffle-rs/ruffle'), ENT_QUOTES) . '"></script>';
	}

	$poll_js = function_exists('pollJS') ? pollJS() : '';

	return <<<EOF
<!DOCTYPE html>
<html>
	<head>
		<meta http-equiv="content-type" content="text/html;charset=UTF-8">
		$nocache_meta
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<title>$title</title>
		<link rel="shortcut icon" href="$favicon">
		$stylesheets
		<style type="text/css">
		$text_css
		</style>
		$head_extra
		<script type="text/javascript">$board_js</script>
		<script type="text/javascript">
		(function(){
			var KEY = 'schan_hidethreads';
			function load(){ try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
			function save(o){ try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) {} }
			function keep(k){
				return k.nodeName === 'A' || k.nodeName === 'LABEL' ||
					(k.classList && (k.classList.contains('reflink') || k.classList.contains('threadtoggle')));
			}
			function mark(k, hide){
				if (hide) { k.classList.add('schan-collapsed'); } else { k.classList.remove('schan-collapsed'); }
			}
			function apply(op, hide){
				var k;
				for (k = op.firstElementChild; k; k = k.nextElementSibling) {
					if (!keep(k)) { mark(k, hide); }
				}
				for (k = op.nextElementSibling; k && k.nodeName !== 'HR' && !k.classList.contains('op'); k = k.nextElementSibling) {
					mark(k, hide);
				}
			}
			function replyLink(op){
				for (var k = op.firstElementChild; k; k = k.nextElementSibling) {
					if (k.nodeName === 'A' && (k.getAttribute('href') || '').indexOf('res/') === 0) { return k; }
				}
				return null;
			}
			function init(){
				var st = document.createElement('style');
				st.textContent = '.schan-collapsed{display:none !important;}';
				document.head.appendChild(st);
				var ops = document.querySelectorAll('#posts > div.op');
				var hidden = load();
				for (var i = 0; i < ops.length; i++) {
					(function(op){
						var reply = replyLink(op);
						if (!reply) { return; }
						var key = (window.currentBoard || '') + ':' + op.id.replace('post', '');
						var box = document.createElement('span'), a = document.createElement('a');
						box.className = 'threadtoggle';
						box.style.cssText = 'display:block;margin:0 6px 2px 0;';
						a.href = '#';
						a.title = 'Hide / show thread';
						box.appendChild(document.createTextNode('['));
						box.appendChild(a);
						box.appendChild(document.createTextNode(']'));
						op.insertBefore(box, op.firstChild);
						function set(hide){
							a.textContent = hide ? '+' : '-';
							box.style.display = hide ? 'inline' : 'block';
							apply(op, hide);
						}
						a.onclick = function(e){
							e.preventDefault();
							var hide = !hidden[key];
							if (hide) { hidden[key] = 1; } else { delete hidden[key]; }
							save(hidden);
							set(hide);
						};
						set(!!hidden[key]);
					})(ops[i]);
				}
			}
			if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
		})();
		</script>
		<script type="text/javascript">
		(function(){
			var KEY = 'schan_5ch';
			function enabled(){ try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
			function groups(){
				var box = document.getElementById('posts'), out = [], cur = null, k;
				if (!box) { return out; }
				for (k = box.firstElementChild; k; k = k.nextElementSibling) {
					if (k.nodeName === 'DIV' && k.classList.contains('op')) {
						var tid = k.id.replace('post', '');
						cur = { thread: tid, omitted: 0, items: [{ el: k, id: tid }] };
						out.push(cur);
						continue;
					}
					if (!cur) { continue; }
					if (k.nodeName === 'HR') { cur = null; continue; }
					if (k.classList.contains('omittedposts')) {
						var m = /(\d+)/.exec(k.textContent);
						cur.omitted = m ? parseInt(m[1], 10) : 0;
						continue;
					}
					var td = k.querySelector ? k.querySelector('td.reply[id^="post"]') : null;
					if (td) { cur.items.push({ el: td, id: td.id.replace('post', '') }); }
				}
				for (var g = 0; g < out.length; g++) {
					for (var i = 0; i < out[g].items.length; i++) {
						out[g].items[i].num = (i === 0) ? 1 : (1 + out[g].omitted + i);
					}
				}
				return out;
			}
			function setText(a, text, real){
				if (!a.hasAttribute('data-orig5')) { a.setAttribute('data-orig5', a.textContent); }
				a.textContent = text;
				if (real) { a.title = real; }
			}
			function render(){
				var box = document.getElementById('posts');
				if (!box) { return; }
				var i, j, restore = box.querySelectorAll('[data-orig5]');
				for (i = 0; i < restore.length; i++) {
					restore[i].textContent = restore[i].getAttribute('data-orig5');
					restore[i].removeAttribute('data-orig5');
					restore[i].removeAttribute('title');
				}
				if (!enabled()) { return; }
				var gs = groups(), map = {};
				for (i = 0; i < gs.length; i++) {
					for (j = 0; j < gs[i].items.length; j++) { map[gs[i].thread + ':' + gs[i].items[j].id] = gs[i].items[j].num; }
				}
				for (i = 0; i < gs.length; i++) {
					for (j = 0; j < gs[i].items.length; j++) {
						var it = gs[i].items[j];
						var ref = it.el.querySelector('.reflink');
						if (ref) {
							var as = ref.getElementsByTagName('a');
							for (var x = 0; x < as.length; x++) {
								if (as[x].textContent === it.id) { setText(as[x], String(it.num), 'No.' + it.id); break; }
							}
						}
						var links = it.el.getElementsByTagName('a');
						for (var y = 0; y < links.length; y++) {
							var t = links[y].textContent, q = /^>>(\d+)/.exec(t);
							if (!q || q[0].length !== t.length || links[y].hasAttribute('data-orig5')) { continue; }
							var href = links[y].getAttribute('href') || '', parts = href.split('#'), tm = /(\d+)\.html/.exec(parts[0]);
							var key = (tm ? tm[1] : gs[i].thread) + ':' + q[1];
							if (map.hasOwnProperty(key)) { setText(links[y], '>>' + map[key], 'No.' + q[1]); }
						}
					}
				}
			}
			window.schan5chRender = render;
			var timer = null;
			function later(){ if (timer) { clearTimeout(timer); } timer = setTimeout(render, 60); }
			function init(){
				render();
				var box = document.getElementById('posts');
				if (box && window.MutationObserver) { new MutationObserver(later).observe(box, { childList: true }); }
			}
			if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
			window.addEventListener('load', render);
		})();
		</script>
		<script type="text/javascript">
		(function(){
			function apply(){
				var css = '';
				try { css = localStorage.getItem('schan_usercss') || ''; } catch (e) {}
				var el = document.getElementById('schanUserCSS');
				if (css === '') {
					if (el && el.parentNode) { el.parentNode.removeChild(el); }
					return;
				}
				if (!el) { el = document.createElement('style'); el.id = 'schanUserCSS'; }
				el.textContent = css;
				document.head.appendChild(el);
			}
			window.schanApplyUserCSS = apply;
			apply();
			document.addEventListener('DOMContentLoaded', apply);
			window.addEventListener('load', apply);
		})();
		</script>
		<script type="text/javascript">
		(function(){
			var NL = String.fromCharCode(10);

			// > green, < orange, ^ blue
			function lineClass(t){
				if (/^\\s*</.test(t)) { return 'orangetext'; }
				if (/^\\s*\\^/.test(t)) { return 'bluetext'; }
				if (/^\\s*>(?!>)/.test(t)) { return 'greentext'; }
				return '';
			}
			function flush(msg, line){
				var j, first = null;
				for (j = 0; j < line.length; j++) {
					if (line[j].nodeType === 3 && line[j].nodeValue.trim() === '') { continue; }
					first = line[j];
					break;
				}
				if (!first || first.nodeType !== 3) { return; }
				var c = lineClass(first.nodeValue);
				if (c === '') { return; }
				var sp = document.createElement('span');
				sp.className = c;
				msg.insertBefore(sp, line[0]);
				for (j = 0; j < line.length; j++) { sp.appendChild(line[j]); }
			}
			function colourOne(msg){
				if (msg.getAttribute('data-lc')) { return; }
				msg.setAttribute('data-lc', '1');
				var nodes = Array.prototype.slice.call(msg.childNodes), line = [], i;
				for (i = 0; i < nodes.length; i++) {
					if (nodes[i].nodeName === 'BR') { flush(msg, line); line = []; } else { line.push(nodes[i]); }
				}
				flush(msg, line);
			}

			// [Quote] button, whole post as greentext
			function postText(ref){
				var box = ref.closest ? ref.closest('div.op, td.reply, div.reply') : null;
				var msg = box ? box.querySelector('.message') : null;
				if (!msg) { return ''; }
				var c = msg.cloneNode(true), n, i;
				n = c.querySelectorAll('.omittedposts');
				for (i = 0; i < n.length; i++) { n[i].parentNode.removeChild(n[i]); }
				n = c.querySelectorAll('br');
				for (i = 0; i < n.length; i++) { n[i].parentNode.replaceChild(document.createTextNode(NL), n[i]); }
				n = c.querySelectorAll('img');
				for (i = 0; i < n.length; i++) { n[i].parentNode.replaceChild(document.createTextNode(n[i].getAttribute('alt') || ''), n[i]); }
				return c.textContent;
			}
			function quote(ref, id){
				var ta = document.getElementById('message');
				if (!ta) { return; }
				var lines = postText(ref).split(NL), out = ['>>' + id], i, t;
				for (i = 0; i < lines.length; i++) {
					t = lines[i].trimEnd ? lines[i].trimEnd() : lines[i].trim();
					if (t.trim() === '') { continue; }
					out.push('>' + (t.charAt(0) === '>' ? ' ' : '') + t);
				}
				var text = out.join(NL) + NL, v = ta.value, s = ta.selectionStart, e = ta.selectionEnd;
				if (typeof s !== 'number') { s = e = v.length; }
				if (s > 0 && v.charAt(s - 1) !== NL) { text = NL + text; }
				ta.value = v.slice(0, s) + text + v.slice(e);
				ta.focus();
				var pos = s + text.length;
				if (ta.setSelectionRange) { ta.setSelectionRange(pos, pos); }
			}
			function addButton(ref){
				if (ref.getAttribute('data-qb')) { return; }
				var link = ref.querySelector('a[href*="#q"]'), m = link ? /#q(\\d+)/.exec(link.getAttribute('href') || '') : null;
				if (!m) { return; }
				ref.setAttribute('data-qb', '1');
				var a = document.createElement('a');
				a.href = '#';
				a.className = 'quotebtn';
				a.title = 'Quote this whole post as greentext';
				a.textContent = 'Quote';
				a.onclick = function(ev){ ev.preventDefault(); quote(ref, m[1]); };
				ref.appendChild(document.createTextNode(' '));
				ref.appendChild(a);
			}

			function all(){
				var i, m = document.querySelectorAll('.message'), r = document.querySelectorAll('.reflink');
				for (i = 0; i < m.length; i++) { colourOne(m[i]); }
				for (i = 0; i < r.length; i++) { addButton(r[i]); }
			}
			function init(){
				all();
				var box = document.getElementById('posts');
				if (box && window.MutationObserver) { new MutationObserver(all).observe(box, { childList: true, subtree: true }); }
			}
			document.addEventListener('schan:updated', all);
			if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
		})();
		</script>
		<script type="text/javascript">
		// floating reply window: form pops into a draggable box once you scroll past it
		(function(){
			if (!/\\/res\\/\\d+\\.html/.test(location.pathname)) { return; }
			var KEY = 'schan_qrpos', area = null, anchor = null, win = null, bar = null, body = null;
			var floating = false, dismissed = false, pos = null;

			function load(){
				try {
					var o = JSON.parse(localStorage.getItem(KEY) || 'null');
					if (o && typeof o.x === 'number' && typeof o.y === 'number') { return o; }
				} catch (e) {}
				return null;
			}
			function save(){ try { localStorage.setItem(KEY, JSON.stringify(pos)); } catch (e) {} }
			function clamp(){
				var vw = document.documentElement.clientWidth, vh = window.innerHeight;
				pos.x = Math.min(Math.max(0, pos.x), Math.max(0, vw - Math.min(win.offsetWidth, vw)));
				pos.y = Math.min(Math.max(0, pos.y), Math.max(0, vh - 32));
				win.style.left = pos.x + 'px';
				win.style.top = pos.y + 'px';
			}
			// moving the form kills focus, so restore it
			function moving(fn){
				var act = document.activeElement, keep = act && area.contains(act), a = 0, b = 0;
				if (keep && typeof act.selectionStart === 'number') { a = act.selectionStart; b = act.selectionEnd; }
				fn();
				if (keep) {
					act.focus();
					try { act.setSelectionRange(a, b); } catch (e) {}
				}
			}
			function busy(){
				var t = area.querySelector('.oek-tools');
				return !!(t && t.style.display === 'block');
			}
			function toWindow(){
				if (floating) { return; }
				anchor.style.height = area.offsetHeight + 'px';
				moving(function(){ body.appendChild(area); });
				win.style.display = 'block';
				if (!pos) { pos = { x: document.documentElement.clientWidth - win.offsetWidth - 20, y: 60 }; }
				clamp();
				floating = true;
			}
			function toPage(){
				if (!floating) { return; }
				moving(function(){ anchor.parentNode.insertBefore(area, anchor.nextSibling); });
				anchor.style.height = '0px';
				win.style.display = 'none';
				floating = false;
			}
			function check(){
				if (busy()) { return; }
				var bottom = (floating ? anchor : area).getBoundingClientRect().bottom;
				if (bottom >= 0) { dismissed = false; toPage(); return; }
				if (!dismissed) { toWindow(); }
			}

			function build(){
				win = document.createElement('div');
				win.id = 'schanQR';
				win.className = 'reply';
				win.style.cssText = 'display:none;position:fixed;z-index:99998;margin:0;padding:0;border:1px solid;box-sizing:border-box;max-width:96vw;max-height:90vh;overflow:hidden;';
				bar = document.createElement('div');
				bar.className = 'postblock';
				bar.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:3px 8px;cursor:move;user-select:none;touch-action:none;font-weight:bold;';
				var title = document.createElement('span');
				title.textContent = 'Reply';
				var close = document.createElement('a');
				close.href = '#';
				close.title = 'Close';
				close.textContent = String.fromCharCode(10006);
				close.style.cssText = 'text-decoration:none;color:inherit;margin-left:12px;cursor:pointer;';
				close.onclick = function(e){ e.preventDefault(); dismissed = true; toPage(); };
				bar.appendChild(title);
				bar.appendChild(close);
				body = document.createElement('div');
				body.style.cssText = 'overflow:auto;padding:4px;max-height:calc(90vh - 32px);';
				win.appendChild(bar);
				win.appendChild(body);
				document.body.appendChild(win);

				var sx = 0, sy = 0, ox = 0, oy = 0, drag = false;
				bar.addEventListener('pointerdown', function(e){
					if (e.target === close) { return; }
					drag = true; sx = e.clientX; sy = e.clientY; ox = pos.x; oy = pos.y;
					try { bar.setPointerCapture(e.pointerId); } catch (x) {}
					e.preventDefault();
				});
				bar.addEventListener('pointermove', function(e){
					if (!drag) { return; }
					pos.x = ox + e.clientX - sx;
					pos.y = oy + e.clientY - sy;
					clamp();
				});
				function stop(e){
					if (!drag) { return; }
					drag = false;
					try { bar.releasePointerCapture(e.pointerId); } catch (x) {}
					save();
				}
				bar.addEventListener('pointerup', stop);
				bar.addEventListener('pointercancel', stop);
			}

			function init(){
				area = document.querySelector('.postarea');
				if (!area) { return; }
				pos = load();
				anchor = document.createElement('div');
				anchor.style.height = '0px';
				area.parentNode.insertBefore(anchor, area);
				build();
				window.addEventListener('scroll', check, { passive: true });
				window.addEventListener('resize', function(){ if (floating) { clamp(); } check(); });
				check();
			}
			if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
		})();
		</script>
		<script src="$jquery"></script>
		<script src="$schanjs"></script>
		$ruffle_js
		<script type="text/javascript">
		function toggleSage(cb){
			var f=document.getElementById("emailfield");
			if(!f){return true;}
			if(cb.checked){
				if(f.value.toLowerCase()!=="sage"){
					f.setAttribute("data-prev", f.value);
				}
				f.value="sage";
			} else {
				if(f.value.toLowerCase()==="sage"){
					f.value=f.getAttribute("data-prev")||"";
				}
			}
			return true;
		}
		</script>
		$poll_js
		<script type="text/javascript">
		window.expandFile = function(e, id){
			if (e && (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1)) { return true; }
			var thumb = document.getElementById('thumbfile' + id);
			var full = document.getElementById('file' + id);
			var src = document.getElementById('expand' + id);
			if (!thumb || !full || !src) { return true; }
			if (full.style.display === 'none' || full.style.display === '') {
				if (full.getAttribute('data-loaded') !== '1') {
					var html = src.textContent || src.innerHTML;
					try { html = decodeURIComponent(html); } catch (x) {}
					if (/<(video|object)[ >]/i.test(html)) {
						// videos/flash can't be clicked shut, so they get a close button beside the player
						// (player isn't moved, otherwise playback restarts)
						var mkClose = function(){
							var c = document.createElement('a');
							c.href = '#';
							c.className = 'expandclose';
							c.title = 'Close (Esc)';
							// svg and not a text x, liveupdate.php compares text length and would redraw the post
							c.innerHTML = '<svg width="10" height="10" viewBox="0 0 10 10" style="display:block"><path d="M1 1L9 9M9 1L1 9" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>';
							c.style.cssText = 'flex:0 0 auto;display:flex;align-items:center;justify-content:center;width:1.5em;height:1.5em;border:1px solid;text-decoration:none;cursor:pointer;';
							c.onclick = function(ev){
								ev.preventDefault();
								ev.stopPropagation();
								window.expandFile(null, id);
							};
							return c;
						};
						var row = document.createElement('div');
						row.className = 'expandrow';
						row.style.cssText = 'display:flex;align-items:flex-start;gap:3px;max-width:100%;';
						var inner = document.createElement('div');
						inner.style.cssText = 'min-width:0;';
						inner.innerHTML = html;
						row.appendChild(mkClose());
						row.appendChild(inner);
						full.innerHTML = '';
						full.appendChild(row);
						full.setAttribute('data-closable', '1');
					} else {
						full.innerHTML = html;
					}
					full.setAttribute('data-loaded', '1');
				}
				thumb.style.display = 'none';
				full.style.display = full.getAttribute('data-closable') === '1' ? 'inline-block' : 'block';
				if (full.getAttribute('data-closable') === '1') { window.schanLastClosable = id; }
			} else {
				full.style.display = 'none';
				full.innerHTML = '';
				full.removeAttribute('data-loaded');
				full.removeAttribute('data-closable');
				if (window.schanLastClosable === id) { window.schanLastClosable = null; }
				thumb.style.display = '';
			}
			if (e && e.preventDefault) { e.preventDefault(); }
			return false;
		};
		// esc closes the last opened video/flash only
		document.addEventListener('keydown', function(e){
			if (e.key !== 'Escape') { return; }
			var t = e.target;
			if (t && (t.nodeName === 'INPUT' || t.nodeName === 'TEXTAREA' || t.nodeName === 'SELECT')) { return; }
			var id = window.schanLastClosable;
			var f = id ? document.getElementById('file' + id) : null;
			if (f && f.getAttribute('data-closable') === '1') { window.expandFile(null, id); }
		});
		</script>
		<script type="text/javascript">
		(function(){
			var pv = null, curThumb = null, lastX = 0, lastY = 0;
			var MARGIN = 20;

			function fileExt(url){
				return url.split('#')[0].split('?')[0].split('.').pop().toLowerCase();
			}

			function previewURL(img){
				var a = img.parentNode;
				while (a && a.nodeName !== 'A') { a = a.parentNode; }
				if (!a) { return null; }
				var href = a.getAttribute('href') || '';
				if (href === '' || href === '#') { return null; }
				var ext = fileExt(href);
				if (['jpg','jpeg','png','gif','webp','svg','webm','mp4'].indexOf(ext) === -1) { return null; }
				return {url: href, video: (ext === 'webm' || ext === 'mp4')};
			}

			function position(){
				if (!pv) { return; }
				var vw = document.documentElement.clientWidth;
				var vh = window.innerHeight;
				var rightSpace = vw - lastX - MARGIN * 2;
				var leftSpace = lastX - MARGIN * 2;
				var onRight = rightSpace >= leftSpace;
				var maxW = Math.max(100, onRight ? rightSpace : leftSpace);
				var maxH = vh - 10;
				pv.style.maxWidth = maxW + 'px';
				pv.style.maxHeight = maxH + 'px';

				var w = pv.offsetWidth, h = pv.offsetHeight;
				var left = onRight ? lastX + MARGIN : lastX - MARGIN - w;
				var top = lastY - h / 2;
				if (top + h > vh - 5) { top = vh - h - 5; }
				if (top < 5) { top = 5; }
				pv.style.left = Math.max(0, left) + 'px';
				pv.style.top = top + 'px';
			}

			function hide(){
				if (pv) {
					if (pv.pause) { try { pv.pause(); } catch (e) {} }
					if (pv.parentNode) { pv.parentNode.removeChild(pv); }
					pv = null;
				}
				curThumb = null;
			}

			function show(img, info){
				hide();
				curThumb = img;
				var el;
				if (info.video) {
					el = document.createElement('video');
					el.muted = true;
					el.loop = true;
					el.autoplay = true;
					el.setAttribute('playsinline', '');
					el.addEventListener('loadedmetadata', position);
				} else {
					el = document.createElement('img');
					el.addEventListener('load', position);
				}
				el.style.cssText = 'position:fixed;z-index:99999;pointer-events:none;display:block;'
					+ 'border:0;background:#000;box-shadow:0 0 6px rgba(0,0,0,.6);'
					+ 'width:auto;height:auto;left:0;top:0;';
				el.src = info.url;
				pv = el;
				document.body.appendChild(el);
				position();
				if (info.video && el.play) { var p = el.play(); if (p && p.catch) { p.catch(function(){}); } }
			}

			document.addEventListener('mouseover', function(e){
				var t = e.target;
				if (!t || t.nodeName !== 'IMG' || !t.classList.contains('thumb')) { return; }
				try { var hv = localStorage.getItem('schan_hover'); if (hv === '0' || (hv === null && window.schanDefaults && window.schanDefaults.hover === false)) { return; } } catch (e) {}
				if (t.getAttribute('data-spoiler')) { return; }
				var info = previewURL(t);
				if (!info) { return; }
				lastX = e.clientX; lastY = e.clientY;
				show(t, info);
			});
			document.addEventListener('mousemove', function(e){
				if (!pv) { return; }
				lastX = e.clientX; lastY = e.clientY;
				position();
			});
			document.addEventListener('mouseout', function(e){
				if (e.target === curThumb) { hide(); }
			});
			document.addEventListener('mousedown', hide);
			document.addEventListener('scroll', function(){ if (pv) { position(); } }, true);
			window.addEventListener('blur', hide);
		})();
		</script>
		$js_captcha
	</head>
EOF;
}

function boardHeaderHTML($board = null) {
	if (!empty($GLOBALS['schan_hide_header'])) {
		return '';
	}
	$board_row = currentBoardRow($board);

	$slug  = isset($board_row['slug'])  && $board_row['slug']  !== '' ? $board_row['slug']  : ($board === null ? current_board() : $board);
	$title = isset($board_row['title']) ? trim($board_row['title']) : '';
	$desc  = isset($board_row['desc'])  ? trim($board_row['desc'])  : '';
	$logo  = isset($board_row['logo'])  ? trim($board_row['logo'])  : '';

	$html = '';
	if ($logo !== '') {
		$html .= $logo;
	}

	$parts = array();
	if ($slug !== '') {
		$parts[] = '<span class="boardslug">/' . htmlentities($slug) . '/</span>';
	}
	if ($title !== '') {
		$parts[] = '<span class="boardtitle">' . htmlentities($title) . '</span>';
	}

	if (!empty($parts)) {
		$html .= implode(' &middot; ', $parts);
	}
	if ($desc !== '') {
		$html .= '<div class="boarddesc" style="font-size:0.5em;text-align:center;">' . htmlentities($desc) . '</div>';
	}

	if (empty($GLOBALS['schan_no_banner'])) {
		$html .= bannerHTML();
	}

	return $html;
}
function pageStylesheets($render_board = null) {
	global $schan_stylesheets;

	if ($render_board === null) {
		$render_board = current_board();
	}

	$install_root = installRootURL();

	$return = '<link rel="stylesheet" type="text/css" href="' . $install_root . '/css/global.css">';
	$return .= '<link rel="stylesheet" type="text/css" href="' . $install_root . '/css/' . htmlentities(SCHAN_DEFAULTSTYLE, ENT_QUOTES) . '.css" title="' . htmlentities($schan_stylesheets[SCHAN_DEFAULTSTYLE], ENT_QUOTES) . '" id="mainStylesheet">';

	foreach ($schan_stylesheets as $filename => $title) {
		if ($filename === SCHAN_DEFAULTSTYLE) {
			continue;
		}
		$return .= '<link rel="alternate stylesheet" type="text/css" href="' . $install_root . '/css/' . htmlentities($filename, ENT_QUOTES) . '.css" title="' . htmlentities($title, ENT_QUOTES) . '">';
	}

	$return .= <<<'STYLEJS'
<script type="text/javascript">
(function(){
	function styleName(l){
		var m = (l.getAttribute('href') || '').match(/\/([^\/]+)\.css(?:\?.*)?$/);
		return m ? m[1] : '';
	}
	window.schanSetStyle = function(name, save){
		var links = document.querySelectorAll('link[rel~="stylesheet"]');
		var target = null, i;
		for (i = 0; i < links.length; i++) {
			if (styleName(links[i]) === name && links[i].getAttribute('title')) { target = links[i]; }
		}
		if (!target) { return false; }
		for (i = 0; i < links.length; i++) {
			if (styleName(links[i]) === 'global' || !links[i].getAttribute('title')) { continue; }
			links[i].disabled = true;
		}
		target.disabled = false;
		if (save) {
			try { localStorage.setItem('schan_style', name); } catch (e) {}
		}
		return true;
	};
	var saved = '';
	try { saved = localStorage.getItem('schan_style') || ''; } catch (e) {}
	if (saved !== '') { window.schanSetStyle(saved, false); }
})();
</script>
STYLEJS;

	return $return;
}

function pageFooter() {
	$footer_html = schanOpt('SCHAN_FOOTER', 'Schan');
	return <<<EOF
		<div class="footer">
			$footer_html
		</div>
	</body>
</html>
EOF;
}
