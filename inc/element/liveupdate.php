<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// live updating for threads / index / catalog, html.php loads it

function liveUpdateDelay() {
	if (!defined('SCHAN_LIVEUPDATE')) {
		return 10;
	}
	$n = intval(SCHAN_LIVEUPDATE);
	return $n <= 0 ? 0 : max(3, $n);
}

function liveUpdateJS() {
	return <<<'JS'
<script type="text/javascript">
(function(){
if(!window.fetch||!window.DOMParser||typeof liveUpdateDelay==='undefined'||!(liveUpdateDelay>0)){return;}
var box=null,timer=null,busy=false,stopped=false,unread=0,baseTitle='',lastAdded=0;
var isThread=/\/res\/\d+\.html$/.test(location.pathname);
var isCatalog=/catalog\.html$/.test(location.pathname);

function setTitle(){document.title=unread>0?'('+unread+') '+baseTitle:baseTitle;}
function nodeId(n){
	if(!n||n.nodeType!==1){return null;}
	if(n.nodeName==='DIV'&&n.classList.contains('op')){return n.id;}
	var td=n.querySelector?n.querySelector('td.reply[id^="post"]'):null;
	return td?td.id:null;
}
function anchorStart(){
	if((window.pageYOffset||0)<=5){return null;}
	for(var n=box.firstElementChild;n;n=n.nextElementSibling){
		var r=n.getBoundingClientRect();
		if(r.bottom>0){return {el:n,top:r.top};}
	}
	return null;
}
function anchorEnd(a){
	if(!a||!document.body.contains(a.el)){return;}
	var d=a.el.getBoundingClientRect().top-a.top;
	if(d){window.scrollBy(0,d);}
}

function updateThread(src){
	var have={},want={},add=[],n,id,changed=false,i;
	for(n=box.firstElementChild;n;n=n.nextElementSibling){id=nodeId(n);if(id){have[id]=n;}}
	for(n=src.firstElementChild;n;n=n.nextElementSibling){
		id=nodeId(n);
		if(!id){continue;}
		want[id]=n;
		if(!have[id]){add.push(n);}
	}
	for(id in have){
		if(have.hasOwnProperty(id)&&!want[id]){box.removeChild(have[id]);changed=true;}
	}
	lastAdded=add.length;
	for(i=0;i<add.length;i++){box.appendChild(document.importNode(add[i],true));changed=true;}
	for(n=box.firstElementChild;n;n=n.nextElementSibling){if(n.classList&&n.classList.contains('threadstats')){box.appendChild(n);break;}}
	var nb=src.querySelectorAll('span.backlinks');
	for(i=0;i<nb.length;i++){
		var cb=nb[i].id?document.getElementById(nb[i].id):null;
		if(cb&&cb.innerHTML!==nb[i].innerHTML){cb.innerHTML=nb[i].innerHTML;changed=true;}
	}
	return changed;
}

// index updates in place so enlarged videos/flash keep playing
// posts with open media never get swapped, only their backlinks refresh
function msgLen(n){
	var m=n.querySelector?n.querySelector('.message'):null;
	return m?(m.textContent||'').length:0;
}
// change detection only looks at the message text, otherwise quote buttons /
// 5ch numbers / backlinks added by scripts would look like edits
function topKey(n){
	var id=nodeId(n);
	return id?(id+':'+msgLen(n)):((n.className||n.nodeName)+':'+(n.textContent||'').length);
}
function hasOpenMedia(n){
	return !!(n.querySelector&&n.querySelector('[data-closable="1"]'));
}
function syncBacklinks(src){
	var nb=src.querySelectorAll('span.backlinks'),i,ch=false;
	for(i=0;i<nb.length;i++){
		var cb=nb[i].id?document.getElementById(nb[i].id):null;
		if(cb&&cb.innerHTML!==nb[i].innerHTML){cb.innerHTML=nb[i].innerHTML;ch=true;}
	}
	return ch;
}
function updateIndex(src){
	var olds=[],news=[],n,i,pool={};
	for(n=box.firstElementChild;n;n=n.nextElementSibling){olds.push(n);}
	for(n=src.firstElementChild;n;n=n.nextElementSibling){news.push(n);}
	function poolKey(el){return nodeId(el)||('~'+el.nodeName+(el.className||''));}
	for(i=0;i<olds.length;i++){
		var pk=poolKey(olds[i]);
		(pool[pk]=pool[pk]||[]).push(olds[i]);
	}
	var want=[],used=[],differs=olds.length!==news.length;
	for(i=0;i<news.length;i++){
		var nn=news[i],id=nodeId(nn),list=pool[poolKey(nn)],old=(list&&list.length)?list.shift():null,el=null;
		if(old){
			if(topKey(old)===topKey(nn)){el=old;}
			else if(id&&hasOpenMedia(old)){el=old;}
		}
		if(el){used.push(el);}else{el=document.importNode(nn,true);differs=true;}
		want.push(el);
	}
	for(i=0;i<olds.length;i++){
		if(used.indexOf(olds[i])<0){box.removeChild(olds[i]);differs=true;}
	}
	var a=differs?anchorStart():null,cur=box.firstElementChild;
	for(i=0;i<want.length;i++){
		if(want[i]===cur){cur=cur.nextElementSibling;}
		else{box.insertBefore(want[i],cur);differs=true;}
	}
	var bl=syncBacklinks(src);
	if(a){anchorEnd(a);}
	return differs||bl;
}

function catalogSig(root){
	var a=root.querySelectorAll('.catalogpost'),s=[];
	for(var i=0;i<a.length;i++){
		var l=a[i].querySelector('a');
		s.push((l?l.getAttribute('href'):'')+':'+(a[i].textContent||'').replace(/\s+/g,' ').trim());
	}
	return s.join('|');
}
function updateCatalog(src){
	if(catalogSig(src)===catalogSig(box)){return false;}
	box.innerHTML=src.innerHTML;
	return true;
}

function syncNav(doc){
	var a=document.querySelectorAll('body > table[border="1"]'),b=doc.querySelectorAll('body > table[border="1"]');
	if(!a.length||a.length!==b.length){return;}
	for(var i=0;i<a.length;i++){
		if(a[i].outerHTML!==b[i].outerHTML){a[i].outerHTML=b[i].outerHTML;}
	}
}

function after(){
	if(window.pollInit){try{pollInit();}catch(e){}}
	if(window.schan5chRender){try{window.schan5chRender();}catch(e){}}
	try{document.dispatchEvent(new CustomEvent('schan:updated'));}catch(e){}
}
function schedule(){
	if(stopped){return;}
	clearTimeout(timer);
	timer=setTimeout(tick,liveUpdateDelay*1000);
}
function tick(){
	if(stopped){return;}
	if(busy||(document.hidden&&!isThread)){schedule();return;}
	busy=true;
	fetch(location.pathname+location.search,{cache:'no-cache',credentials:'same-origin'}).then(function(r){
		if(r.status===404){stopped=true;throw new Error('gone');}
		if(!r.ok){throw new Error('http');}
		return r.text();
	}).then(function(t){
		var doc=new DOMParser().parseFromString(t,'text/html'),src=doc.getElementById('posts');
		if(!src){return;}
		lastAdded=0;
		var changed=isThread?updateThread(src):(isCatalog||box.querySelector('.catalogpost')?updateCatalog(src):updateIndex(src));
		if(isThread&&document.hidden&&lastAdded>0){unread+=lastAdded;setTitle();}
		syncNav(doc);
		if(changed){after();}
	}).catch(function(){}).then(function(){busy=false;schedule();});
}
function init(){
	box=document.getElementById('posts');
	if(!box){return;}
	baseTitle=document.title.replace(/^\(\d+\)\s*/,'');
	document.addEventListener('visibilitychange',function(){
		if(!document.hidden){unread=0;setTitle();}
		if(!document.hidden&&!stopped&&!busy){clearTimeout(timer);tick();}
	});
	schedule();
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();
</script>
JS;
}
