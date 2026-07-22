# CLAUDE.md — working on `_listing.php`

A single self-contained PHP file that renders a directory as a searchable
gallery. One real copy is **symlinked into many content folders**; each symlink
lists its own folder. See `README.md` for the user-facing overview.

## Hard invariants — violate these and it breaks

1. **Content directory comes from the REQUEST, never `__DIR__`.**
   `__DIR__`/`__FILE__` resolve symlinks to the *real* file (the git checkout),
   so they point at the wrong folder when the script is symlinked in. The script
   uses `$BASE_DIR = dirname($_SERVER['SCRIPT_FILENAME'])` (falling back to
   `__DIR__` only for CLI). All directory scans and thumbnail file lookups must
   use `$BASE_DIR`. `__DIR__` is used **only** to load the shared `config.php`
   that sits next to the real script.

2. **One self-contained file.** All CSS, JS, and icons are inline in
   `_listing.php`. Do not add external assets (no linked CSS/JS, no image files).
   The base64 PNG icons are defined **once** as CSS classes (`.ic-pdf { … }`),
   *not* per card — inlining them per card previously bloated the page ~4×.

3. **Target ≈2012 WebKit (HP TouchPad / iOS 5-6).** Do not introduce:
   - CSS: variables (`var()`), grid, flexbox, `gap`, `object-fit`,
     `aspect-ratio`, `clamp()`, `color-mix()`, `position: sticky`,
     `backdrop-filter`, `@media (prefers-color-scheme)`. Layout is `inline-block`
     + `float`; thumbnails use `-webkit-background-size: cover`. Use `-webkit-`
     prefixes on `border-radius`, `box-shadow`, `box-sizing`, `appearance`.
   - JS: `let`/`const`, arrow functions, template literals, `NodeList.forEach`,
     `classList`, `fetch`. Stick to ES5: `var`, `function`, `for` loops,
     `Array.prototype.slice.call`, and `className` string manipulation.

4. **Thumbnail endpoint must emit nothing before the image bytes.** `?thumb=`
   streams a GD JPEG with only its two headers. No warnings/notices/whitespace
   may precede it (they corrupt the stream if `display_errors` is ever on) — e.g.
   don't reintroduce deprecated GD calls. It writes no cache files (PHP-FPM user
   usually can't write to content dirs); the browser caches via the `?v=mtime`
   query.

5. **All URL-derived paths are sandboxed to `$BASE_DIR`.** Subfolder navigation
   (`?path=`) and nested thumbnails (`?thumb=Album/pic.jpg`) go through
   `safe_subpath()` / the same guard inside `serve_thumb()`: reject `..`, and
   require `realpath()` to stay inside the base. Never build a filesystem path
   from a request value without that check.

## Architecture (top to bottom of the file)

- **Config** (`$CONFIG` defaults) → merged with shared `config.php` (`__DIR__`)
  then per-folder `config.php` (`$BASE_DIR`). Keys: `title`, `noun`,
  `default_view`, `json_url`, `hidden`, `newtab`, `strip_recipe_word`.
- **`?thumb=` dispatch** → `serve_thumb($BASE_DIR, $name, 240)` then `exit`.
- **Helpers**: `ext_of`, `is_image_ext`, `icon_key_for`, `prettify`,
  `human_size`, `serve_thumb`, `icon_data` (base64 map) / `icon_css` (emits the
  `.ic-*` rules once).
- **`?path=` navigation** → `$subpath` (via `safe_subpath`) and `$LIST_DIR`
  (`$BASE_DIR` + subpath). Folder cards link to `?path=…`; files/thumbnails use
  the sub-path relative to the page URL. Breadcrumbs render when `$subpath !== ''`.
- **`get_entries($cfg, $listDir, $allowJson)`** → `json_url` fetch if set and at
  root, else `scandir($listDir)`. Returns `[name, type, size, mtime]`.
- **Build `$items`** (skip dotfiles / self / hidden), sort, compute
  `$defaultView` (config or 80%-image rule), `$title` (config or folder name),
  `$noun`.
- **HTML**: `<style>` (hardcoded colors + `icon_css()`), toolbar
  (search / view toggle / sort toggle), `#grid.view-<grid|list>` of `.card`
  anchors, `<script>` (search filter, sort, view toggle, lazy-load).

## Behaviors worth preserving

- **Links**: images (+svg) open in a new tab (`newtab`); everything else opens
  same-tab so downloads don't spawn an orphan blank tab.
- **Lazy-load**: photo thumbnails carry `data-bg` and load when near the viewport
  (scroll + `resize` + `touchend`, throttled). Icons are shared CSS, not lazy.
- **Title/noun** auto-adapt per folder so one copy serves many folders.

## Testing

```sh
php -l _listing.php                                   # syntax
php -l config.php
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/recipes/_listing.php
curl -s 'http://localhost:8000/recipes/_listing.php?thumb=Some%20Photo.jpg&v=1' -o /tmp/t.jpg
php -r '$i=getimagesize("/tmp/t.jpg"); echo $i?"ok {$i[0]}x{$i[1]}\n":"bad\n";'
```

To reproduce the **symlink** case, run through a symlink with `SCRIPT_FILENAME`
set to the symlink path and confirm it lists the *target* folder, not the real
file's folder. Page size and thumbnail size are the perf canaries — a jump in
either usually means icon dedup or the thumbnail endpoint regressed.

## Environment notes (this deployment)

- Homebrew nginx on `:8000`; content folders symlinked into
  `/opt/homebrew/var/www`; PHP-FPM at `127.0.0.1:9000` running as `_www`, nginx
  workers as `nobody`. A separate Ubuntu nginx reverse-proxies the public host.
- Owner-only (`drwx------`) folders can't be read by `_www`/`nobody` → empty
  listings. Fix with `chmod o+rX` or run the servers as the owner.
