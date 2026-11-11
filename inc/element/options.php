<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// options link + window (style, hover preview, 5ch mode, user css), loaded by html.php
// returns array(link html, modal html)
function buildOptionsElement() {
	global $schan_stylesheets;

	$txt_style = __('Style');

	$select_style = '';
	if (count($schan_stylesheets) > 1) {
		$select_style = '<select id="switchStylesheet">';
		$select_style .= '<option value="">' . $txt_style . '</option>';
		foreach($schan_stylesheets as $filename => $title) {
			$select_style .= '<option value="' . htmlentities($filename, ENT_QUOTES) . '">'. htmlentities($title) . '</option>';
		}
		$select_style .= '</select>';
	}

	$options_link = '<a href="#" id="schanOptionsLink" class="btnlink">' . __('Options') . '</a>';
	$options_style_row = ($select_style === '') ? '' : '<div style="margin:6px 0;">' . $txt_style . ': ' . $select_style . '</div>';
	$options_modal = <<<'OPTIONSEOT'
<div id="schanOptions" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100000;background:rgba(0,0,0,.45);">
	<div class="reply" style="position:absolute;top:24px;left:50%;transform:translateX(-50%);width:620px;max-width:96vw;height:400px;max-height:90vh;display:flex;margin:0;padding:0;border:1px solid;box-sizing:border-box;overflow:hidden;">
		<div id="schanOptSide" style="width:100px;flex:none;border-right:1px solid;text-align:center;padding-top:6px;font-size:11px;">
			<a href="#" data-optstab="general" style="display:block;padding:6px 0;text-decoration:none;color:var(--opt-accent,inherit);">
				<svg viewBox="0 0 24 24" width="24" height="24" style="display:block;margin:0 auto 2px;"><path d="M12 3 1.5 12.5H5V21h5.5v-6h3v6H19v-8.5h3.5z" fill="currentColor"></path></svg>
				%%GENERAL%%
			</a>
			<a href="#" data-optstab="css" style="display:block;padding:6px 0;text-decoration:none;color:inherit;">
				<svg viewBox="0 0 24 24" width="24" height="24" style="display:block;margin:0 auto 2px;"><path d="M8 6 2 12l6 6M16 6l6 6-6 6M14 4l-4 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
				%%USERCSS%%
			</a>
		</div>
		<div style="flex:1;padding:10px 14px;position:relative;overflow:auto;">
			<a href="#" id="schanOptionsClose" title="Close" style="position:absolute;right:10px;top:4px;font-size:22px;line-height:1;text-decoration:none;color:inherit;">&#10006;</a>
			<div id="schanPanel_general">
				<div style="text-align:center;font-weight:bold;color:var(--opt-accent,inherit);margin-bottom:10px;">%%GENERAL%%</div>
				%%STYLEROW%%
				<div style="margin:6px 0;"><label><input type="checkbox" id="schanOptHover"> %%HOVER%%</label></div>
				<div style="margin:6px 0;"><label><input type="checkbox" id="schan5chMode"> %%FIVECH%%</label></div>
			</div>
			<div id="schanPanel_css" style="display:none;">
				<div style="text-align:center;font-weight:bold;color:var(--opt-accent,inherit);margin-bottom:10px;">%%USERCSS%%</div>
				<div style="font-size:smaller;margin-bottom:6px;">%%CSSNOTE%%</div>
				<textarea id="schanUserCssText" spellcheck="false" style="width:100%;height:230px;box-sizing:border-box;font-family:monospace;font-size:12px;"></textarea>
				<div style="margin-top:6px;"><input type="button" id="schanUserCssSave" value="%%SAVE%%"> <span id="schanUserCssStatus" style="font-size:smaller;"></span></div>
			</div>
		</div>
	</div>
</div>
<script type="text/javascript">
(function(){
	var link = document.getElementById('schanOptionsLink'), box = document.getElementById('schanOptions');
	if (!link || !box) { return; }
	var hover = document.getElementById('schanOptHover');
	var fivech = document.getElementById('schan5chMode');
	var side = document.getElementById('schanOptSide');
	var cssText = document.getElementById('schanUserCssText');
	var cssStatus = document.getElementById('schanUserCssStatus');

	function syncStyle(){
		var sel = document.getElementById('switchStylesheet');
		if (!sel) { return; }
		try {
			var links = document.querySelectorAll('link[rel~="stylesheet"]');
			var found = '';
			for (var i = 0; i < links.length; i++) {
				if (links[i].disabled) { continue; }
				var m = (links[i].getAttribute('href') || '').match(/\/([^\/]+)\.css(?:\?.*)?$/);
				if (!m || m[1] === 'global') { continue; }
				for (var j = 0; j < sel.options.length; j++) {
					if (sel.options[j].value === m[1]) { found = m[1]; }
				}
			}
			if (found !== '') { sel.value = found; }
		} catch (e) {}
	}
	function sync5ch(){
		var on = false;
		try { on = localStorage.getItem('schan_5ch') === '1'; } catch (e) {}
		fivech.checked = on;
	}
	function syncHover(){
		var on = !(window.schanDefaults && window.schanDefaults.hover === false);
		try { var v = localStorage.getItem('schan_hover'); if (v !== null) { on = v !== '0'; } } catch (e) {}
		hover.checked = on;
	}
	function syncAccent(){
		try {
			var p = document.createElement('span');
			p.className = 'filetitle';
			p.style.display = 'none';
			document.body.appendChild(p);
			var c = window.getComputedStyle(p).color;
			document.body.removeChild(p);
			if (c) { box.style.setProperty('--opt-accent', c); }
		} catch (e) {}
	}
	function showTab(name){
		var tabs = box.querySelectorAll('[data-optstab]');
		for (var i = 0; i < tabs.length; i++) {
			var n = tabs[i].getAttribute('data-optstab');
			var active = (n === name);
			tabs[i].style.color = active ? 'var(--opt-accent, inherit)' : 'inherit';
			var panel = document.getElementById('schanPanel_' + n);
			if (panel) { panel.style.display = active ? 'block' : 'none'; }
		}
		if (name === 'css') {
			var css = '';
			try { css = localStorage.getItem('schan_usercss') || ''; } catch (e) {}
			cssText.value = css;
			cssStatus.textContent = '';
		}
	}
	function open(e){
		if (e) { e.preventDefault(); }
		syncStyle();
		syncHover();
		sync5ch();
		syncAccent();
		showTab('general');
		box.style.display = 'block';
	}
	function close(e){
		if (e) { e.preventDefault(); }
		box.style.display = 'none';
	}
	link.addEventListener('click', open);
	document.getElementById('schanOptionsClose').addEventListener('click', close);
	box.addEventListener('click', function(e){ if (e.target === box) { close(); } });
	document.addEventListener('keydown', function(e){ if (e.key === 'Escape' || e.keyCode === 27) { close(); } });
	var tabLinks = box.querySelectorAll('[data-optstab]');
	for (var t = 0; t < tabLinks.length; t++) {
		tabLinks[t].addEventListener('click', function(e){
			e.preventDefault();
			showTab(this.getAttribute('data-optstab'));
		});
	}
	var styleSel = document.getElementById('switchStylesheet');
	if (styleSel) {
		var lastName = '', lastTime = 0;

		// the stylesheet links of the styles in the picker, with their names
		var styleLinks = function(){
			var out = [], links = document.querySelectorAll('link[rel~="stylesheet"]');
			for (var i = 0; i < links.length; i++) {
				var m = (links[i].getAttribute('href') || '').match(/^(.*\/)?([^\/]+)\.css(?:\?.*)?$/);
				if (!m || m[2] === 'global') { continue; }
				for (var j = 0; j < styleSel.options.length; j++) {
					if (styleSel.options[j].value === m[2]) { out.push({ el: links[i], name: m[2], base: m[1] || '' }); break; }
				}
			}
			return out;
		};

		// true once the chosen style is really loaded and on
		var isApplied = function(name){
			var all = styleLinks();
			for (var i = 0; i < all.length; i++) {
				if (all[i].name === name && !all[i].el.disabled && all[i].el.sheet) { return true; }
			}
			return false;
		};

		// switch the stylesheet links directly (used when schanSetStyle isn't there)
		var switchLinks = function(name){
			var all = styleLinks(), target = null, others = [], base = '';
			for (var i = 0; i < all.length; i++) {
				base = all[i].base;
				if (all[i].name === name) { target = all[i].el; } else { others.push(all[i].el); }
			}
			var finish = function(){
				for (var k = 0; k < others.length; k++) { others[k].disabled = true; }
				syncAccent();
			};
			if (target) {
				// alternate sheets ignore disabled=false if it was already false, so toggle it
				target.disabled = true;
				target.disabled = false;
				if (target.sheet) { finish(); } else { target.addEventListener('load', finish); }
			} else {
				// load the new sheet first, then drop the old ones so there is no unstyled flash
				var l = document.createElement('link');
				l.rel = 'stylesheet';
				l.href = base + encodeURIComponent(name) + '.css';
				l.onload = finish;
				document.head.appendChild(l);
			}
			try { localStorage.setItem('schan_style', name); } catch (e) {}
		};

		var runStyle = function(name){
			if (window.schanSetStyle) { window.schanSetStyle(name, true); } else { switchLinks(name); }
			syncAccent();
		};

		// swaps the theme the moment the select changes (input fires before change, on arrow keys too)
		var applyStyle = function(){
			var name = styleSel.value, now = Date.now();
			if (name === '') { return; }
			if (name === lastName && now - lastTime < 80) { return; }
			lastName = name;
			lastTime = now;
			runStyle(name);
			// first switch on a page can miss (sheet not loaded yet); if so, do it once more
			setTimeout(function(){
				if (styleSel.value === name && !isApplied(name)) { runStyle(name); }
				syncAccent();
			}, 300);
		};
		styleSel.addEventListener('input', applyStyle);
		styleSel.addEventListener('change', applyStyle);
	}
	fivech.addEventListener('change', function(){
		try { localStorage.setItem('schan_5ch', fivech.checked ? '1' : '0'); } catch (e) {}
		if (window.schan5chRender) { window.schan5chRender(); }
	});
	hover.addEventListener('change', function(){
		try { localStorage.setItem('schan_hover', hover.checked ? '1' : '0'); } catch (e) {}
	});
	document.getElementById('schanUserCssSave').addEventListener('click', function(){
		try {
			if (cssText.value.replace(/\s+/g, '') === '') {
				localStorage.removeItem('schan_usercss');
			} else {
				localStorage.setItem('schan_usercss', cssText.value);
			}
			if (window.schanApplyUserCSS) { window.schanApplyUserCSS(); }
			cssStatus.textContent = 'Saved.';
		} catch (e) {
			cssStatus.textContent = 'Could not save (browser storage unavailable).';
		}
	});
})();
</script>
OPTIONSEOT;
	$options_modal = str_replace(array('%%GENERAL%%', '%%USERCSS%%', '%%STYLEROW%%', '%%HOVER%%', '%%FIVECH%%', '%%CSSNOTE%%', '%%SAVE%%'), array(__('General'), __('User CSS'), $options_style_row, __('Image hover preview'), __('5ch mode (number posts by their place in the thread)'), __('Your own CSS, saved in this browser and applied on every page.'), __('Save')), $options_modal);

	return array($options_link, $options_modal);
}
