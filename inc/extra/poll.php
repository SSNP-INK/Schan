<?php
if (!defined('SCHAN_BOARD')) {
	die('');
}

/*
 * Polls: 2-8 options, one vote per IP address.
 *
 * Optional setting in settings.php (polls are ON if it isn't defined):
 *   define('SCHAN_POLLS', false);   // turn polls off
 *
 * Each poll is stored in <board>/polls/<post id>.php. Polls left over in the old root polls/
 * folder are moved into their board's folder automatically the first time they are loaded
 * (or when the board is rebuilt). The file starts with exit so it can't be read over the web. Voters are stored as salted hashes, not raw IPs.
 * Poll counts are never written into the static pages; the Vote / Results buttons load
 * them live from imgboard.php?vote and imgboard.php?pollresults=<id>.
 */

if (!defined('SCHAN_POLLMAXOPTIONS')) {
	define('SCHAN_POLLMAXOPTIONS', 8);
}
if (!defined('SCHAN_POLLMAXLENGTH')) {
	define('SCHAN_POLLMAXLENGTH', 60);
}

function pollsEnabled() {
	return (!defined('SCHAN_POLLS') || SCHAN_POLLS) && (!function_exists('extensionEnabled') || extensionEnabled('polls'));
}

function pollBoardSlug($board = null) {
	if ($board === null || $board === '') {
		$board = function_exists('current_board') ? current_board() : '';
	}
	return (is_string($board) && preg_match('/^[a-z0-9_-]{1,64}$/', $board)) ? $board : '';
}

function pollDir($board = null) {
	$board = pollBoardSlug($board);
	return dirname(dirname(__FILE__)) . '/' . ($board !== '' ? $board . '/' : '') . 'polls';
}

// Where polls used to live (single folder in the install root)
function pollLegacyPath($id) {
	return dirname(dirname(__FILE__)) . '/polls/' . intval($id) . '.php';
}

function pollPath($id, $board = null) {
	return pollDir($board) . '/' . intval($id) . '.php';
}

// Path of an existing poll file. A poll still in the old root folder is moved into the board's
// own folder, but only if the post really belongs to that board.
function pollLocate($id, $board = null) {
	$board = pollBoardSlug($board);
	$path = pollPath($id, $board);
	if ($board === '' || is_file($path)) {
		return $path;
	}
	$legacy = pollLegacyPath($id);
	if (!is_file($legacy)) {
		return $path;
	}
	$post = function_exists('postByID') ? postByID(intval($id)) : null;
	if (empty($post) || (isset($post['board']) && $post['board'] !== '' && $post['board'] !== $board)) {
		return $legacy;
	}
	if (!is_dir(pollDir($board))) {
		@mkdir(pollDir($board), 0755, true);
	}
	return @rename($legacy, $path) ? $path : $legacy;
}

function pollParse($raw) {
	$nl = strpos($raw, "\n");
	if ($nl === false) {
		return null;
	}
	$poll = json_decode(substr($raw, $nl + 1), true);
	return (is_array($poll) && isset($poll['options']) && isset($poll['votes'])) ? $poll : null;
}

// Returns the poll array for a post, or null
function pollGet($id, $board = null) {
	static $cache = array();
	$id = intval($id);
	$board = pollBoardSlug($board);
	$ck = $board . ':' . $id;
	if (array_key_exists($ck, $cache)) {
		return $cache[$ck];
	}

	$cache[$ck] = null;
	$path = pollLocate($id, $board);
	if (is_readable($path)) {
		$raw = @file_get_contents($path);
		if ($raw !== false && $raw !== '') {
			$cache[$ck] = pollParse($raw);
		}
	}
	return $cache[$ck];
}

function pollWriteHandle($fh, $poll) {
	ftruncate($fh, 0);
	rewind($fh);
	fwrite($fh, "<?php exit; ?>\n" . json_encode($poll));
	fflush($fh);
}

// Cleans the submitted option list. Returns array of escaped option strings.
function pollCleanOptions($raw) {
	$options = array();
	$seen = array();
	if (!is_array($raw)) {
		return $options;
	}
	foreach ($raw as $o) {
		if (!is_string($o)) {
			continue;
		}
		$o = trim($o);
		if ($o === '') {
			continue;
		}
		$o = _substr($o, 0, SCHAN_POLLMAXLENGTH);
		$key = function_exists('mb_strtolower') ? mb_strtolower($o, 'UTF-8') : strtolower($o);
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key] = true;
		$options[] = cleanString($o);
		if (count($options) >= SCHAN_POLLMAXOPTIONS) {
			break;
		}
	}
	return $options;
}

function pollCreate($id, $options, $board = null) {
	$board = pollBoardSlug($board);
	if ($board === '') {
		return false;
	}
	if (!is_dir(pollDir($board))) {
		@mkdir(pollDir($board), 0755, true);
	}
	$fh = @fopen(pollPath($id, $board), 'c+');
	if (!$fh) {
		return false;
	}
	flock($fh, LOCK_EX);
	pollWriteHandle($fh, array(
		'options' => array_values($options),
		'votes' => array_fill(0, count($options), 0),
		'voters' => new stdClass(),
		'created' => time(),
	));
	flock($fh, LOCK_UN);
	fclose($fh);
	return true;
}

function pollDelete($id, $board = null) {
	foreach (array(pollPath($id, $board), pollLegacyPath($id)) as $path) {
		if (is_file($path)) {
			@unlink($path);
		}
	}
}

function pollVoterKey() {
	return substr(hash('sha256', SCHAN_TRIPSEED . '|poll|' . remoteAddress()), 0, 40);
}

// Public view of a poll: options, counts, and which option this IP voted for (-1 if none)
function pollResultsData($id, $poll = null) {
	if ($poll === null) {
		$poll = pollGet($id);
	}
	if (empty($poll)) {
		return array('error' => __('Poll not found.'));
	}

	$voters = isset($poll['voters']) ? (array)$poll['voters'] : array();
	$key = pollVoterKey();
	$voted = isset($voters[$key]) ? intval($voters[$key]) : -1;

	return array(
		'id' => intval($id),
		'options' => $poll['options'],
		'votes' => array_map('intval', $poll['votes']),
		'total' => array_sum($poll['votes']),
		'voted' => $voted,
	);
}

// Handles POST imgboard.php?vote  (fields: poll, option)
function pollHandleVote() {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		return array('error' => __('Invalid request.'));
	}

	$id = isset($_POST['poll']) ? intval($_POST['poll']) : 0;
	$opt = isset($_POST['option']) && is_numeric($_POST['option']) ? intval($_POST['option']) : -1;
	if ($id <= 0) {
		return array('error' => __('Poll not found.'));
	}

	$post = postByID($id);
	if (empty($post) || $post['moderated'] == 0) {
		return array('error' => __('Poll not found.'));
	}

	$board = pollBoardSlug(isset($post['board']) ? $post['board'] : null);
	$path = pollLocate($id, $board);
	if (!is_readable($path)) {
		return array('error' => __('Poll not found.'));
	}

	$fh = @fopen($path, 'c+');
	if (!$fh) {
		return array('error' => __('Could not record your vote.'));
	}
	flock($fh, LOCK_EX);

	$raw = stream_get_contents($fh);
	$poll = ($raw !== false && $raw !== '') ? pollParse($raw) : null;
	if (empty($poll)) {
		flock($fh, LOCK_UN);
		fclose($fh);
		return array('error' => __('Poll not found.'));
	}

	$voters = isset($poll['voters']) ? (array)$poll['voters'] : array();
	$key = pollVoterKey();
	$error = '';

	if (isset($voters[$key])) {
		$error = __('You have already voted in this poll.');
	} else if ($opt < 0 || $opt >= count($poll['options'])) {
		$error = __('Choose an option first.');
	} else {
		$poll['votes'][$opt] = intval($poll['votes'][$opt]) + 1;
		$voters[$key] = $opt;
		$poll['voters'] = $voters;
		pollWriteHandle($fh, $poll);
	}

	flock($fh, LOCK_UN);
	fclose($fh);

	$result = pollResultsData($id, $poll);
	if ($error !== '') {
		$result['error'] = $error;
	}
	return $result;
}

// The poll as shown inside a post. Left-aligned so it sits next to a floated thumbnail.
function pollHTML($post) {
	if (!pollsEnabled() || !isset($post['id'])) {
		return '';
	}
	$poll = pollGet($post['id'], isset($post['board']) ? $post['board'] : null);
	if (empty($poll)) {
		return '';
	}

	$id = intval($post['id']);
	$html = '<div class="ibpoll" id="poll' . $id . '" data-poll="' . $id . '"' . (isset($post['board']) && $post['board'] !== '' ? ' data-board="' . htmlentities($post['board'], ENT_QUOTES) . '"' : '') . ' style="text-align:left;margin:6px 0;padding:0;background:transparent !important;border:0 !important;box-shadow:none !important;">';
	$html .= '<div class="ibpoll-options" id="polloptions' . $id . '" style="background:transparent !important;border:0 !important;padding:0;">';
	foreach ($poll['options'] as $i => $option) {
		$html .= '<label style="display:inline-block;margin:0 12px 2px 0;white-space:nowrap;"><input type="radio" name="pollopt' . $id . '" value="' . intval($i) . '"> ' . $option . '<span class="pollcount" id="pollcount' . $id . '_' . intval($i) . '"></span></label>';
	}
	$html .= '</div>';
	$html .= '<div class="ibpoll-buttons" id="pollbuttons' . $id . '" style="margin-top:4px;background:transparent !important;border:0 !important;padding:0;">';
	$html .= '<input type="button" value="' . __('Vote') . '" onclick="pollVote(' . $id . ');"> ';
	$html .= '<input type="button" value="' . __('Results') . '" onclick="pollResults(' . $id . ');">';
	$html .= '</div>';
	$html .= '</div>';
	return $html;
}

// Poll fields in the posting form
function pollFormRow() {
	$txt_poll = __('Poll');
	$txt_add = __('Add a poll');
	$txt_option = __('Option');
	$txt_more = __('+ Add option');
	$txt_help = sprintf(__('(%d-%d options, one vote per IP)'), 2, SCHAN_POLLMAXOPTIONS);
	$max = intval(SCHAN_POLLMAXLENGTH);

	$inputs = '';
	for ($i = 1; $i <= 2; $i++) {
		$inputs .= '<input type="text" name="poll[]" size="28" maxlength="' . $max . '" placeholder="' . $txt_option . ' ' . $i . '" autocomplete="off"><br>';
	}

	return <<<EOF
					<tr>
						<td class="postblock">
							$txt_poll
						</td>
						<td>
							<details class="pollform">
								<summary style="cursor:pointer;">$txt_add</summary>
								<div id="pollopts">$inputs</div>
								<a href="#" onclick="return pollAddOption();">$txt_more</a> <small>$txt_help</small>
							</details>
						</td>
					</tr>
EOF;
}

// Browser-side code for the poll form and the Vote / Results buttons (included in the page header)
function pollJS() {
	return <<<'HTML'
<script type="text/javascript">
function pollAddOption(){
	var box=document.getElementById("pollopts");
	if(!box){return false;}
	var n=box.getElementsByTagName("input").length;
	if(n>=8){return false;}
	var i=document.createElement("input");
	i.type="text";i.name="poll[]";i.size=28;i.maxLength=60;i.autocomplete="off";
	i.placeholder="Option "+(n+1);
	box.appendChild(i);
	box.appendChild(document.createElement("br"));
	return false;
}
function pollBoard(){return (typeof currentBoard==="string")?currentBoard:"";}
function pollBoardFor(id){var e=document.getElementById("poll"+id),b=e?e.getAttribute("data-board"):null;return b?b:pollBoard();}
function pollURL(q,b){
	var root=(typeof schanRoot==="string")?schanRoot:"";
	return root+"/imgboard.php?"+q+"&board="+encodeURIComponent(b!==undefined&&b!==null?b:pollBoard());
}
function pollRequest(url,body,cb){
	var x=new XMLHttpRequest();
	x.open(body?"POST":"GET",url,true);
	if(body){x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");}
	x.onreadystatechange=function(){
		if(x.readyState!==4){return;}
		var r=null;
		try{r=JSON.parse(x.responseText);}catch(e){}
		cb(r);
	};
	x.send(body||null);
}
/* Writes "= count" beside each option. Once this IP has voted, the options go grey and locked. */
function pollApply(id,r){
	if(!r||!r.options){return;}
	for(var i=0;i<r.options.length;i++){
		var c=document.getElementById("pollcount"+id+"_"+i);
		if(c){c.textContent=" = "+(r.votes[i]||0);}
	}
	if(r.voted>=0){
		var radios=document.getElementsByName("pollopt"+id);
		for(var j=0;j<radios.length;j++){
			radios[j].checked=(parseInt(radios[j].value,10)===r.voted);
			radios[j].disabled=true;
		}
		var opts=document.getElementById("polloptions"+id);
		var btns=document.getElementById("pollbuttons"+id);
		if(opts){opts.style.opacity="0.5";}
		if(btns){btns.style.display="none";}
	}
}
function pollVote(id){
	var radios=document.getElementsByName("pollopt"+id),v=-1;
	for(var i=0;i<radios.length;i++){if(radios[i].checked){v=radios[i].value;break;}}
	if(v<0){alert("Choose an option first.");return;}
	var pb=pollBoardFor(id);
	pollRequest(pollURL("vote",pb),"poll="+id+"&option="+encodeURIComponent(v)+"&board="+encodeURIComponent(pb),function(r){
		if(!r){alert("Your vote could not be recorded.");return;}
		if(r.error){alert(r.error);}
		pollApply(id,r);
	});
}
function pollResults(id){
	pollRequest(pollURL("pollresults="+id,pollBoardFor(id)),null,function(r){
		if(r&&r.options){pollApply(id,r);}
		else{alert((r&&r.error)?r.error:"Could not load the results.");}
	});
}
/* On page load, polls this IP already voted in are shown greyed out with their counts */
function pollInit(){
	if(!document.querySelectorAll){return;}
	var els=document.querySelectorAll("[data-poll]"),groups={},any=false;
	for(var i=0;i<els.length;i++){
		var b=els[i].getAttribute("data-board")||pollBoard();
		(groups[b]=groups[b]||[]).push(els[i].getAttribute("data-poll"));any=true;
	}
	if(!any){return;}
	for(var g in groups){if(!groups.hasOwnProperty(g)){continue;}(function(g){
		pollRequest(pollURL("pollstatus="+groups[g].join(","),g),null,function(r){
			if(!r){return;}
			for(var k in r){
				if(r.hasOwnProperty(k)&&r[k]&&r[k].options&&r[k].voted>=0){pollApply(k,r[k]);}
			}
		});
	})(g);}
}
if(document.addEventListener){document.addEventListener("DOMContentLoaded",pollInit,false);}
</script>
HTML;
}
