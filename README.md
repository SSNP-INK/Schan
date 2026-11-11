# Schan

Schan is a self-hosted, multi-board imageboard written in PHP with a MySQL / MariaDB database. Threads and
index pages are generated as static HTML, so reading is fast, while posting, moderation and the staff panel
run through PHP.

This README covers what it does, how to install it, how to configure it, and how to run it day to day.

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [After installing](#after-installing)
- [Using the staff area](#using-the-staff-area)
- [Configuration (`settings.php`)](#configuration-settingsphp)
- [Anti-flood](#anti-flood)
- [Thumbnails and per-board images](#thumbnails-and-per-board-images)
- [Extensions](#extensions)
- [File layout](#file-layout)
- [Backups and moving the site](#backups-and-moving-the-site)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

**Boards and posting**
- Any number of boards, each with its own folder, title, description, logo and settings.
- Threads and replies with name, email, subject, message, tripcodes and optional capcodes for staff.
- Index pages, thread pages, a catalog (`catalog.html`) and JSON output (`threads.json`, `catalog.json`, per-thread `.json`).
- Sticky and locked threads, board locking, thread limits per board, bump limits, `sage`, `noko`.
- Reply backlinks, click-to-quote, hover previews and automatic thread refresh.
- Message truncation on index pages, word-break for very long words, greentext / orangetext / bluetext.
- BBCode: `[b]`, `[u]`, `[s]`, `[spoiler]`, `[quote]`, `[scroll]`, `[color=]`, `[size=]`.
- Optional text spoilers and image spoilers (a "Spoiler" checkbox that blurs the thumbnail).
- Multiple stylesheets (`yotsuba`, `yotsuba_b`, `photon` by default), chosen by the visitor in Options.
- Translations through `SCHAN_LOCALE`.

**Files and media**
- Uploads: JPEG, PNG, GIF, WebP, SVG, SWF (with Ruffle support), AAC, FLAC, OGG, Opus, MP3, WAV, MP4 and WebM.
- Click-to-expand images, built-in video playback, a play-button overlay on video thumbnails.
- Thumbnails through GD, ffmpeg or ImageMagick.
- Upload by URL, oEmbed embeds (YouTube, Vimeo, SoundCloud) and optional metadata stripping (needs ExifTool).
- Placeholder icons for file types that cannot be thumbnailed.

**Moderation**
- A staff area at `mod.php`, plus a moderated view of every board at `mod.php/<board>/` with moderation links on every post.
- Roles: super administrator, administrator, moderator, janitor and a posting-only role, with per-board accounts.
- Bans (by IP, with reasons, expiry and an optional public ban message), a report queue, keyword filters, and a moderation log.
- Delete posts and files, sticky, lock, move threads between boards, edit posts, rebuild boards.
- Approval queue: hold media and / or links until staff approve them, with trusted IPs that skip the queue.
- Auto-hide posts that collect enough reports.
- Anti-flood system, post delay, and optional CAPTCHAs (simple, hCaptcha or reCAPTCHA).

**Optional extensions** (switch on and off at runtime): polls, custom emoji and a BBCode toolbar, image and link approval,
trusted IPs, search, a global message, a custom home page, poster country flags, statistics, and country blocking.
See [Extensions](#extensions).

**Site tools**
- Static pages you write in the panel, a configurable board bar, an overboard (latest threads from every board).
- Import from a vichan / Schan database, and a database migration tool.

---

## Requirements

- A web server (Apache or nginx) with **PHP 7.3 or newer**.
- **MySQL or MariaDB**, and a database user that can create tables.
- PHP extensions: `mysqli`, `gd`, `json`, `session`, `fileinfo`.
- Recommended PHP extensions: `mbstring` (non-English text), `ctype` (search), `curl` (embeds and upload by URL).
- Optional: `ffmpeg` / `ffprobe` (video thumbnails), ImageMagick, ExifTool, and the GeoLite2 Country database
  (`GeoLite2-Country.mmdb`) for country flags and country blocking.
- PHP `file_uploads` on, and `upload_max_filesize` / `post_max_size` large enough for your files (match `SCHAN_MAXKB`).

The installer checks all of this for you.

---

## Installation

### 1. Upload the files

Copy the whole Schan folder to your web root (or a subfolder such as `/var/www/html/board`).

### 2. Create the database

Either create an empty database and user yourself, for example:

```sql
CREATE DATABASE schan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'schan'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON schan.* TO 'schan'@'localhost';
FLUSH PRIVILEGES;
```

or tick **Create database if it does not exist** in the installer (the user then needs permission to create databases).

### 3. Set permissions

The web server user (usually `www-data`) must be able to write to the install folder, `settings.php`, and the
folders it creates for boards and uploads:

```bash
cd /var/www/html/board          # your install folder
sudo chown -R www-data:www-data .
sudo find . -type d -exec chmod 775 {} +
sudo find . -type f -exec chmod 664 {} +
```

If you would rather keep your own account as the owner, use `chown -R youruser:www-data .` and keep group write
(`775` / `664`) instead.

### 4. Run the installer

Open `https://your-site/install.php` in a browser.

- **Step 1** checks PHP, extensions, files and folder permissions. Fix anything marked **Fix**, then continue.
  Warnings are optional.
- **Step 2** asks for the database host, port, name, user and password, the administrator password, the default
  board name (for example `b`), the timezone, and the URL path of the folder (leave empty when installed at the web root).

The installer tests the database connection, writes `settings.php`, generates a random tripcode seed and locks itself.

### 5. Finish

- **Delete `install.php`** from the server.
- Log in at `https://your-site/mod.php` with username `admin` and the password you chose.
- Create your first board (see below). Database tables are created automatically the first time the site loads.
- After you have created your staff accounts, blank `SCHAN_ADMINPASS` in `settings.php`, because it is stored in plain text.

To run the installer again, delete `.installed.php` and blank `SCHAN_DBNAME` in `settings.php`.

### Manual install (without the installer)

Fill in the database values in `settings.php` yourself (`SCHAN_DBHOST`, `SCHAN_DBPORT`, `SCHAN_DBUSERNAME`,
`SCHAN_DBPASSWORD`, `SCHAN_DBNAME`), set `SCHAN_TRIPSEED` to a random string, set `SCHAN_ADMINPASS`, and load the site.
**Never change `SCHAN_TRIPSEED` after launch**, or every existing tripcode changes.

---

## After installing

### Protect private files

Schan stores its data (extensions, pages, board features, flags, home page, post edits, ban appeals, IP map,
anti-flood state, news cache and so on) in the database. Old dot-files and `.php` data files from earlier versions are
imported once and renamed to `<name>.imported`; you can delete the `.imported` files afterwards. `settings.php` (database
credentials) is the only file that must stay on disk, so block direct access to it. For Apache:

```apache
<FilesMatch "^\.|^settings\.php$|^flood\.dat\.php$|^pending-data\.php$">
    Require all denied
</FilesMatch>
```

For nginx:

```nginx
location ~ /\. { deny all; }
location ~ ^/(settings|flood\.dat|pending-data)\.php$ { deny all; }
```

Also keep `settings.php` out of version control, because it holds your database password.

### HTTPS

Serve the board over HTTPS. Staff logins use a session cookie, so plain HTTP exposes it.

### Behind Cloudflare

If the site is served through Cloudflare, set `SCHAN_CLOUDFLARE` to `true` so real visitor IP addresses are used.
Without it, bans, delays and anti-flood would apply to Cloudflare's addresses.

---

## Using the staff area

Everything lives at **`mod.php`**.

| Address | What it is |
|---|---|
| `mod.php` | Log in, the dashboard, and every panel page (bans, reports, boards, and so on). |
| `mod.php/<board>/` | The board as visitors see it, with moderation links on every post and the staff post form. |
| `mod.php/<board>/2.html` | Page 2 of that board. |
| `mod.php/<board>/res/5.html` | Thread 5. |

Old `imgboard.php?manage...` addresses redirect to `mod.php`. Board links in the staff area (the board bar and other
board links) open the moderated view of that board.

### The dashboard

| Section | Use |
|---|---|
| **Boards** | Create, edit, lock and delete boards. Each row links to the moderated view, the board's pages, bans and reports. |
| **Report queue** | Reports from visitors. Review a post, then delete it, ban the poster, or clear the report. |
| **Pending approval** | Media and links waiting for approval (needs the *Image and link approval* extension). |
| **Ban list** | Active bans. Add, edit and lift bans. Bans can have a reason, an expiry and an optional public message. |
| **Keywords** | Words and phrases that trigger an action when found in a post. |
| **Countries** | Blocked countries (needs the *Country blocking* extension). |
| **Manage users** | Staff accounts, roles and which board each account is bound to. |
| **Moderation log** | A record of staff actions. |
| **Static pages** | Create and edit your own pages (rules, FAQ, and so on). |
| **Home page** | Build the site home page from blocks (needs the *Home page* extension). |
| **Emoji** | Upload and manage custom emoji (needs the *Custom emoji* extension). |
| **Rebuild** | Regenerate all pages. Use this after changing settings or images. |
| **Extensions** | Install and uninstall optional features. |
| **Configuration** | Site-level options. |
| **Import / Migrate Database** | Bring in a vichan / Schan database, or migrate between database types. |

### Roles

| Role | Access |
|---|---|
| Super administrator | Everything, on every board. |
| Administrator | Full panel access (limited to their board if the account is bound to one). |
| Moderator | Moderation tools for their board(s). |
| Janitor | The manage panel only. On public pages they are treated as an ordinary visitor. |
| Posting-only | Can use the staff post form but has no moderation tools. |
| Disabled | The account exists but cannot log in. |

Accounts can be bound to a single board or to all boards.

### Everyday moderation

- **Delete a post:** use the delete link on the post in the moderated view, or tick it and use the delete form.
- **Ban the poster:** use the **B** button on the post, then set the length and reason.
- **Sticky / lock a thread:** use the sticky and lock buttons on the thread's first post.
- **Move a thread:** use the move link on the thread, and pick the destination board.
- **Staff posts:** the post form in the moderated view posts with your capcode, even on locked boards.
- **Approve held media or links:** open **Pending approval** and approve or delete each item.

### Creating a board

1. Open **Boards** and click **Create new board**.
2. Enter a slug (lowercase letters, numbers, `_`, `-`), a title and a description.
3. Open the board at `/<slug>/` (or `mod.php/<slug>/`).

Slugs that clash with Schan's own files and folders (`css`, `js`, `inc`, `res`, `src`, `thumb`, `static`, `mod`,
`search`, `stats`, `flags`, `catalog`, `index`, and similar) are reserved and rejected. The full list is
`$schan_reserved_slugs` in `settings.php`.

---

## Configuration (`settings.php`)

Edit `settings.php` by hand at any time. Many changes need **Rebuild** in the panel before they show up on existing pages.
Optional lines at the bottom are commented out; remove the `//` to turn one on.

### Site and posting

| Setting | Meaning |
|---|---|
| `SCHAN_ROOTURL` | URL path of the install (empty at the web root, `/board` in a subfolder). |
| `SCHAN_TIMEZONE`, `SCHAN_DATEFMT` | Timezone and date format. |
| `SCHAN_LOCALE` | Language (see the translation files). Empty for English. |
| `SCHAN_DEFAULTBOARD` | Board served when none is given. |
| `SCHAN_BOARDDESC`, `SCHAN_BOARDTITLE`, `SCHAN_LOGO` | Fallbacks for boards without their own. |
| `SCHAN_ALWAYSNOKO` | Redirect to the thread after posting. |
| `SCHAN_CAPTCHA`, `SCHAN_REPLYCAPTCHA`, `SCHAN_REPORTCAPTCHA`, `SCHAN_MANAGECAPTCHA` | CAPTCHA for new threads, replies, reports and staff login: `simple`, `hcaptcha`, `recaptcha`, or empty to disable. |
| `SCHAN_REPORT` | Let visitors report posts. |
| `SCHAN_AUTOHIDE` | Number of reports that hides a post until approved (`0` to disable). |
| `SCHAN_REQMOD` | Hold posts for approval: `files`, `all`, or empty. |
| `SCHAN_REQMODMEDIA` | Held media shows `pending.png` until approved. |
| `SCHAN_REQMODLINKS` | Held links show "(link pending approval)". |
| `SCHAN_BANMESSAGE` | Allow a public message on banned posts. |
| `SCHAN_SPOILERTEXT`, `SCHAN_SPOILERIMAGE` | Allow text spoilers and thumbnail spoilers. |
| `SCHAN_AUTOREFRESH` | Seconds between automatic thread refreshes (`0` to disable). |
| `SCHAN_DISALLOWTHREADS`, `SCHAN_DISALLOWREPLIES` | Show this message instead of allowing new threads or replies. |
| `SCHAN_CLOUDFLARE` | Enable only when served through Cloudflare. |

### Appearance

| Setting | Meaning |
|---|---|
| `SCHAN_THREADSPERPAGE`, `SCHAN_PREVIEWREPLIES` | Threads per index page, and replies previewed per thread. |
| `SCHAN_TRUNCATE`, `SCHAN_WORDBREAK` | Truncate long messages on index pages, and break very long words. |
| `SCHAN_EXPANDWIDTH` | Expanded media width as a percentage of the screen. |
| `SCHAN_BACKLINKS`, `SCHAN_CATALOG`, `SCHAN_JSON` | Backlinks, the catalog page, and JSON output. |
| `SCHAN_DEFAULTSTYLE` and `$schan_stylesheets` | Default stylesheet, and the list of stylesheets in `css/`. |
| `$schan_anonymous`, `$schan_capcodes` | Default poster names, and staff capcode labels and colors. |
| `$schan_hidefieldsop`, `$schan_hidefields` | Hide post form fields for new threads and for replies. |
| `$schan_boardbar_links` | Extra links in the board bar. The panel also manages this. |

Optional (commented out by default): `SCHAN_SITENAME`, `SCHAN_FOOTER`, `SCHAN_FAVICON`, `SCHAN_HEADEXTRA`,
`SCHAN_CUSTOMCSS`, `SCHAN_NOCACHE`, `SCHAN_GREENTEXT`, `SCHAN_ORANGETEXT`, `SCHAN_BLUETEXT`, `SCHAN_OPTIONSLINK`,
`SCHAN_SEARCHBAR`, `SCHAN_USERDELETE`, `SCHAN_BOARDBARSTYLE`, `SCHAN_HOVERDEFAULT`, `SCHAN_RUFFLE`, `SCHAN_RUFFLEURL`.

### Post control

| Setting | Meaning |
|---|---|
| `SCHAN_DELAY` | Seconds between posts from the same IP (`0` to disable). |
| `SCHAN_MAXTHREADS` | Oldest threads are removed past this many (`0` for no limit). |
| `SCHAN_MAXREPLIES` | Replies before a thread stops bumping (`0` for no limit). |
| `SCHAN_MAXNAME`, `SCHAN_MAXEMAIL`, `SCHAN_MAXSUBJECT`, `SCHAN_MAXMESSAGE` | Maximum field lengths (`0` for no limit). |

### Files

| Setting | Meaning |
|---|---|
| `$schan_uploads` | Allowed file types: `'mime/type' => array('extension', 'optional placeholder image')`. Empty array disables uploads. |
| `$schan_embeds` | oEmbed providers. Empty array disables embeds. |
| `SCHAN_MAXKB`, `SCHAN_MAXKBDESC` | Maximum upload size in KB, and its label. |
| `SCHAN_THUMBNAIL` | Thumbnail method: `gd`, `ffmpeg` or `imagemagick`. |
| `SCHAN_UPLOADVIAURL`, `SCHAN_STRIPMETADATA`, `SCHAN_NOFILEOK` | Upload by URL, strip metadata (ExifTool), and allow threads without a file. |
| `SCHAN_MAXWOP`, `SCHAN_MAXHOP`, `SCHAN_MAXW`, `SCHAN_MAXH` | Thumbnail size for new threads and for replies. |

### Staff and database

`SCHAN_MANAGEKEY`, `SCHAN_ADMINPASS`, `SCHAN_MODPASS`, `SCHAN_GEOIPDB`, `SCHAN_TRIPSEED` and the `SCHAN_DB*` settings.
Leave `SCHAN_DBMIGRATE` off unless you are migrating.

---

## Anti-flood

Anti-flood limits how much a single visitor (and, optionally, everyone together) can post in a time window. It works
alongside `SCHAN_DELAY`, and it never applies to logged-in staff.

```php
define('SCHAN_ANTIFLOOD', true);     // Enable the anti-flood system  [false to disable]
define('SCHAN_FLOODWINDOW', 300);    // Time window (in seconds) that the limits below are counted over
define('SCHAN_FLOODPOSTS', 10);      // Maximum posts from one IP address within the window  [0 to disable]
define('SCHAN_FLOODTHREADS', 3);     // Maximum new threads from one IP address within the window  [0 to disable]
define('SCHAN_FLOODDUPES', true);    // Refuse the same message posted twice by one IP address within the window
define('SCHAN_FLOODGLOBAL', 0);      // Maximum posts from all visitors together within the window  [0 to disable]
define('SCHAN_FLOODCOOLDOWN', 600);  // Seconds an IP address is blocked from posting after hitting a limit  [0 for no block]
```

When a per-IP limit is hit, that IP is blocked from posting for `SCHAN_FLOODCOOLDOWN` seconds. State is kept in the
database (settings table, name `flood`) and contains only hashed IPs and timestamps. If the database lock cannot be
taken, anti-flood skips itself rather than stop people posting.

---

## Thumbnails and per-board images

### Where files go

- Uploaded files: `<board>/src/`
- Thumbnails: `<board>/thumb/` (each board has its own folder)
- Thread and index pages: `<board>/` and `<board>/res/`

Thumbnails made by an earlier version into a shared `static/thumb/` folder are still found and shown.
The web server user must be able to write to each board's `src/` and `thumb/` folders.

### Per-board images

A board can override any of these images by placing its own copy in the board's folder (`<board>/`). Each is checked
separately, so a board can override some and not others. Anything missing or unreadable falls back to the site-wide image.

| File in the board folder | Replaces |
|---|---|
| `pending.png` | The image shown instead of media that is waiting for approval. |
| `spoiler.png` | The image shown instead of a spoilered thumbnail. |
| `video_overlay.png` | The play button drawn on video thumbnails (new uploads only). |
| The placeholder's file name, for example `swf_thumbnail.png` | The icon used for file types that cannot be thumbnailed (new uploads only). |

Pending and spoiler images are chosen when pages are built, so run **Rebuild** after adding or changing them. The video
overlay and placeholder icons are part of the thumbnail file made at upload time, so existing thumbnails keep their old look.

---

## Extensions

Open **Extensions** in the panel to install or uninstall each one. Uninstalling permanently deletes that extension's
stored data, and the panel shows exactly what will be removed before you confirm.

| Extension | Default | What it does |
|---|---|---|
| Polls | On | Let posters attach a poll (2 to 8 options, one vote per IP) to a post. |
| Custom emoji and BBCode toolbar | On | Custom `:emoji:` images, emoji and kaomoji pickers, and a BBCode toolbar in the post form. |
| Image and link approval | On | Hold media and / or links in new posts until staff approve them. Adds the **Pending approval** queue. |
| Trusted IPs | On | Whitelist a poster on a board so their posts skip approval. |
| Search | On | Board search page and search boxes (MySQL / mysqli only). Stores no data. |
| Global message | On | One line of text shown on every board between the post form and the threads. |
| Home page | Off | Generate a custom `index.html` home page from blocks: logo, boards, recent posts, stats, custom HTML. |
| Poster country flags | Off | A country flag next to each name, from the GeoIP database or the Cloudflare header. Also custom flags. |
| Board statistics | Off | `stats.html` (posts per hour, day, week and month for every board) and a Watching / Replies / Attachments line under threads. |
| Country blocking | Off | Refuse posts from selected countries. Needs the GeoIP database (or Cloudflare) and the flags extension. |

For flags and country blocking, download the free GeoLite2 Country database from MaxMind and put it at
`inc/GeoLite2-Country.mmdb`, or set `SCHAN_GEOIPDB` to its full path.

---

## File layout

```
install.php            installer (delete after use)
imgboard.php           posting, moderation and board logic
mod.php                the staff area and moderated board views
settings.php           all configuration
license.txt            license
css/                   stylesheets
inc/                   application code
  defines.php          constants
  functions.php        shared helpers
  html.php             page building
  antiflood.php        anti-flood system
  extensions.php       extension registry
  config.php           site options
  roles.php            staff roles
  database/            database drivers
  element/             page parts (header, post, thumbnailing, catalog, manage, ...)
  extra/               optional extensions (polls, emoji, pending, trust, search, ...)
static/emojis/         custom emoji images
<board>/               one folder per board
  src/                 uploaded files
  thumb/               thumbnails
  res/                 thread pages
```

---

## Backups and moving the site

Back up two things:

1. **The database** (`mysqldump -u user -p database > backup.sql`).
2. **The install folder**, especially each `<board>/src/` and `<board>/thumb/`, `settings.php` and `static/`.

To move to a new server: copy both, restore the database, update the `SCHAN_DB*` values and `SCHAN_ROOTURL` in
`settings.php` if they changed, fix permissions, and run **Rebuild**. Keep the same `SCHAN_TRIPSEED`.

---

## Troubleshooting

**"Permission denied" when uploading or thumbnailing.**
The web server user cannot write to the board's `src/` or `thumb/` folder. Run:
```bash
sudo chown -R www-data:www-data /path/to/install/<board>/src /path/to/install/<board>/thumb
sudo chmod -R 775 /path/to/install/<board>/src /path/to/install/<board>/thumb
```
Check parent folders too (`namei -l /path/to/install/<board>/thumb`), and SELinux if you use it.

**"Could not create thumbnail."**
Usually a permissions problem (above), a missing PHP `gd` extension, or a file whose extension does not match its real
type.

**Uploads fail or are cut off.**
Raise `upload_max_filesize` and `post_max_size` in `php.ini`, and `client_max_body_size` in nginx.

**Pages look unchanged after a settings change.**
Use **Rebuild** in the panel. Pages are static HTML.

**Cannot log in to the panel.**
Use the username `admin`. If you blanked `SCHAN_ADMINPASS` before creating another account, set it again temporarily,
or reset the account in the database.

**The installer says it is already installed.**
Delete `.installed.php` and blank `SCHAN_DBNAME` in `settings.php`.

**Flags or country blocking do nothing.**
The GeoIP database is missing, or `SCHAN_GEOIPDB` points to the wrong path. Country blocking also needs the flags extension.

---

## License

See `license.txt`.
