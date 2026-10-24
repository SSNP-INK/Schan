<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

// post form pieces, loaded by html.php

// Extras row for poll / oekaki / embed, each is a link that opens its panel (one at a time)
// $items: key => array(label, panel html), empty panels get skipped
function postFormExtrasRow($items) {
	$tabs = '';
	$panels = '';
	foreach ($items as $key => $item) {
		if (trim($item[1]) === '') {
			continue;
		}
		$tabs .= '<button type="button" data-xtra-tab="' . $key . '">' . htmlentities($item[0]) . '</button> ';
		$panels .= '<div data-xtra-panel="' . $key . '" style="display:none;margin-top:6px;">' . $item[1] . '</div>';
	}
	if ($tabs === '') {
		return '';
	}
	$js = <<<'JS'
<script type="text/javascript">
(function(){
	var root=document.currentScript.parentNode,tabs=root.querySelectorAll('[data-xtra-tab]'),panels=root.querySelectorAll('[data-xtra-panel]'),cur='';
	function show(name){
		cur=name;
		for(var i=0;i<panels.length;i++){panels[i].style.display=(panels[i].getAttribute('data-xtra-panel')===name)?'block':'none';}
		for(var j=0;j<tabs.length;j++){tabs[j].style.fontWeight=(tabs[j].getAttribute('data-xtra-tab')===name)?'bold':'normal';}
	}
	for(var k=0;k<tabs.length;k++){
		tabs[k].onclick=function(e){e.preventDefault();var n=this.getAttribute('data-xtra-tab');show(cur===n?'':n);};
	}
})();
</script>
JS;
	return '<tr><td class="postblock">' . __('Extras') . '</td><td>' . $tabs . $panels . $js . '</td></tr>';
}
