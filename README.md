# _listing.php — a pretty directory listing

A single, self-contained PHP page that renders a folder of files as a clean,
searchable, mobile-friendly gallery. Built to run from **one shared copy
symlinked into many content folders**, and to work on genuinely old browsers
(circa-2012 WebKit — e.g. an HP TouchPad).

## Features

- **Follows folders down.** Subfolders open in the same pretty view with a
  breadcrumb trail — one symlink at the top of a tree covers every folder beneath
  it. Folders sort first and link deeper; files and thumbnails work at any depth.
  (Sub-path access is sandboxed to the base folder — no `../` escapes.)
- **Grid & list views** with a toggle. Defaults automatically: a folder that's
  >80% images opens in **grid**, otherwise **list** (override per-folder).
- **Instant search** and **A–Z / Newest** sort — all client-side, no reloads.
- **Real thumbnails** for images, generated on the fly and shrunk to ~7 KB each
  (a 2 MB photo never hits the device). Type icons (PDF, doc, text, …) for the
  rest.
- **Prettified names**: `chicken-in-basil-cream.pdf` → *Chicken In Basil Cream*.
- **Self-contained**: all CSS, JS, and icons are embedded in `_listing.php`.
  The only file you must deploy is that one script.
- **Fast on old hardware**: ~67 KB page, ~50 KB of thumbnails for a 70-file
  folder (versus ~14 MB unoptimized).

## How it works

`_listing.php` figures out **which folder to list from the request URL**, not
from its own location on disk. That's the key to the symlink model: you keep one
real copy in a git project and symlink it into each content folder — each symlink
lists its own folder.

- **Listing source:** by default it reads the folder directly (`scandir`).
  Optionally it can fetch nginx's `autoindex_format json` output instead (set
  `json_url`).
- **Thumbnails:** requests to `_listing.php?thumb=<file>&v=<mtime>` return a
  GD-resized JPEG. Generated in memory (no cache files, no write permissions
  needed) and cached hard by the browser; the `?v=mtime` busts that cache when a
  file changes.
- **Icons:** each file-type PNG is embedded once as a base64 CSS class.

## Install

1. Put the project (this folder) somewhere you keep in git, e.g.
   `~/git/dir-listing/`. It contains `_listing.php` and, optionally, a shared
   `config.php`.
2. Symlink the script into each folder you want to browse:

   ```sh
   ln -s ~/git/dir-listing/_listing.php /path/to/Recipes/_listing.php
   ln -s ~/git/dir-listing/_listing.php /path/to/Photos/_listing.php
   # …one per folder
   ```

3. Serve those folders with nginx (example below), then visit
   `https://your-host/recipes/_listing.php`.

### nginx (example, one server for several shared folders)

The content folders are exposed under one web root (here via web-root symlinks),
so a single `root` + one PHP rule covers them all:

```nginx
server {
    listen 8000;
    root   /opt/homebrew/var/www;   # music, photos, recipes… symlinked in here
    autoindex on;

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

If a **reverse proxy** sits in front, make sure its `/…` location forwards
`.php` too — a plain `location /recipes { proxy_pass … }` gets out-ranked by a
`location ~ \.php$` on the proxy. Use `location ^~ /recipes { … }` so the prefix
wins.

## Configuration

`config.php` is optional and **layered**, and **where you put it matters**:

- **In this project folder** (next to the real `_listing.php`) → **shared**:
  applies to *every* folder the script is symlinked into. Keep this generic — or
  omit it entirely.
- **Inside a content folder** (e.g. `…/Recipes/config.php`) → **per-folder**:
  overrides the shared values for that one folder only. This is where
  folder-specific `title`/`noun` belong. It's ignored by git (it lives with the
  content, not the project) and is hidden from the listing.

⚠️ Don't put a folder-specific config (like `'title' => 'Our Recipes'`) in the
shared project folder — every folder would inherit it.

Each `config.php` returns a plain array:

```php
<?php
return [
    'title' => 'Our Recipes',   // default: derived from the folder name
    'noun'  => 'recipe',        // "3 recipes" / "No recipes match". default: 'item'
];
```

| Key | Default | Meaning |
|-----|---------|---------|
| `title` | folder name, title-cased | Page heading / browser title. |
| `noun` | `item` | Word used in counts and the empty message. |
| `default_view` | `auto` | `grid`, `list`, or `auto` (>80% images → grid). |
| `json_url` | `''` | If set, fetch this nginx JSON listing instead of reading disk. |
| `hidden` | `['config.php']` | Extra filenames to hide (dotfiles and the script itself are always hidden). |
| `newtab` | image types | Extensions that open in a new tab. Non-images open same-tab (they just download). |
| `strip_recipe_word` | `true` | Drop a trailing "recipe" from displayed names. |

## Permissions (the common gotcha)

PHP-FPM runs as its own user (often `_www`), and the nginx worker often runs as
`nobody`. **They can only list and serve folders they can read.** A folder that's
`drwx------` (owner-only) will show up empty and its thumbnails will fail. Fix
with either:

```sh
chmod -R o+rX /path/to/Folder      # world read + traverse
```

or run nginx/php-fpm as your own user. World-readable folders (`drwx---r-x`) work
as-is.

## Requirements

- PHP with the **GD** extension (for thumbnails; without it, image links just
  open the full file).
- nginx (or any web server that can run PHP and follow symlinks).

## Troubleshooting

- **"File not found" / "Primary script unknown"** — nginx sent PHP-FPM the wrong
  path. With `alias`, a server-wide `location ~ \.php$` uses the document root,
  not the alias. Prefer symlinking folders into the web root (so plain `root`
  works), or give the alias its own nested PHP location.
- **404 only through the public URL** — a reverse proxy is intercepting `.php`.
  Make its proxy location `^~` so it out-ranks the proxy's own `.php` handler.
- **Empty listing** — permissions (see above), or the folder really is empty.
- **Broken/oversized images on the device** — confirm GD is installed
  (`php -m | grep -i gd`); without it the page falls back to full-size images.
