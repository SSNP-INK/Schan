<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// board links bar, options window, post form. loaded by html.php

// links bar at the top
function boardSwitcher($current = null) {
	global $schan_boardbar_links;

	if ($current === null) {
		$current = current_board();
	}

	$install_root = installRootURL();

	$board_links = array();
	if (defined('SCHAN_OVERBOARD') && function_exists('rebuildOverboard')) {
		$ocls = ($current === SCHAN_OVERBOARD) ? ' class="current"' : '';
		$board_links[] = '<a href="' . htmlentities($install_root . '/' . SCHAN_OVERBOARD . '/', ENT_QUOTES) . '"' . $ocls . ' title="overboard">' . htmlentities(SCHAN_OVERBOARD) . '</a>';
	}
	if (function_exists('allBoards')) {
		foreach (allBoards() as $b) {
			$url = $install_root . '/' . rawurlencode($b['slug']) . '/';
			$cls = ($b['slug'] === $current) ? ' class="current"' : '';
			$title = $b['title'] != '' ? ' title="' . htmlentities($b['title'], ENT_QUOTES) . '"' : '';
			$board_links[] = '<a href="' . htmlentities($url, ENT_QUOTES) . '"' . $cls . $title . '>' . htmlentities($b['slug']) . '</a>';
		}
	}

	$custom_links = array();
	if (!empty($schan_boardbar_links) && is_array($schan_boardbar_links)) {
		foreach ($schan_boardbar_links as $link) {
			if (!is_array($link) || count($link) < 2) {
				continue;
			}
			$custom_links[] = '<a href="' . htmlentities($link[1], ENT_QUOTES) . '">' . htmlentities($link[0]) . '</a>';
		}
	}

	$groups = array();
	if (!empty($board_links)) {
		$groups[] = '[ <span class="boardlinks">' . implode(' / ', $board_links) . '</span> ]';
	}
	if (!empty($custom_links)) {
		$groups[] = '[ <span class="customlinks">' . implode(' / ', $custom_links) . '</span> ]';
	}
	if (empty($groups)) {
		return '';
	}

	return '<div class="boardbar" style="' . htmlentities(schanOpt('SCHAN_BOARDBARSTYLE', 'position:absolute;'), ENT_QUOTES) . '">' . implode(' ', $groups) . '</div>';
}

// Options link + window (style, hover preview, 5ch mode, user css)
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
		styleSel.addEventListener('change', function(){
			if (this.value === '') { return; }
			if (window.schanSetStyle) { window.schanSetStyle(this.value, true); }
			syncAccent();
		});
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

// post form. the Extras row (postFormExtrasRow) lives in post_form.php
function buildPostForm($parent, $staff_post = false, $render_board = null) {
	global $schan_hidefieldsop, $schan_hidefields, $schan_uploads, $schan_embeds;
	$hide_fields = $parent == SCHAN_NEWTHREAD ? $schan_hidefieldsop : $schan_hidefields;

	if ($render_board === null) {
		$render_board = current_board();
	}
	$hide_fields = boardApplyFeatureOverrides($hide_fields, $render_board);

	$postform_extra = array('name' => '', 'email' => '', 'subject' => '', 'footer' => '');
	$input_submit = '<input type="submit" value="' . __('Submit') . '" accesskey="z">';
	if ($staff_post || !in_array('subject', $hide_fields)) {
		$postform_extra['subject'] = $input_submit;
	} else if (!in_array('email', $hide_fields)) {
		$postform_extra['email'] = $input_submit;
	} else if (!in_array('name', $hide_fields)) {
		$postform_extra['name'] = $input_submit;
	} else if (!in_array('email', $hide_fields)) {
		$postform_extra['email'] = $input_submit;
	} else {
		$postform_extra['footer'] = $input_submit;
	}

	$board = $render_board;

	if ($staff_post) {
		$form_action = $board === '' ? '?' : installRootURL() . '/imgboard.php?';
	} else if ($parent == SCHAN_NEWTHREAD) {
		$form_action = $board === '' ? 'imgboard.php' : '../imgboard.php';
	} else {
		$form_action = $board === '' ? 'imgboard.php' : '../../imgboard.php';
	}

	$form_extra = '<input type="hidden" name="parent" value="' . $parent . '">';
	$input_extra = '';
	if (!$staff_post && defined('SCHAN_OVERBOARD') && $board === SCHAN_OVERBOARD && function_exists('overboardBoardRowHTML')) {
		$form_extra .= '<input type="hidden" name="from_overboard" value="1">';
		$input_extra = overboardBoardRowHTML();
	} else {
		$form_extra .= '<input type="hidden" name="board" value="' . htmlentities($board, ENT_QUOTES) . '">';
	}
	$rules_extra = '';

	$maxlen_name = -1;
	$maxlen_email = -1;
	$maxlen_subject = -1;
	$maxlen_message = -1;
	if (SCHAN_MAXNAME > 0) {
		$maxlen_name = SCHAN_MAXNAME;
	}
	if (SCHAN_MAXEMAIL > 0) {
		$maxlen_email = SCHAN_MAXEMAIL;
	}
	if (SCHAN_MAXSUBJECT > 0) {
		$maxlen_subject = SCHAN_MAXSUBJECT;
	}
	if (SCHAN_MAXMESSAGE > 0) {
		$maxlen_message = SCHAN_MAXMESSAGE;
	}
	if ($staff_post) {
		$txt_raw_html = __('Raw HTML');
		$txt_enable = __('Enable');
		$txt_raw_html_info_1 = __('Text entered in the Message field will be posted as is with no formatting applied.');
		$txt_raw_html_info_2 = __('Line-breaks must be specified with "&lt;br&gt;".');

		$txt_reply_to = __('Reply to');
		$staff_parent = intval($parent);
		$txt_new_thread = __('0 to start a new thread');

		$form_action = $board === '' ? '?' : installRootURL() . '/imgboard.php?';
		$form_extra = '<input type="hidden" name="staffpost" value="1">';
		$form_extra .= '<input type="hidden" name="board" value="' . htmlentities($board, ENT_QUOTES) . '">';
		$input_extra = <<<EOF
					<tr>
						<td class="postblock">
							$txt_raw_html
						</td>
						<td>
							<label>
								<input type="checkbox" name="raw" value="1" accesskey="r">&nbsp;$txt_enable<br>
							&nbsp; 	<small>$txt_raw_html_info_1</small><br>
							&nbsp; 	<small>$txt_raw_html_info_2</small>
							</label>
						</td>
					</tr>
					<tr>
						<td class="postblock">
							$txt_reply_to
						</td>
						<td>
							<input type="text" name="parent" size="28" maxlength="75" value="$staff_parent" accesskey="t">&nbsp;$txt_new_thread
						</td>
					</tr>
EOF;
		if (function_exists('roleIsFake') && roleIsFake(isset($GLOBALS['account']) ? $GLOBALS['account'] : array())) {
			$input_extra = preg_replace('#<tr>\s*<td class="postblock">\s*' . preg_quote($txt_raw_html, '#') . '.*?</tr>#s', '', $input_extra, 1);
			$input_extra .= roleFakeCapcodeRowsHTML();
		}

		$maxlen_name = -1;
		$maxlen_email = -1;
		$maxlen_subject = -1;
		$maxlen_message = -1;
	}

	$max_file_size_input_html = '';
	$max_file_size_rules_html = '';
	$reqmod_html = '';
	$filetypes_html = '';
	$file_input_html = '';
	$embed_input_html = '';
	$oekaki_input_html = '';
	$unique_posts_html = '';

	$captcha_setting = $parent == SCHAN_NEWTHREAD ? SCHAN_CAPTCHA : SCHAN_REPLYCAPTCHA;

	$captcha_html = '';
	if ($captcha_setting && !$staff_post) {
		if ($captcha_setting === 'hcaptcha') {
			$captcha_inner_html = '
<div style="min-height: 82px;">
	<div class="h-captcha" data-sitekey="' . SCHAN_HCAPTCHA_SITE . '"></div>
</div>';
		} else if ($captcha_setting === 'recaptcha') {
			$captcha_inner_html = '
<div style="min-height: 80px;">
	<div class="g-recaptcha" data-sitekey="' . SCHAN_RECAPTCHA_SITE . '"></div>
	<noscript>
		<div>
			<div style="width: 302px; height: 422px; position: relative;">
				<div style="width: 302px; height: 422px; position: absolute;">
					<iframe src="https://www.google.com/recaptcha/api/fallback?k=' . SCHAN_RECAPTCHA_SITE . '" frameborder="0" scrolling="no" style="width: 302px; height:422px; border-style: none;"></iframe>
				</div>
			</div>
			<div style="width: 300px; height: 60px; border-style: none;bottom: 12px; left: 25px; margin: 0px; padding: 0px; right: 25px;background: #f9f9f9; border: 1px solid #c1c1c1; border-radius: 3px;">
				<textarea id="g-recaptcha-response" name="g-recaptcha-response" class="g-recaptcha-response" style="width: 250px; height: 40px; border: 1px solid #c1c1c1; margin: 10px 25px; padding: 0px; resize: none;"></textarea>
			</div>
		</div>
	</noscript>
</div>';
		} else {
			$captcha_inner_html = '
<input type="text" name="captcha" id="captcha" size="6" accesskey="c" autocomplete="off">&nbsp;&nbsp;' . __('(enter the text below)') . '<br>
<img id="captchaimage" src="' . installRootURL() . '/inc/captcha.php" width="175" height="55" alt="CAPTCHA" onclick="javascript:reloadCAPTCHA()" style="margin-top: 5px;cursor: pointer;">';
		}

		$txt_captcha = __('CAPTCHA');
		$captcha_html = <<<EOF
					<tr>
						<td class="postblock">
							$txt_captcha
						</td>
						<td>
							$captcha_inner_html
						</td>
					</tr>
EOF;
	}

	if (!empty($schan_uploads) && ($staff_post || !in_array('file', $hide_fields))) {
		if (SCHAN_MAXKB > 0) {
			$max_file_size_input_html = '';
			$max_file_size_rules_html = '<li>' . sprintf(__('Maximum file size allowed is %s.'), SCHAN_MAXKBDESC) . '</li>';
		}

		$filetypes_html = '<li>' . supportedFileTypes() . '</li>';

		$txt_file = __('File');
		$maxfiles = defined('SCHAN_MAXFILES') ? max(1, intval(SCHAN_MAXFILES)) : 1;
		$file_inputs_html = '<input type="file" name="files[]"' . ($maxfiles > 1 ? ' multiple' : '') . ' size="35" accesskey="f">';
		if ($maxfiles > 1) {
			$filelist_js = <<<'JS'
(function(){
var s=document.currentScript,list=s.previousElementSibling,inp=list.previousElementSibling,max=__MAX__,dt,sps=[];
try{dt=new DataTransfer();}catch(e){return;}
function mk(t){return document.createElement(t);}
var btn=mk('input'),st=mk('span'),wrap=mk('span');
btn.type='button';btn.value='Browse...';btn.accessKey='f';
btn.onclick=function(){inp.click();};
wrap.appendChild(btn);wrap.appendChild(document.createTextNode(' '));wrap.appendChild(st);
inp.style.display='none';inp.parentNode.insertBefore(wrap,inp);
function upd(){var n=dt.files.length;st.textContent=n===0?'No file selected':(n===1?'1 file selected':n+' files selected');}
upd();
function shortName(n){var c=Array.from(n);return c.length>10?'...'+c.slice(-10).join(''):n;}
function mkFile(f,name){try{return new File([f],name,{type:f.type,lastModified:f.lastModified});}catch(e){alert('Your browser cannot rename files.');return f;}}
function setFiles(n){dt=n;inp.files=dt.files;render();}
function render(){
	upd();
	list.innerHTML='';
	for(var i=0;i<dt.files.length;i++){(function(i){
		var f=dt.files[i],row=mk('div'),x=mk('a'),box=mk('span'),nm=mk('span'),sp=mk('label'),spcb=mk('input'),ed=mk('a');
		row.style.cssText='display:flex;align-items:center;gap:8px;margin:3px 0;';
		x.href='#';x.textContent='X';x.title='Remove';x.style.cssText='text-decoration:none;font-weight:bold;';
		x.onclick=function(e){e.preventDefault();var n=new DataTransfer();for(var j=0;j<dt.files.length;j++){if(j!==i){n.items.add(dt.files[j]);}}sps.splice(i,1);setFiles(n);};
		box.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;overflow:hidden;font-size:11px;background:rgba(128,128,128,.25);';
		if(f.type.indexOf('image/')===0){var im=mk('img');im.style.cssText='max-width:48px;max-height:48px;';im.onload=function(){URL.revokeObjectURL(im.src);};im.src=URL.createObjectURL(f);box.appendChild(im);}
		else{box.textContent=(f.name.split('.').pop()||'file').toUpperCase().slice(0,5);}
		nm.textContent=shortName(f.name);nm.title=f.name;nm.style.wordBreak='break-all';
		spcb.type='checkbox';spcb.name='spoiler_files[]';spcb.value=String(i);spcb.style.marginRight='3px';spcb.checked=!!sps[i];
		spcb.onchange=function(){sps[i]=spcb.checked;};
		sp.appendChild(spcb);sp.appendChild(document.createTextNode(' Spoiler'));
		sp.style.cssText='white-space:nowrap;font-size:11px;';
		ed.href='#';ed.textContent='Edit';ed.title='Edit file name';ed.style.cssText='white-space:nowrap;font-size:11px;';
		ed.onclick=function(e){e.preventDefault();var nn=prompt('File name:',f.name);if(nn===null){return;}nn=nn.replace(/[\/\\]/g,'').trim();if(nn===''||nn===f.name){return;}var n=new DataTransfer();for(var j=0;j<dt.files.length;j++){n.items.add(j===i?mkFile(dt.files[j],nn):dt.files[j]);}setFiles(n);};
		row.appendChild(x);row.appendChild(box);row.appendChild(nm);row.appendChild(sp);row.appendChild(ed);list.appendChild(row);
	})(i);}
}
inp.addEventListener('change',function(){
	var n=new DataTransfer(),j,over=false;
	for(j=0;j<dt.files.length;j++){n.items.add(dt.files[j]);}
	for(j=0;j<inp.files.length;j++){if(n.files.length<max){n.items.add(inp.files[j]);sps.push(false);}else{over=true;}}
	if(over){alert('You can attach up to '+max+' files.');}
	setFiles(n);
});
if(inp.form){inp.form.addEventListener('reset',function(){dt=new DataTransfer();sps=[];list.innerHTML='';upd();});}
})();
JS;
			$file_inputs_html .= '<div class="filelist"></div>';
			$file_inputs_html .= '<script>' . str_replace('__MAX__', strval($maxfiles), $filelist_js) . '</script>';
			$file_inputs_html .= '<small>' . sprintf(__('You can attach up to %d files.'), $maxfiles) . '</small>';
		} else {
			$single_js = <<<'JS2'
(function(){
var s=document.currentScript,ed=s.previousElementSibling,inp=ed.parentNode.querySelector('input[type=file]');
var btn=document.createElement('input'),st=document.createElement('span'),wrap=document.createElement('span');
btn.type='button';btn.value='Browse...';btn.accessKey='f';btn.onclick=function(){inp.click();};
wrap.appendChild(btn);wrap.appendChild(document.createTextNode(' '));wrap.appendChild(st);
function upd(){st.textContent=(inp.files&&inp.files.length)?'1 file selected':'No file selected';}
inp.style.display='none';inp.parentNode.insertBefore(wrap,inp);upd();
inp.addEventListener('change',upd);
if(inp.form){inp.form.addEventListener('reset',function(){setTimeout(upd,0);});}
ed.onclick=function(e){
	e.preventDefault();
	if(!inp||!inp.files||!inp.files.length){alert('Choose a file first.');return;}
	var f=inp.files[0],nn=prompt('File name:',f.name);
	if(nn===null){return;}
	nn=nn.replace(/[\/\\]/g,'').trim();
	if(nn===''||nn===f.name){return;}
	try{var dt=new DataTransfer();dt.items.add(new File([f],nn,{type:f.type,lastModified:f.lastModified}));inp.files=dt.files;}
	catch(x){alert('Your browser cannot rename files.');}
};
})();
JS2;
			$file_inputs_html .= ' <label style="white-space:nowrap;"><input type="checkbox" name="spoiler_files[]" value="0"> Spoiler</label> <a href="#" title="Edit file name" style="white-space:nowrap;">Edit</a><script>' . $single_js . '</script>';
		}
		$file_input_html = <<<EOF
					<tr>
						<td class="postblock">
							$txt_file
						</td>
						<td>
							$file_inputs_html
						</td>
					</tr>
EOF;

		$oekaki_dir = dirname(__DIR__, 2) . '/js/wPaint-master/';
		if (isset($schan_uploads['image/png']) && is_file($oekaki_dir . 'wPaint.min.js') && ($staff_post || !in_array('oekaki', $hide_fields))) {
			$oekaki_body = <<<'OEKAKIHTML'
<a href="#" class="oek-toggle" style="text-decoration:none;">&#9656; %%DRAW%%</a>
<div class="oek-box" style="display:none;margin-top:6px;">
	<div class="oek-setup">
		%%SIZE%%: <input type="number" class="oek-w" value="400" min="50" max="800" style="width:60px;"> &times; <input type="number" class="oek-h" value="300" min="50" max="800" style="width:60px;">
		<input type="button" class="oek-start" value="%%START%%">
	</div>
	<div class="oek-tools" style="display:none;">
		<div class="oek-wpaint" style="position:relative;margin-top:56px;border:1px solid #000;background:#fff;"></div>
		<div style="margin-top:6px;"><input type="button" class="oek-attach" value="%%ATTACH%%"> <input type="button" class="oek-clear" value="%%CLEAR%%"></div>
	</div>
	<div class="oek-status" style="font-size:smaller;margin-top:4px;"></div>
</div>
<script>
(function(){
var root=document.currentScript.parentNode,base=__BASE__;
var toggle=root.querySelector('.oek-toggle'),box=root.querySelector('.oek-box'),wEl=root.querySelector('.oek-wpaint'),
	wIn=root.querySelector('.oek-w'),hIn=root.querySelector('.oek-h'),startBtn=root.querySelector('.oek-start'),
	setup=root.querySelector('.oek-setup'),tools=root.querySelector('.oek-tools'),attachBtn=root.querySelector('.oek-attach'),
	clearBtn=root.querySelector('.oek-clear'),status=root.querySelector('.oek-status');
var form=root;while(form&&form.nodeName!=='FORM'){form=form.parentNode;}
var started=false;
function say(t){status.textContent=t;}
function clamp(v,a,b){v=parseInt(v,10);if(isNaN(v)){v=400;}return Math.max(a,Math.min(b,v));}
function loadScript(src,done){var s=document.createElement('script');s.src=src;s.onload=function(){done(true);};s.onerror=function(){done(false);};document.head.appendChild(s);}
function loadCss(href){var l=document.createElement('link');l.rel='stylesheet';l.href=href;document.head.appendChild(l);}
function loadAll(cb){
	if(window.schanWPaint){cb(window.schanWPaint);return;}
	if(window.schanWPaintQueue){window.schanWPaintQueue.push(cb);return;}
	window.schanWPaintQueue=[cb];
	loadCss(base+'lib/wColorPicker.min.css');loadCss(base+'wPaint.min.css');
	var list=['lib/jquery.1.10.2.min.js','lib/jquery.ui.core.1.10.3.min.js','lib/jquery.ui.widget.1.10.3.min.js','lib/jquery.ui.mouse.1.10.3.min.js','lib/jquery.ui.draggable.1.10.3.min.js','lib/wColorPicker.min.js','wPaint.min.js','plugins/main/wPaint.menu.main.min.js','plugins/text/wPaint.menu.text.min.js','plugins/shapes/wPaint.menu.main.shapes.min.js'];
	var usedOwn=false;
	function finish(){
		var jq=window.jQuery;
		if(usedOwn&&jq&&jq.noConflict){jq=jq.noConflict(true);}
		var q=window.schanWPaintQueue;window.schanWPaintQueue=null;
		window.schanWPaint=(jq&&jq.fn&&jq.fn.wPaint)?jq:null;
		for(var i=0;i<q.length;i++){q[i](window.schanWPaint);}
		if(!window.schanWPaint){window.schanWPaint=undefined;}
	}
	function step(i){
		if(i>=list.length){finish();return;}
		loadScript(base+list[i],function(ok){if(i===0&&ok){usedOwn=true;}step(i+1);});
	}
	step(0);
}
function toFile(u,name){
	var p=u.split(','),mime=(p[0].match(/:(.*?);/)||[])[1]||'image/png',bin=atob(p[1]),n=bin.length,a=new Uint8Array(n);
	while(n--){a[n]=bin.charCodeAt(n);}
	return new File([a],name,{type:mime});
}
toggle.onclick=function(e){
	e.preventDefault();
	var open=box.style.display==='none';
	box.style.display=open?'block':'none';
	toggle.innerHTML=(open?'&#9662; ':'&#9656; ')+toggle.textContent.replace(/^\s*\S\s*/,'');
};
startBtn.onclick=function(){
	var w=clamp(wIn.value,50,800),h=clamp(hIn.value,50,800);
	say('Loading...');
	loadAll(function($j){
		if(!$j){say('Could not load wPaint. Check that js/wPaint-master is uploaded.');return;}
		wEl.style.width=w+'px';wEl.style.height=h+'px';
		tools.style.display='block';setup.style.display='none';
		try{$j(wEl).wPaint({path:base,bg:'#ffffff',menuOffsetLeft:0,menuOffsetTop:-50});started=true;say('');}
		catch(err){tools.style.display='none';setup.style.display='block';say('wPaint failed to start.');}
	});
};
clearBtn.onclick=function(){
	if(!started||!window.schanWPaint){return;}
	try{window.schanWPaint(wEl).wPaint('clear');}catch(e){}
};
attachBtn.onclick=function(){
	if(!started||!window.schanWPaint){return;}
	var data;
	try{data=window.schanWPaint(wEl).wPaint('image');}catch(e){data=null;}
	if(typeof data!=='string'||data.length<100){say('Nothing to attach yet.');return;}
	if(data.indexOf('data:')!==0){data='data:image/png;base64,'+data;}
	var inp=form?form.querySelector('input[type=file]'):null;
	if(!inp){say('This form has no file field.');return;}
	try{
		var file=toFile(data,'oekaki-'+Date.now()+'.png'),d=new DataTransfer();
		d.items.add(file);inp.files=d.files;
		inp.dispatchEvent(new Event('change',{bubbles:true}));
		var ok=false;for(var i=0;i<inp.files.length;i++){if(inp.files[i].name===file.name){ok=true;}}
		say(ok?('Attached '+file.name+' to your post.'):'Could not attach it (file limit reached?).');
	}catch(err){say('Your browser cannot attach the drawing automatically.');}
};
})();
</script>
OEKAKIHTML;
			$oekaki_body = str_replace(
				array('__BASE__', '%%DRAW%%', '%%SIZE%%', '%%START%%', '%%ATTACH%%', '%%CLEAR%%'),
				array(json_encode(installRootURL() . '/js/wPaint-master/'), __('Draw an image'), __('Canvas size'), __('Start'), __('Attach to post'), __('Clear')),
				$oekaki_body
			);
			// extras row panel, tab replaces the draw toggle so start it open
			$oekaki_input_html = str_replace(
				array('class="oek-toggle" style="text-decoration:none;"', 'class="oek-box" style="display:none;margin-top:6px;"'),
				array('class="oek-toggle" style="display:none;"', 'class="oek-box" style="margin-top:6px;"'),
				$oekaki_body
			);
		}
	}

	$embeds_enabled = (!empty($schan_embeds) || SCHAN_UPLOADVIAURL) && ($staff_post || !in_array('embed', $hide_fields));
	if ($embeds_enabled) {
		$txt_embed = __('Embed');
		$txt_embed_help = '';
		if ($embeds_enabled) {
			$txt_embed_help = __('(paste a YouTube URL)');
		}
		// extras row panel
		$embed_input_html = '<input type="text" name="embed" size="28" accesskey="x" autocomplete="off">&nbsp;&nbsp;' . $txt_embed_help;
	}

	if (SCHAN_REQMOD == 'all') {
		$reqmod_html = '<li>' . __('All posts are moderated before being shown.') . '</li>';
	} else if (SCHAN_REQMOD == 'files') {
		$reqmod_html = '<li>' . __('All posts with a file attached are moderated before being shown.') . '</li>';
	}

	$thumbnails_html = '';
	if (isset($schan_uploads['image/jpeg']) || isset($schan_uploads['image/pjpeg']) || isset($schan_uploads['image/png']) || isset($schan_uploads['image/gif']) || isset($schan_uploads['image/webp']) || isset($schan_uploads['image/svg+xml'])) {
		$maxdimensions = SCHAN_MAXWOP . 'x' . SCHAN_MAXHOP;
		if (SCHAN_MAXW != SCHAN_MAXWOP || SCHAN_MAXH != SCHAN_MAXHOP) {
			$maxdimensions .= ' (new thread) or ' . SCHAN_MAXW . 'x' . SCHAN_MAXH . ' (reply)';
		}

		$thumbnails_html = '<li>' . sprintf(__('Images greater than %s will be thumbnailed.'), $maxdimensions) . '</li>';
	}

	$unique_posts = uniquePosts();
	if ($unique_posts > 0) {
		$unique_posts_html = '<li>' . sprintf(__('Currently %s unique user posts.'), $unique_posts) . '</li>' . "\n";
	}

	// meme flags go next to sage, or on their own row if there's no sage
	$meme_row = '';
	$meme_inline = '';
	$meme_used = false;
	if (function_exists('memeFlagsFormRow') && ($staff_post || !in_array('memeflag', $hide_fields))) {
		$meme_row = memeFlagsFormRow();
		if (preg_match_all('#<td\b#i', $meme_row) == 2 && preg_match('#^\s*<tr[^>]*>\s*<td\b[^>]*>.*?</td>\s*<td\b[^>]*>(.*)</td>\s*</tr>\s*$#is', $meme_row, $meme_m)) {
			$meme_inline = '&nbsp;&nbsp;<span style="display:inline-block;vertical-align:middle;">' . $meme_m[1] . '</span>';
		}
	}

	$output = <<<EOF
		<div class="postarea">
			<form name="postform" id="postform" action="$form_action" method="post" enctype="multipart/form-data">
			$max_file_size_input_html
			$form_extra
			<table class="postform">
				<tbody>
					$input_extra
EOF;
	if ($staff_post || !in_array('name', $hide_fields)) {
		$txt_name = __('Name');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_name
						</td>
						<td>
							<input type="text" name="name" size="28" maxlength="{$maxlen_name}" accesskey="n">
							{$postform_extra['name']}
						</td>
					</tr>
EOF;
	}
	if ($staff_post || !in_array('email', $hide_fields)) {
		$txt_email = __('E-mail');
		$sage_html = '';
		if (!$staff_post && !in_array('sage', $hide_fields)) {
			$sage_html = '&nbsp;&nbsp;<label style="white-space:nowrap;"><input type="checkbox" id="sagecheckbox" onclick="return toggleSage(this);">&nbsp;' . __('Sage') . '</label>';
			if ($meme_inline !== '') {
				$sage_html .= $meme_inline;
				$meme_used = true;
			}
		}
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_email
						</td>
						<td>
							<input type="text" name="email" id="emailfield" size="28" maxlength="{$maxlen_email}" accesskey="e">
							{$postform_extra['email']}
							$sage_html
						</td>
					</tr>
EOF;
	}
	if ($staff_post || !in_array('subject', $hide_fields)) {
		$txt_subject = __('Subject');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_subject
						</td>
						<td>
							<input type="text" name="subject" size="40" maxlength="{$maxlen_subject}" accesskey="s" autocomplete="off">
							{$postform_extra['subject']}
						</td>
					</tr>
EOF;
	}
	if ($staff_post || !in_array('message', $hide_fields)) {
		$txt_message = __('Message');
		$emoji_picker_html = (function_exists('emojiPicker') && !in_array('emoji', $hide_fields)) ? emojiPicker($parent) : '';
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_message
						</td>
						<td>
							<textarea id="message" name="message" cols="48" rows="4" maxlength="{$maxlen_message}" accesskey="m"></textarea>
							$emoji_picker_html
						</td>
					</tr>
EOF;
	}

	$poll_panel_html = '';
	if (function_exists('pollsEnabled') && pollsEnabled() && ($staff_post || !in_array('poll', $hide_fields))) {
		$poll_row = pollFormRow();
		if (preg_match_all('#<td\b#i', $poll_row) == 2 && preg_match('#^\s*<tr[^>]*>\s*<td\b[^>]*>.*?</td>\s*<td\b[^>]*>(.*)</td>\s*</tr>\s*$#is', $poll_row, $poll_m)) {
			$poll_panel_html = $poll_m[1];
		} else {
			$poll_panel_html = '<table><tbody>' . $poll_row . '</tbody></table>';
		}
	}
	if (!$meme_used && $meme_row !== '') {
		$output .= $meme_row;
	}

	$output .= <<<EOF
					$file_input_html
EOF;
	$output .= postFormExtrasRow(array(
		'poll'   => array(__('Poll'), $poll_panel_html),
		'oekaki' => array(__('Oekaki'), $oekaki_input_html),
		'embed'  => array(__('Embed'), $embed_input_html),
	));
	if ($staff_post || !in_array('password', $hide_fields)) {
		$txt_password = __('Password');
		$txt_password_help = __('(for post and file deletion)');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_password
						</td>
						<td>
							<input type="password" name="password" id="newpostpassword" size="8" accesskey="p">&nbsp;&nbsp;$txt_password_help
						</td>
					</tr>
EOF;
	}
	// captcha goes last
	$output .= $captcha_html;
	if ($postform_extra['footer'] != '') {
		$output .= <<<EOF
					<tr>
						<td>
							&nbsp;
						</td>
						<td>
							{$postform_extra['footer']}
						</td>
					</tr>
EOF;
	}
	$output .= <<<EOF
					<tr>
						<td colspan="2" class="rules">
							$rules_extra
							<ul>
								$reqmod_html
								$filetypes_html
								$max_file_size_rules_html
								$thumbnails_html
								$unique_posts_html
							</ul>
						</td>
					</tr>
				</tbody>
			</table>
			</form>
		</div>
EOF;

	return $output;
}
