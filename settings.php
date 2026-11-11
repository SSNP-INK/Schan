<?php
/*
Schan
https://codeberg.org/tslocum/schan

This file is filled in by install.php. Open install.php in your browser to set up the board.
After installing you can still edit any value here by hand.

Many settings require the imageboard to be rebuilt to take effect. To do this,
click Rebuild All in the management panel.
*/
// Database  (filled in by install.php)
define('SCHAN_DBMODE', 'mysqli');     // Mode
define('SCHAN_DBACCOUNTS', 'accounts'); // Staff accounts
define('SCHAN_DBBANS', 'bans');         // Bans
define('SCHAN_DBKEYWORDS', 'keywords'); // Keywords
define('SCHAN_DBLOGS', 'logs');         // Staff logs
define('SCHAN_DBPOSTS', 'posts');       // Posts  (shared across all boards)
define('SCHAN_DBREPORTS', 'reports');   // Reports  (shared across all boards)
define('SCHAN_DBBOARDS', 'boards');     // Boards  (registry of boards)
define('SCHAN_DBMIGRATE', false);       // Enable database migration tool

define('SCHAN_DBHOST', 'localhost'); // Hostname
define('SCHAN_DBPORT', 3306);        // Port  (set to 0 if you are using a UNIX socket as the host)
define('SCHAN_DBUSERNAME', '');      // Username
define('SCHAN_DBPASSWORD', '');      // Password
define('SCHAN_DBNAME', '');          // Database

define('SCHAN_DBPATH', '.schan.db');  // SQLite DB path
define('SCHAN_DBDRIVER', 'mysql');   // PDO driver to use (mysql / pgsql / sqlite / etc.)
define('SCHAN_DBDSN', '');           // Custom DSN, overrides the connection settings above

// Internationalization
define('SCHAN_ROOTURL', '');   // URL path of the install root; '' if at the web root
define('SCHAN_LOCALE', '');                      // See README for instructions  ['' to run in English]
define('SCHAN_TIMEZONE', 'UTC');                 // See https://secure.php.net/manual/en/timezones.php - e.g. America/Los_Angeles
define('SCHAN_DATEFMT', '%g/%m/%d(%a)%H:%M:%S'); // Date and time format  (see php.net/strftime)
define('SCHAN_GEOIPDB', '');                     // Full path to GeoLite2-Country.mmdb  ['' if not used]

// Board description and behavior
define('SCHAN_BOARD', '');          // Legacy identifier  (kept for compatibility)
define('SCHAN_DEFAULTBOARD', 'b');   // Board served when none is specified
define('SCHAN_BOARDDESC', ''); // Displayed below logo in page headers (fallback for boards without a description)
define('SCHAN_BOARDTITLE', '');      // Title of board pages.  When blank, defaults to SCHAN_BOARDDESC (when set) or "Schan"
define('SCHAN_ALWAYSNOKO', true);   // Redirect to thread after posting
define('SCHAN_CAPTCHA', '');         // Require a CAPTCHA when posting a new thread: simple / hcaptcha / recaptcha  ['' to disable]
define('SCHAN_REPLYCAPTCHA', '');    // Require a CAPTCHA when posting a reply: simple / hcaptcha / recaptcha  ['' to disable]
define('SCHAN_REPORTCAPTCHA', '');   // Require a CAPTCHA when reporting: simple / hcaptcha / recaptcha  ['' to disable]
define('SCHAN_MANAGECAPTCHA', '');   // Require a CAPTCHA when logging in to the management panel: simple / hcaptcha / recaptcha  ['' to disable]
define('SCHAN_REPORT', false);       // Allow users to report posts
define('SCHAN_AUTOHIDE', 0);         // Amount of reports which will cause a post to be hidden until it is approved  [0 to disable]
define('SCHAN_REQMOD', '');          // Require moderation before displaying posts: files / all  ['' to disable]
define('SCHAN_REQMODMEDIA', true); // Media shows pending.png until approved
define('SCHAN_REQMODLINKS', false); // Links show "(link pending approval)" until approved
define('SCHAN_BANMESSAGE', true);    // Allow staff to append a custom message to posts when banning users
define('SCHAN_UPDATEBUMPED', true);  // Update thread position when a reply is deleted
define('SCHAN_SPOILERTEXT', false);  // Allow users to hide text until it is hovered over using <s>text</s> or <spoiler>text</spoiler>
define('SCHAN_SPOILERIMAGE', false); // Allow users to blur thumbnails via a "Spoiler" checkbox
define('SCHAN_AUTOREFRESH', 30);     // Delay (in seconds) between attempts to refresh a thread automatically  [0 to disable]
define('SCHAN_CLOUDFLARE', false);   // Only enable when the site is served via Cloudflare to identify IP addresses correctly
define('SCHAN_DISALLOWTHREADS', ''); // When set, users attempting to post a new thread are shown this message instead  ['' to disable]
define('SCHAN_DISALLOWREPLIES', ''); // When set, users attempting to post a reply are shown this message instead  ['' to disable]

// Board appearance
define('SCHAN_INDEX', 'index.html'); // Index file
define('SCHAN_LOGO', '');            // Logo HTML (fallback for boards without a logo)
define('SCHAN_THREADSPERPAGE', 10);  // Amount of threads shown per index page
define('SCHAN_PREVIEWREPLIES', 3);   // Amount of replies previewed on index pages
define('SCHAN_TRUNCATE', 15);        // Messages are truncated to this many lines on board index pages  [0 to disable]
define('SCHAN_WORDBREAK', 80);       // Words longer than this many characters will be broken apart  [0 to disable]
define('SCHAN_EXPANDWIDTH', 85);     // Expanded content size as a percentage of the screen's width
define('SCHAN_BACKLINKS', true);     // Display reflinks to replies that reference a post
define('SCHAN_CATALOG', true);       // Generate catalog page
define('SCHAN_JSON', true);          // Generate JSON files
define('SCHAN_DEFAULTSTYLE', 'yotsuba_b'); // Default page style
$schan_hidefieldsop = array();       // Fields to hide when creating a new thread - e.g. array('name', 'email', 'subject', 'message', 'file', 'embed', 'password')
$schan_hidefields = array();         // Fields to hide when replying
$schan_anonymous = array(
	'Anonymous'
); // Default name (or names)
$schan_capcodes = array(
	array('Admin', 'red'),
	array('Mod', 'purple'),
	array('Maya', 'red')
); // Administrator and moderator capcode label and color
// Stylesheets (located in css)
//   Format: File name excluding extension => Title
$schan_stylesheets = array(
	'yotsuba' => 'yotsuba',
	'yotsuba_b' => 'yotsuba b',
	'photon' => 'photon'
);

// Reserved board slugs.
//   These names collide with files or folders that the install needs, and
//   cannot be used as board slugs.
$schan_reserved_slugs = array(
	'css', 'js', 'inc', 'res', 'src', 'thumb', 'emoji', 'static', 'boards',
	'imgboard', 'imgboard.php', 'settings', 'settings.php',
	'install', 'install.php', 'mod', 'mod.php', 'search', 'search.php',
	'stats', 'stats.html', 'flags', 'flags.html',
	'favicon', 'favicon.ico', 'README', 'README.md', 'LICENSE',
	'catalog', 'catalog.html', 'index', 'index.html', 'index.php',
	'sticky', 'sticky.png', 'lock', 'lock.png',
	'video_overlay', 'video_overlay.png', 'kaomoji', 'kaomoji.txt',
	'.git', '.htaccess', '.schan.db', 'schan.db', 'schan.lock',
);

// Post control
define('SCHAN_DELAY', 30);           // Delay (in seconds) between posts from the same IP address  [0 to disable]
define('SCHAN_MAXTHREADS', 100);     // Oldest threads are discarded when the thread count passes this limit  [0 to disable]
define('SCHAN_MAXREPLIES', 0);       // Maximum replies before a thread stops bumping  [0 to disable]
define('SCHAN_MAXNAME', 75);         // Maximum name length  [0 to disable]
define('SCHAN_MAXEMAIL', 320);       // Maximum email length  [0 to disable]
define('SCHAN_MAXSUBJECT', 75);      // Maximum subject length  [0 to disable]
define('SCHAN_MAXMESSAGE', 8000);    // Maximum message length  [0 to disable]

// Anti-flood  (extra to SCHAN_DELAY: limits per IP address over a time window; staff are never limited)
define('SCHAN_ANTIFLOOD', true);     // Enable the anti-flood system  [false to disable]
define('SCHAN_FLOODWINDOW', 300);    // Time window (in seconds) that the limits below are counted over
define('SCHAN_FLOODPOSTS', 10);      // Maximum posts from one IP address within the window  [0 to disable]
define('SCHAN_FLOODTHREADS', 3);     // Maximum new threads from one IP address within the window  [0 to disable]
define('SCHAN_FLOODDUPES', true);    // Refuse the same message posted twice by one IP address within the window
define('SCHAN_FLOODGLOBAL', 0);      // Maximum posts from all visitors together within the window  [0 to disable]
define('SCHAN_FLOODCOOLDOWN', 600);  // Seconds an IP address is blocked from posting after hitting a limit  [0 for no block]

// Upload types
//   Empty array to disable
//   Format: MIME type => (extension, optional thumbnail)
$schan_uploads = array(
	'image/jpeg' => array('jpg'),
	'image/pjpeg' => array('jpg'),
	'image/png' => array('png'),
	'image/gif' => array('gif'),
	'image/webp' => array('webp'),
	'image/svg+xml' => array('svg'),
	'application/x-shockwave-flash' => array('swf', 'swf_thumbnail.png'),
	'audio/aac' => array('aac'),
	'audio/flac' => array('flac'),
	'audio/ogg' => array('ogg'),
	'audio/opus' => array('opus'),
	'audio/mp3' => array('mp3'),
	'audio/mpeg' => array('mp3'),
	'video/mp4' => array('mp4'),
	'audio/wav' => array('wav'),
	'video/webm' => array('webm')
);

// oEmbed APIs
//   Empty array to disable
$schan_embeds = array('SoundCloud' => 'https://soundcloud.com/oembed?format=json&url=SCHANEMBED',
                       'Vimeo'      => 'https://vimeo.com/api/oembed.json?url=SCHANEMBED',
                       'YouTube'    => 'https://www.youtube.com/oembed?url=SCHANEMBED&format=json');

// File control
define('SCHAN_MAXKB', 30720);        // Maximum file size in kilobytes  [0 to disable]  (30720 = 30 MB, matching SCHAN_MAXKBDESC)
define('SCHAN_MAXKBDESC', '30 MB');   // Human-readable representation of the maximum file size
define('SCHAN_THUMBNAIL', 'gd');     // Thumbnail method to use: gd / ffmpeg / imagemagick  (see README for instructions)
define('SCHAN_UPLOADVIAURL', false); // Allow files to be uploaded via URL
define('SCHAN_STRIPMETADATA', false);// Attempt to strip all metadata from uploaded files  (requires ExifTool)
define('SCHAN_NOFILEOK', false);     // Allow the creation of new threads without uploading a file

// Thumbnail size - new thread
define('SCHAN_MAXWOP', 250);         // Width
define('SCHAN_MAXHOP', 250);         // Height

// Thumbnail size - reply
define('SCHAN_MAXW', 250);           // Width
define('SCHAN_MAXH', 250);           // Height

// Tripcode seed - Must not change once set!  (install.php generates a random one)
define('SCHAN_TRIPSEED', '8fcfcc12240552e61ba26ca8704addaa');

// CAPTCHA - hCaptcha / reCAPTCHA
define('SCHAN_HCAPTCHA_SITE', '');   // Site key
define('SCHAN_HCAPTCHA_SECRET', ''); // Secret key
define('SCHAN_RECAPTCHA_SITE', '');  // Site key
define('SCHAN_RECAPTCHA_SECRET', '');// Secret key

// Management panel
define('SCHAN_MANAGEKEY', '');       // When set, the management panel may only be accessed via imgboard.php?manage=SCHAN_MANAGEKEY  ['' to disable]
//   When SCHAN_ADMINPASS is set, an administrator account is created with username "admin".
//   install.php fills this in. Once the account exists you may blank it.
define('SCHAN_ADMINPASS', '');       // Administrator password
define('SCHAN_MODPASS', '');         // Moderator password  ['' to disable]

// Page options
//   Everything below is optional and commented out. Remove the // in front of a line to turn it on;
//   the value shown is what the board does when the line is left out.
//
// Names and text
// define('SCHAN_SITENAME', 'Schan');         // Page title used when a board has no name or description
// define('SCHAN_FOOTER', 'Schan');           // Footer text (HTML allowed)
// define('SCHAN_FAVICON', '');               // Full URL of the favicon  ['' = favicon.ico in the install root]
//
// Page head
// define('SCHAN_HEADEXTRA', '');             // Extra HTML put at the end of <head> (analytics, fonts, meta tags)
// define('SCHAN_CUSTOMCSS', '');             // Extra CSS applied on every page, e.g. 'body { font-size: 11pt; }'
// define('SCHAN_NOCACHE', true);             // Send the no-cache meta tags
//
// Colors of >greentext, >>orangetext and bluetext
// define('SCHAN_GREENTEXT', '#789922');
// define('SCHAN_ORANGETEXT', '#e08a00');
// define('SCHAN_BLUETEXT', '#3b7dd8');
//
// Page parts
// define('SCHAN_OPTIONSLINK', true);         // Show the Options link and window
// define('SCHAN_SEARCHBAR', true);           // Show the search box on board pages (needs the Search extension)
// define('SCHAN_USERDELETE', true);          // Show the "Delete Post" password box at the bottom of pages
//
// Behavior
// define('SCHAN_HOVERDEFAULT', true);        // Image hover preview on by default (visitors can change it in Options)
// define('SCHAN_RUFFLE', true);              // Load Ruffle so .swf files can play
// define('SCHAN_RUFFLEURL', 'https://unpkg.com/@ruffle-rs/ruffle'); // Where Ruffle is loaded from
