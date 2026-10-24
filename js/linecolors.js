// Line colours: > green, < orange, ^ blue. Append to js/schan.js. Colours live in css/global.css.
(function(){
	function lineClass(t){
		if (/^\s*</.test(t)) { return 'orangetext'; }
		if (/^\s*\^/.test(t)) { return 'bluetext'; }
		if (/^\s*>(?!>)/.test(t)) { return 'greentext'; }
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
	function one(msg){
		if (msg.getAttribute('data-lc')) { return; }
		msg.setAttribute('data-lc', '1');
		var nodes = Array.prototype.slice.call(msg.childNodes), line = [], i;
		for (i = 0; i < nodes.length; i++) {
			if (nodes[i].nodeName === 'BR') { flush(msg, line); line = []; } else { line.push(nodes[i]); }
		}
		flush(msg, line);
	}
	function all(){
		var m = document.querySelectorAll('.message');
		for (var i = 0; i < m.length; i++) { one(m[i]); }
	}
	function init(){
		all();
		var box = document.getElementById('posts');
		if (box && window.MutationObserver) { new MutationObserver(all).observe(box, { childList: true, subtree: true }); }
	}
	document.addEventListener('schan:updated', all);
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
