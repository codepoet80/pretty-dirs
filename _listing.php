<?php
/**
 * _listing.php — a pretty, self-contained directory listing for the Recipes folder.
 *
 * Everything (HTML, CSS, JS, icons) lives in this one file. Icons are inline SVG.
 *
 * Optional: drop a `config.php` next to this file that RETURNS an array to override
 * any of the defaults in $CONFIG below, e.g.:
 *
 *     <?php return [
 *         'title'    => 'Nic & Jon — Recipes',
 *         'json_url' => 'https://home.jonandnic.com/recipes/index.json',
 *         'hidden'   => ['Logingov issue.pdf', 'ABI Derm.pdf'],
 *     ];
 */

// ------------------------------------------------------------------ config ---
$CONFIG = [
    // Page heading + browser title. null = derive from the folder name, so one
    // shared/symlinked copy titles itself per folder (e.g. /photos -> "Photos").
    'title'    => null,

    // nginx autoindex JSON endpoint. If reachable, it's used as the source of
    // truth. Leave '' (or unreachable) to fall back to reading this folder
    // directly from disk — that fallback needs no nginx changes and works today.
    'json_url' => '',

    // Extra filenames to hide. (Dotfiles, _listing.php, and config.php are
    // ALWAYS hidden regardless of this list.) e.g. ['ABI Derm.pdf'].
    'hidden'   => [],

    // Open these types in a new tab (images render inline, so a new tab keeps
    // the listing open). Everything else (PDF, doc, txt…) opens same-tab and
    // just downloads — no orphan blank tab.
    'newtab'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],

    // Strip a trailing " recipe" word from prettified names.
    'strip_recipe_word' => true,

    // Initial view: 'grid', 'list', or 'auto' (grid if >80% of files are images,
    // else list). The visitor can still toggle; this only sets the default.
    'default_view' => 'auto',

    // Noun for the UI ("3 items", "No items match"). Set per-folder, e.g. 'recipe'.
    'noun' => 'item',
];

// The directory being listed. Use the REQUEST path, NOT __DIR__: __DIR__/__FILE__
// resolve symlinks to the real file, so a script symlinked into a content folder
// from elsewhere (e.g. a git checkout) would see the git folder. The path as
// requested — dirname(SCRIPT_FILENAME) — is the actual content folder.
$BASE_DIR = !empty($_SERVER['SCRIPT_FILENAME'])
    ? dirname($_SERVER['SCRIPT_FILENAME'])
    : __DIR__;

// Config: shared defaults live next to the real script (__DIR__, synced in git);
// an optional per-folder config.php in the content folder overrides them.
foreach (array_unique([__DIR__ . '/config.php', $BASE_DIR . '/config.php']) as $__cfg_file) {
    if (is_file($__cfg_file)) {
        $__over = include $__cfg_file;
        if (is_array($__over)) {
            $CONFIG = array_merge($CONFIG, $__over);
        }
    }
}

$SELF = basename(__FILE__);

// Serve a small, browser-cached JPEG thumbnail for an image, then stop. Keeps
// old devices from downloading multi-MB photos just to show a 54-150px preview.
if (isset($_GET['thumb'])) {
    serve_thumb($BASE_DIR, (string) $_GET['thumb'], 240);
    exit;
}

// ------------------------------------------------------------- data source ---
/**
 * Returns a list of entries: [ ['name'=>..., 'type'=>'file'|'directory',
 * 'size'=>int|null, 'mtime'=>int(epoch)], ... ]
 */
function get_entries(array $cfg, string $listDir, bool $allowJson): array
{
    // 1) Try the nginx autoindex JSON, if configured (root only).
    if ($allowJson && !empty($cfg['json_url'])) {
        $ctx = stream_context_create(['http' => ['timeout' => 4], 'https' => ['timeout' => 4]]);
        $json = @file_get_contents($cfg['json_url'], false, $ctx);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $out = [];
                foreach ($data as $row) {
                    if (!isset($row['name'])) {
                        continue;
                    }
                    $out[] = [
                        'name'  => $row['name'],
                        'type'  => ($row['type'] ?? 'file') === 'directory' ? 'directory' : 'file',
                        'size'  => isset($row['size']) ? (int) $row['size'] : null,
                        'mtime' => isset($row['mtime']) ? (int) strtotime($row['mtime']) : 0,
                    ];
                }
                return $out;
            }
        }
    }

    // 2) Fallback: read this directory straight off disk.
    $out = [];
    foreach (scandir($listDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $listDir . '/' . $name;
        $isDir = is_dir($path);
        $out[] = [
            'name'  => $name,
            'type'  => $isDir ? 'directory' : 'file',
            'size'  => $isDir ? null : (int) @filesize($path),
            'mtime' => (int) @filemtime($path),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- helpers ----
function ext_of(string $name): string
{
    $p = strrpos($name, '.');
    return $p === false ? '' : strtolower(substr($name, $p + 1));
}

function is_image_ext(string $ext): bool
{
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/** Percent-encode each segment of a relative path but keep the slashes. */
function url_path(string $rel): string
{
    return implode('/', array_map('rawurlencode', explode('/', $rel)));
}

/**
 * Sanitize a requested sub-path and confirm it's a real directory INSIDE
 * $baseDir. Returns a clean relative path ('' for the base, or on any rejection
 * — traversal, missing, escaping the base). This is the only guard between a
 * URL and the filesystem, so it must reject '..' and anything realpath() places
 * outside the base.
 */
function safe_subpath(string $baseDir, string $raw): string
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $raw)) as $p) {
        if ($p === '' || $p === '.') {
            continue;
        }
        if ($p === '..') {
            return '';
        }
        $parts[] = $p;
    }
    if (!$parts) {
        return '';
    }
    $rel  = implode('/', $parts);
    $base = realpath($baseDir);
    $full = $base !== false ? realpath($base . '/' . $rel) : false;
    if ($full === false || !is_dir($full)) {
        return '';
    }
    if ($full !== $base && strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) {
        return '';   // escaped the base directory
    }
    return $rel;
}

/** Map an extension to one of our inline icon keys. */
function icon_key_for(string $ext, string $type): string
{
    if ($type === 'directory') {
        return 'folder';
    }
    switch ($ext) {
        case 'pdf':
            return 'pdf';
        case 'doc':
        case 'docx':
        case 'odt':
            return 'doc';
        case 'txt':
        case 'rtf':
            return 'text';
        case 'md':
        case 'markdown':
        case 'mdown':
        case 'mkd':
            return 'markdown';
        case 'epub':
        case 'mobi':
        case 'azw':
        case 'azw3':
        case 'fb2':
            return 'ebook';
        case 'mp3':
        case 'm4a':
        case 'aac':
        case 'flac':
        case 'wav':
        case 'ogg':
        case 'oga':
        case 'wma':
            return 'audio';
        case 'mp4':
        case 'm4v':
        case 'mkv':
        case 'avi':
        case 'mov':
        case 'webm':
        case 'wmv':
        case 'mpg':
        case 'mpeg':
            return 'video';
        case 'xls':
        case 'xlsx':
        case 'csv':
        case 'ods':
            return 'spreadsheet';
        case 'ppt':
        case 'pptx':
        case 'odp':
            return 'presentation';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'webp':
        case 'svg':
            return 'img';
        case 'exe':
        case 'msi':
        case 'ipk':
        case 'ipkg':
        case 'apk':
        case 'deb':
        case 'rpm':
        case 'pkg':
        case 'dmg':
            return 'package';
        default:
            return 'unknown';
    }
}

/** Turn a filename into a friendly display title. */
function prettify(string $name, array $cfg): string
{
    // Strip extension.
    $n = preg_replace('/\.[^.\/]+$/', '', $name);
    // Separators -> spaces.
    $n = str_replace(['-', '_'], ' ', $n);
    // Collapse whitespace.
    $n = trim(preg_replace('/\s+/', ' ', $n));
    // Optionally drop a trailing "recipe".
    if (!empty($cfg['strip_recipe_word'])) {
        $n = preg_replace('/\s+recipe$/i', '', $n);
    }
    if ($n === '') {
        $n = $name;
    }
    // Title-case (unicode aware).
    if (function_exists('mb_convert_case')) {
        $n = mb_convert_case($n, MB_CASE_TITLE, 'UTF-8');
    } else {
        $n = ucwords($n);
    }
    return $n;
}

function human_size(?int $bytes): string
{
    if ($bytes === null) {
        return '';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    $val = $bytes;
    do {
        $val /= 1024;
        $i++;
    } while ($val >= 1024 && $i < count($units) - 1);
    return round($val, $val < 10 ? 1 : 0) . ' ' . $units[$i];
}

/**
 * Stream a downscaled JPEG thumbnail of an image in this directory. Generated
 * in memory (no disk writes, so no special permissions needed) and cached hard
 * by the browser; the ?v=mtime in the URL busts that cache when the file changes.
 * Falls back to redirecting to the original if GD can't handle it.
 */
function serve_thumb(string $baseDir, string $rel, int $max): void
{
    // Sandbox the relative path to $baseDir (same rules as safe_subpath).
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $rel)) as $p) {
        if ($p === '' || $p === '.') { continue; }
        if ($p === '..') { $parts = null; break; }
        $parts[] = $p;
    }
    $safeRel = $parts ? implode('/', $parts) : '';
    $base = realpath($baseDir);
    $path = ($parts && $base !== false) ? realpath($base . '/' . $safeRel) : false;
    $inside = $path !== false && $base !== false
        && ($path === $base || strpos($path, $base . DIRECTORY_SEPARATOR) === 0);
    $ext = $path !== false ? ext_of($path) : '';
    $gd  = function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');

    $src = null;
    if ($gd && $inside && is_file($path)) {
        if ($ext === 'png') {
            $src = @imagecreatefrompng($path);
        } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $src = @imagecreatefromwebp($path);
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $src = @imagecreatefromjpeg($path);
        }
    }

    if (!$src) {                              // can't thumbnail -> original file
        header('Location: ' . url_path($safeRel));
        return;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, $max / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($tw, $th);
    $white = imagecolorallocate($dst, 255, 255, 255);   // flatten any transparency
    imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000');
    imagejpeg($dst, null, 72);
}

/**
 * File-type icons as base64 PNG data URIs (100x100 RGBA), from the old
 * directory-listing project. PNG keeps them rendering on older devices/browsers
 * that choke on inline SVG. Returns a ready-to-use `src` value.
 */
function icon_data(): array
{
    static $icons = null;
    if ($icons === null) {
        $pre = 'data:image/png;base64,';
        $icons = [
            'package' => $pre . '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',
            'markdown' => $pre . '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',
            'ebook' => $pre . 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAACXBIWXMAAAOxAAADsQH1g+1JAAAFGmlUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSLvu78iIGlkPSJXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQiPz4gPHg6eG1wbWV0YSB4bWxuczp4PSJhZG9iZTpuczptZXRhLyIgeDp4bXB0az0iQWRvYmUgWE1QIENvcmUgNi4wLWMwMDIgNzkuMTY0NDYwLCAyMDIwLzA1LzEyLTE2OjA0OjE3ICAgICAgICAiPiA8cmRmOlJERiB4bWxuczpyZGY9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMiPiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtbG5zOmRjPSJodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyIgeG1sbnM6cGhvdG9zaG9wPSJodHRwOi8vbnMuYWRvYmUuY29tL3Bob3Rvc2hvcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RFdnQ9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZUV2ZW50IyIgeG1wOkNyZWF0b3JUb29sPSJBZG9iZSBQaG90b3Nob3AgMjEuMiAoTWFjaW50b3NoKSIgeG1wOkNyZWF0ZURhdGU9IjIwMjQtMTItMTRUMTc6NTY6NDAtMDU6MDAiIHhtcDpNb2RpZnlEYXRlPSIyMDI0LTEyLTE0VDE3OjU3OjM4LTA1OjAwIiB4bXA6TWV0YWRhdGFEYXRlPSIyMDI0LTEyLTE0VDE3OjU3OjM4LTA1OjAwIiBkYzpmb3JtYXQ9ImltYWdlL3BuZyIgcGhvdG9zaG9wOkNvbG9yTW9kZT0iMyIgcGhvdG9zaG9wOklDQ1Byb2ZpbGU9InNSR0IgSUVDNjE5NjYtMi4xIiB4bXBNTTpJbnN0YW5jZUlEPSJ4bXAuaWlkOmRlZjUzYjU3LWY3M2Ut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',
            'audio' => $pre . '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',
            'video' => $pre . 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAHoElEQVR4Xu2deWxURRzHv2+7PWi321JtU9AiKAQhBIkHCD2ASKIYoYFiDBghoYYqIMZIPWLiH0g0cmkEe1Go3HI0UEsDItiESzFCSAiiQJBQTSsi7NnSa58z2y4pTaHzZt/bvmPmn/4z1/t+9vebN7/5zauEMIo8I/NVSLYFkDAsjG700zQ5xYbHRy+RCj/Z1VeTkngHlvOySgFpGm97XbZLedCGZzJj4XYX9BUULiDyzOy3iFV8qEtRw5kUBTIuJw6BQCu87oXSu8t2h9MdT1vFQGRiFsjLPk0GS+cZUNdtQkDoJCkUt2tRpC1FOZDcnAzY5VO6FpZ3cl2B9BEU5UBmTBgFW9Qh3mfWdbvuQPoAigDS9RfSE5AQFNet16T3lh/U+gclgLAA6YDSDNeteVpDEUBYgUQIigCiBEgEoAggSoFoDEUA4QGiIRQBhBeIRlAEkHCAaABFAAkXiMpQBBA1gKgIRQDpCiSun4TJU/tx78ZV2DwKIN3Vz54SB4fT1ldQBJDuyicl2/DspDjY+JmEE2YRQHoyhfSHojByTAxiYxXrc6c7TveleEDZzOH37nAGDopCgoPfVALtjfjj/EtS1U/nWF2gciDTM4cj2lbLOoDl6wXkSdLe4xdZdRBAWJXirSeA8CqnUTsBRCNhebsVQHiV06idAKKRsLzdCiC8ymnUTgDRSFjebgUQXuU0amcoIA8PtmP8RJJLS8Q4f6YFv59r0UiWvuvWMEBoUnPh8v53KdXwdxvKv/Dg7C/NfaegyiMbBkj5vjQkp0T1+PinjjVhAwFz8wa1HWMXQwBJGxCFop1p91X6dpOMyi0+VG33kXC2caEYAsiQYXas3JDKpHJ9XSuKV7rx29lWpvp6q2Q6ICGBT9Y2YeOXHpJfayxzMS0QCqapUcaeTV5U7/Qbxo2ZGkjIWozkxiwBhIKRyeW6E0ca8c1ar67dmGWAhKylyR/Azo0+1FT6IetweTEFkAOVPkzNcyh6Ybp2pRVlqz262+2bAsisnHoMetSOhe8nYeiIGGYw1I0d/b4Rm4q88Lj0YS6mAPLyxPrgGkHLlGnxmLMgEc4k9uwPvy+Ab8u9OLi38U4/zFRVrmg6IFSfBIeEeYsTMfmFBPIpD3bFrl5uRelKFy5daGNvpHJNUwIJaTRshB0FhckYPDSaWTa60Nce9GNzkQ8+T+TdmKmBUAoSyVx6MS8Br+Q7EJ/Abi4Uxvb1XvzwXWTdmOmBhEzDSXJw85ckYsJz8UFIrOXPiy0o/tyNK5ci48YsAyQEYOSYaLxZmIQBGexujEaPf6zxY0uxF35f59sDK1GF9SwHhOpDM9Vz5zgwa64DsXHs5kJfjbeVeXFkf6NCmdmrWxJISB76aYzX33FibLaySzeXL7SgiLixa1fUd2OWBhICM2ZsDAqWJiE13c78U25vBw5X+7G1xBuMKqtVBJBOJe3RUtCF5c5OQHQMuxtz32oPQqk90KQKEwGkm4yp6TYs+iAZo56MVSTwxfPNwbexuqvEdMIopgBCY1lql/Hkmtr8t53o/0DPiRX3Gq9slRuHyN6Ftwgg91EuhrguuqHMna0skpyf+w/cnEfHAgjDT5km6BUsdWLEaDY3VrzCRV6N+dYUUwDpGu1l0FdxlQEZUcHQPiuQinVu1Ozic1umAKLFGkKpdbisBOKyEhVBXDznOhr+4lvcBZB7SE1TV+mmUfGivpos6lV81kGnYgogarqstPSo4HrxxNg4RVZBd+9ff+YSr71UNTWAdGwMqXtyKNoYetwkvlWqXnxLWAgByhM6CR1kbVqnbgTY0kBocDGfrBPjFAYXtTzqtSSQYPidxKzy5iaCfmKJtdCcrl0VPuzfTXK61Isn3jW85YDwHFBRxSKVvG0ZIPQIdz45ws1UeIRLb2mVkusN58gVukgU0wPhTXJoaZaxb5sveAmInn1EqpgaCE8aEBX+zM+3Ub7Gg+sNESTRSdyUQHgT5W7eaEfpKhdOn4yMe+rJ6kwHhCeVtL1NRs0eP0kn9aGlRaPXJ0afZxogGUOUJ1tTjehJ39pP3aivi7x7Mq2F8FxHoAdIW0s8qp2FMxpAr9VMYSG9PmWXCjTp7fB+kvRGriComS2iZA73q2spIDTkUUQispFKC+WBZAkgerr/0RskUwOh8abjhxtRQS566uWGlGWBGOkqdFdIprOQ5tvkYwGbjfvNE0MASUySUFHd+79u/fVEE9av8eK/f/Wxp+jNPRl2H0In/tW2VAzM6DkZmgIoI8kFp08a/7tZhrAQCuSRx+z4aEUKUlLvTu2s2uElF2l8PD9GXbYxDBCqXgz57wPP58bj6azY4FsTvQOol5CHWnQNBUSth9ZzPwKIzugIIAKIzhTQ2XSEhQggOlNAZ9MRFiKA6EwBnU1HWIgAojMFdDYdYSECiM4U0Nl0hIVYDUhuTgbs8imdPbZ+p9MmjZOqjtaxTpD9cktnjyTNQEJe1gXyx8k6iIXrNaDy2FNEZOZ0VsVAqLjyjMw3yEfDPraw0GyPHggsk/aeKGGr3FGLC0gQysysEvKtw+lKBrNWXblaqjxeoPSZuYEEoeRlzSZ/FhCuw5UObNr6Mi5BkksIjB08z/g/OwhjsM0/KdkAAAAASUVORK5CYII=',
            'pdf' => $pre . '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',
            'doc' => $pre . 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAEUElEQVR4Xu2dz0sUYRjH3xnHXF1dDaPIIiyUkCXcOnTrFF0LgiKIcCWCOkp1kDp3Kc91CTXooP0DQfQPROQalUcj0C4Jupm7uetO74a2IubM+8z7zj4z+53rvs+P9/t5n3dm3nlnxxIBjuzH/C3LFSPSxUAAN2xM25qEOJxwrj3sa5uqV1IWNXB2Nj8tja9Q7TnaJZsscUJSWauIukEhARmezY9KQR9xFDVITlUgfckm4bpifW1D3HjQ3zYdxB/FlgYkl18QluihBORsswWkmmO9oCgDyc4Uei27NM9ZWGpu24HUC4o6kM+rGatcmaF2mrPdTiD1gAIg20bIbkC2oBQr9qXRvsRr0wMKQHwA+QtFiEJxw75sGgqA+AQSFhQAUQASBhQAUQRiGgqAEICYhAIgRCCmoABIACAmoABIQCC6oQCIBiA6oQDINiCOVCPd4ZBvxnXcPALIDvlPyuX3hFyGpx5BoShHzsZ4cbEKodUWoj/pCEtZmRrCIFCUw8YdSFXWTjl3HU3YwrGV5flHhQpFOWIjANlStUuCaQkG5df339a5F5mk78cVykCGcj/TtuV+os6xDWfnWOnxdMcXv/0GEL9KUdsBCFU5Q3YAYkhYqlsAoSpnyA5ADAlLdQsgVOUM2QGIIWGpbgGkplxK3tjdOdZKlXJPuzdL6+LDStnbN4DUNOputsTjgXZv0QgtXi4WxdsfJW9LAAEQ71FSpxaokDoJ/7+wAMIMCIt0cA5hgaGWBIAACDMFmKWDCgGQXRWovs16qkO+ZxzT4728S18oVrx7x6VCzh9oFtd7Et4JR7TFs28F8W45QksnALI50lAh4ZQcKiQcnX1HiRyQ/XKl9VBLfE/qi8UNkS/L7XBeB5cpyyvPhvkdQJihBhAAYaYAs3RQIQCyqwID7Y64eHAfM3X0pRO5TQ5nuxxx29COD32y0j1FbpMDgDBbOgEQZkBwDmEGhD47x8wSl73MgAIIgDBTgFk6qBAAYaYAs3S4VAh2nTC77MUmBwAJda6K3DN1VAgqBBWylwLYdcKsQkIdrpyDcbns5axRqLkBSKhyewcDEG+NQm0BIKHK7R2MCxA8wmV2lQUgAOI9fWhsgW1AGsXU4SpyQLDrhNmUpWMUxsIHl6usWIipoxMAokNFjT4ARKOYOlwBiA4VNfrgAuRMpyMudJt5P+Sp/BcFX2/AatSV7IoLEJOPcO/PrYqlko9XkskqajQEEI1i6nAFIDpU1OgDQDSKqcMVFyA6+hILHwDCDCOAAAgzBZilgwoBEGYKMEsHFQIgzBRglo7pCsnOFHotuzTPrNts03ErzccnTrd+9Zug8pc+q46HcyvL8nPKnX6DNGw7VyyOZ1JHVPpPAjKUy9+V3+x9ohKoEdtWXHFvMpMaU+k7CchmlUzJKrmqEqyR2sqHA68mBlPK+pCBVMUdms3ftFwxIj8Gn24ksT36Oif/EX5scjD1nKLJH52NfZIugt/IAAAAAElFTkSuQmCC',
            'text' => $pre . 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAFlUlEQVR4Xu2dW2hcVRSG/zNNyc0miqXSamqSUkw6jeiLd0ERn7xBxaoPonhBKIhURfBFwQdftIgPIgi+iA9aLyBe8AZKH1RQVHIxFGvTRhPbKjYkaRPSaY9rd1KTzkzCPmufndln5t+Ql7DXOmv/31rrnOTM3hPBYcSDeAQxdoqLXgc34ZjmzgNa+u6Juva8U62gIu2F437sRoS7tPZB2q06X4BcCcTTVYOiAhIP4BkR9IUgRXUJ6jSQa8RDPIdTk/dF3Xt2u7jT2OqA9GNMqmOD5oJB2/wPxERZHSiJgcTD6EQBI0ELqw3uLCDVgZIcyBAuwyn8rF1z0HZlQFYeCoEszpCKQOahxNN3RF3ffOY7oQjECshpKDPy9LXNNxQCsQayMlAIJBEQ/1AIJDEQv1AIRAXEHxQCUQPxA4VAnICkD4VAnIGkC4VAUgGSHhQCWQwkagLOudnhj3H3Px4JpFT+1huA3JqqQSGQUulz7UDrdfLbXFWgEEgl2RvWA019QNS44lAIZDnJGy6UQml1gXIMhd+uj/L2rys0QPLyPmTQIcr6Ms0hL0B+tV00gdgqpZ1HIFrlPNkRiCdhtW4JRKucJzsC8SSs1i2BaJXzZEcgnoTVuiUQrXKe7AjEk7BatwSiVc6THYF4ElbrNlNA1j4u/7yTTTIhjYm3gLl96UWUKSA9h4CGC9JbfBqeRu8EJj9Iw1PRR6aA5I/JO4eW9Bafhqc/7wcm3kzDUwaBbI3LFz4lHzCf/hiYHVpelHXPypu9G8vnzMqbgb8eq2xrXjitlncc5mfNrUDzFeXzxncA/75Wh0BWyavS3omFhZ84CIw9LDC+shOj422g/e7yuce/BfZfa+ejaStwkfhpyi/MP/w08PeLdvY2szLTslZ3AJeMFpdUOCIiXi030/02SyzOWRLId+LL7BO0HLlmYONH8mmTm4oGR56Xn+csjS2mZQZI4xZg83xbMhltMjvJSKNCzlwv1wZs+hFo3Az88zJw6IkkkSw/NzNAzPbj7u+BqU+Bg7ckFyBNIObqbbdLpXwo94/XgfFHk8ezlEVmgJgPpHV+IfcNeao5qniqSatlLRZyy6QkyCfAH/fWIZC2bZKR7wN75YnnxHhyAdKuEBNB19fAySlgVKolrZGZCjn3AWD9K8CwPG1pRoecftG+vdzyuLRB84CgGRtelfuI3NtGKjxOa/wZm8wA0S7wjJ0PIK4xVbInEIcKIRAHBVghRfHiIYTxyUUCIRBVPfMewnuIKnGcjdiy2LJUScSWxZalShxnI7YstixVErFlsWWpEsfZiC2LLUuVRGxZbFmqxHE2Ystiy1IlEVsWW5YqcZyN2LLYslRJxJbFlqVKHGcjtiy2LFUSsWWxZakSx9mILYstS5VEbFlsWarEcTZiy2LLUiURWxZblipxnI3YstiyVEnElsWWpUocZyO2LLYsVRKxZbFlqRLH2Yj9e7KFQDZolo6ZH4DfK2xVc76g0kDcVcrHseiL7BEvH7C/AvsuV6nkwqxsg3bJ1reWqcgXNXsW9nR6UVbqsGyAdp3WP64r6Og0mDmpyiZaleU0QWYaqlNW3qdU0V+DIVr0USsEHPSMzdH5VPOh8qTl7QLahxbfKxsBOymaPmg5qwmBf8+yKpI09ciO/TXYlWZL+MP2p7O6VpyyU/EPTgTZ0alL/uZdlSuF3TmqPo9gt3E62j2dR3xTiUgcSNe+lVTHmVUX09KZbFdF0DkOKdpQV0z3rJgYW2NNZAZLWTdcTHDgD4jRZFHsK7GUAAAAASUVORK5CYII=',
            'spreadsheet' => $pre . 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAEu0lEQVR4Xu2dTUhUURTH7503jtP4UampfRgFlqRjKVmR0AdE2TdBtIiIsvzog0JaBG1aBG2zdlpCixZBQS0qdGrRpk2EVGQUWUEUpIs08iPNsdubamQQm7n3zLx5tzf/t3Fzzrn3/n/vvHPvmTcOZ3FcpYH6fS4hGhjji+IIo41rnifbVZlTfLK54ugNuybFqQOXtde1cs53UP119CtIn+lal780vX9soNEuKCQg/kD9CSbYGR1FjWdOISAbCiq840KM9f8YPHahsvFmPPEovupABONlgbpOznghZUCdfcJAQnMMQekb+3Y82ZmiDMQfqC1iwv1YZ2Gpc4sEYhcUZSBL7h72G27XfeqidfabDMQOKAAScYdMBWQCSnBgf/OyIx1W31AAIgHkD5Sfo33BwQNWQwEQSSDJggIgCkCSAQVAFIFYDQVACECshAIgRCBWQQGQOIBYAQVA4gSSaCgAkgAgiYQCIBFAfEY63zW3ehr1NJ6IwyOATFJ/6+yV3hlpGS67oADIJOVzPJmuTYVVXhdTlmYiUjyZojyqk7u9YUWLfPlG1cxFnmmGR1mfcAwqFOUBUwFIWNSFGYVGlttMFuJlQhl+PvRx++uNl1/IhlAGUtJ+qCSNGw9lB0h1u+A4W/9625U3sjoAiKxSRDsAIQpnlRuAWKUsMS6AEIWzyg1ArFKWGBdAiMJZ5QYgVilLjAsgROGscgMQq5QlxtUayLVVp3MrZhR7iGuz1a080PCZMgEAoagm4eNoIO09T4Zvf3o0IqEDO7igxledV+rt7H8z2vru3pCMT6JsSrPnu5sW784OxXM0kLb3HYOXum8NyAh3vrx2+s45q30Peju/n3rW+lXGJ1E21bmlntaqplwAiVAUQGLcXvG038NFHRnyb5GT2n4HkNgPUwCJohFqyBTioIaghkwogAxBhqi/fIRdVuzCHGmB1omaXtLWjj6pS6ugkaGjgTz7+vZHZ1/3qIzea2aVexdnzUt7P9Qz9rD3qVT/SyaujM0cX56xpXCFD60TtE5k7pc/Nijq8lqFLP+Loo5eFnpZarf1X2scDHEwxMEwWuogQ5AhyBBkiPkaEHZZmu2ySFsem50c3TqxWVvS8I4Ggl6WZo8s1BAAIT2mcA7BOQTnEJxDcA6J+vjEi3JR5EENQQ1BDUENQQ3Rr4aQDgU2Ozm6dWKztqThHQ0EvSy0TkhZgW0vtr3Y9mLbi22vfttefB6Coo6iPpUC+Fp07PsC3V50e/FeVrQ8sSVDekf6xz+PfBmPncCMzfcVGDmeLMP8seCfH4Z7gjI+ibLJdGfw4szZaaF4jm6dJEqwZMZxJJA9RWt9c7255H9un0wAk8e62H17kDK+1t+goizof/cBEM0IAgiAaKaAZtNBhgCIZgpoNh1kCIBopoBm00GGAIhmCmg2HWQIgGimgGbTQYakGhB/oLaICfdjzdat73R4cFVXzdWPshNU/oCKCcb9gbpXjPHfP+OA698KCCZ6Xta0LTfffhOyOqkDMSP7O+qPmH/Oyg6SwnbnujZfaVFZPwlIaAB/oL7F5L5TZbBUshVC3Hm5pa1Rdc1kIKGBytrr9nLOGszHV4nqwM61F91CsBYTxnXKGn8Bf+p/vyocZOkAAAAASUVORK5CYII=',
            'presentation' => $pre . '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',
            'img' => $pre . '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',
            'folder' => $pre . 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAD90lEQVR4Xu2dzUuUURTG73WUkMoKBN2MYyGFLUwICpI2bdIgqo0a9DEGUbtatqmV/0O0ciIlW2WLvnYR7dqoMaNBYmOLaNEHw6hD5dyO21bzvPRMF+cRws05z73n9+vkq8ibd/qIioCP6ja6jJOQyP4SSIiEREYgsutoQyQkMgKRXUcbIiGREYjsOtoQCYmMQGTX0YZISGQEIruONkRCIiMQ2XW0IRISGYHIrpNoQw48Kg6kgrvhnD9q83S54FaDd2sWtu5CWAv22Tu/ap83ap7Xux7v/a3CSHqy5p4tWAgL2T9V3Nec8ktEFlcKo10TxPyoo2EhvQ9X7nrvrrOmCiFUmlLucH44U2CdEXMuLOTg9MoLG+gkeai3tiVHyGdEGZ9EyDObZIg9jW3K5HqlevXj2N4K+6yY8mEhvdPFp/YF+1SdhvhQ9WHcVZuKdTrvnxyTSrn5/HD6W5Kw2IUkmen/99hTp/P+WmE0PYVeRkJQYkB9tcn3LQ6n3wEt+K8B1fmfLGSW6Grte7PbCyNd48jFtCEILbw2Z0+LY0gbLMQee+vylIUMEWutPSk+WDifuYTcT0IQWmDt5qO7CbmItEkIQgusrauQ7a3NbveOFudCFbzm1i7fsJ+ollY33Frltw0apgqjmQvIxIk2pLuzdahjzzbknIar/VH+5d5/KvOFnHj5ZTbT2Xqo4QgnGHj58/qbV4Mdx5FWeENOv/663L6rpRs5pFFrv5d/Ls0MtPcg88NCxuZKM3bAGeSQBq59MnGo7Swyv4QgtPBaCcGZUTskhIoXD5cQnBm1Q0KoePFwCcGZUTskhIoXD5cQnBm1Q0KoePFwCcGZUTskhIoXD5cQnBm1Q0KoePFwCcGZUTskhIoXD5cQnBm1Q0KoePFwCcGZUTskhIoXD5cQnBm1Q0KoePFwCcGZUTskhIoXD5cQnBm1Q0KoePFwCcGZUTskhIoXD5cQnBm1g280O1t6bO9+h16oQh054vAQ3Eyuv+0ccsUkL5/J2QGXkUMatdaE3DMh0H9cAAvJzpUHvas+b1TItc5t71ssBdd07H7/znytPZt1sJDNpux8Keur7qZ1681yf9G2rcgbl4L9uZPra1tEZCQWgh6i+toJJNqQ2uNViRKQEJQYuV5CyIDReAlBiZHrJYQMGI2XEJQYuV5CyIDReAlBiZHrJYQMGI2XEJQYuV5CyIDReAlBiZHr/wD7KJx0li0MYwAAAABJRU5ErkJggg==',
            'unknown' => $pre . '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',
        ];
    }
    return $icons;
}

/**
 * Emit one CSS rule per icon type. Each base64 PNG then appears ONCE in the page
 * (in <style>) instead of being duplicated inside all 65 cards — the single
 * biggest HTML-size win for slow devices.
 */
function icon_css(): string
{
    $out = '';
    foreach (icon_data() as $key => $uri) {
        $out .= '.ic-' . $key . '{background-image:url(' . $uri . ')}' . "\n";
    }
    return $out;
}

// ------------------------------------------------------------ build model ----
// Sub-directory navigation: ?path=Album/Sub lists a folder below the base.
$subpath  = isset($_GET['path']) ? safe_subpath($BASE_DIR, (string) $_GET['path']) : '';
$LIST_DIR = $subpath === '' ? $BASE_DIR : $BASE_DIR . '/' . $subpath;

$hidden = array_map('strval', $CONFIG['hidden'] ?? []);

$entries = get_entries($CONFIG, $LIST_DIR, $subpath === '');
$items = [];
foreach ($entries as $e) {
    $name = $e['name'];
    if ($name === '' || $name[0] === '.') {
        continue; // dotfiles
    }
    if ($name === $SELF || $name === 'config.php' || in_array($name, $hidden, true)) {
        continue;
    }
    $ext = $e['type'] === 'directory' ? '' : ext_of($name);
    $items[] = [
        'name'    => $name,
        'title'   => $e['type'] === 'directory' ? $name : prettify($name, $CONFIG),
        'type'    => $e['type'],
        'ext'     => $ext,
        'iconkey' => icon_key_for($ext, $e['type']),
        'isimg'   => is_image_ext($ext),
        'size'    => $e['size'],
        'mtime'   => $e['mtime'],
    ];
}

// Sort alphabetically by title for the initial (server-rendered) order.
usort($items, function ($a, $b) {
    if ($a['type'] !== $b['type']) {
        return $a['type'] === 'directory' ? -1 : 1;   // folders first
    }
    return strcasecmp($a['title'], $b['title']);
});

// Default view: config 'default_view' may force 'grid' or 'list'; 'auto' (the
// default) picks grid when >80% of the files are images, otherwise list.
$defaultView = strtolower($CONFIG['default_view'] ?? 'auto');
if ($defaultView !== 'grid' && $defaultView !== 'list') {
    $files = 0;
    $imgs  = 0;
    foreach ($items as $it) {
        if ($it['type'] === 'file') {
            $files++;
            if ($it['isimg']) {
                $imgs++;
            }
        }
    }
    $defaultView = ($files > 0 && $imgs / $files > 0.8) ? 'grid' : 'list';
}

$title = ($CONFIG['title'] !== null && $CONFIG['title'] !== '')
    ? $CONFIG['title']
    : ucwords(str_replace(['-', '_'], ' ', basename($BASE_DIR)));
$noun = (string) ($CONFIG['noun'] ?? 'item');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= htmlspecialchars($subpath === '' ? $title : $title . ' / ' . str_replace('/', ' / ', $subpath)) ?></title>
<style>
    /* Deliberately old-WebKit-safe (circa 2012: HP webOS, iOS 5/6, Android 4).
       No CSS variables, grid, flexbox, gap, object-fit, calc/clamp, or media
       color-scheme. inline-block cards + -webkit-background-size thumbnails. */
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body {
        margin: 0;
        background: #f4f7fb;
        color: #26313d;
        font-family: -apple-system, "Helvetica Neue", Helvetica, Arial, sans-serif;
        line-height: 1.4;
    }
    header, footer { display: block; }
    a { text-decoration: none; }

    header {
        padding: 24px 16px 16px;
        text-align: center;
        background: #e6eef7;
        border-bottom: 1px solid #d4e0ec;
    }
    header h1 { margin: 0; font-size: 26px; font-weight: bold; color: #1565c0; }
    header p { margin: 5px 0 0; color: #6b7a8d; font-size: 14px; }

    .toolbar {
        text-align: center;
        padding: 11px 8px;
        background: #eef3f9;
        border-bottom: 1px solid #d4e0ec;
    }
    .search {
        display: inline-block;
        vertical-align: middle;
        background: #fff;
        border: 1px solid #cdd9e6;
        -webkit-border-radius: 20px;
        border-radius: 20px;
        padding: 6px 14px;
        margin: 4px;
    }
    .search input {
        border: 0;
        outline: none;
        background: transparent;
        font-size: 15px;
        color: #26313d;
        width: 190px;
        max-width: 60%;
        -webkit-appearance: none;
    }
    /* Flat segmented control. No border-radius/overflow clipping — old WebKit
       (TouchPad) doesn't clip square children to a rounded parent, which made
       the button corners burst out of the pill. */
    .sort, .view {
        display: inline-block;
        vertical-align: middle;
        border: 1px solid #cdd9e6;
        margin: 4px;
    }
    .sort button, .view button {
        -webkit-appearance: none;
        appearance: none;
        margin: 0;
        border: 0;
        border-left: 1px solid #cdd9e6;
        background: #fff;
        color: #6b7a8d;
        font-size: 14px;
        line-height: 1.2;
        padding: 7px 15px;
        cursor: pointer;
    }
    .sort button:first-child, .view button:first-child { border-left: 0; }
    .sort button.active, .view button.active { background: #1565c0; color: #fff; font-weight: bold; }

    .wrap { max-width: 1000px; margin: 0 auto; padding: 16px 8px 40px; }
    .count { text-align: center; color: #6b7a8d; font-size: 13px; margin: 0 0 12px; }

    /* --- shared card chrome (both views) --- */
    .card {
        background: #fff;
        border: 1px solid #d4e0ec;
        -webkit-border-radius: 8px;
        border-radius: 8px;
        -webkit-box-shadow: 0 1px 3px rgba(30,55,90,0.18);
        box-shadow: 0 1px 3px rgba(30,55,90,0.18);
        overflow: hidden;
        color: #26313d;
    }
    .card:hover { border-color: #1565c0; }
    .thumb {
        display: block;
        background-color: #e2eaf3;
        background-repeat: no-repeat;
        background-position: 50% 50%;
        -webkit-background-size: cover;
        background-size: cover;
        text-align: center;
        overflow: hidden;
    }
    .name  { display: block; font-weight: bold; word-wrap: break-word; }
    .sub   { display: block; font-size: 11px; color: #6b7a8d; margin-top: 3px; }
    .badge { text-transform: uppercase; font-weight: bold; color: #1976d2; margin-right: 5px; }

    /* --- GRID VIEW --- */
    .view-grid { text-align: center; }
    .view-grid .card {
        display: inline-block;
        vertical-align: top;
        width: 150px;
        margin: 7px;
        text-align: left;
    }
    .view-grid .thumb { height: 112px; }
    .view-grid .thumb.ico { -webkit-background-size: 60px 60px; background-size: 60px 60px; }
    .view-grid .meta { padding: 9px 10px 11px; }
    .view-grid .name { font-size: 14px; }

    /* --- LIST VIEW (table layout so the thumb cell fills the full row height,
       even when the name wraps — no white sliver under the thumbnail) --- */
    .view-list { max-width: 620px; margin: 0 auto; }
    .view-list .card { display: table; width: 100%; margin: 0 0 8px 0; }
    .view-list .thumb { display: table-cell; width: 54px; height: 54px; vertical-align: middle; }
    .view-list .thumb.ico { -webkit-background-size: 34px 34px; background-size: 34px 34px; }
    .view-list .meta { display: table-cell; vertical-align: middle; padding: 9px 12px; }
    .view-list .name { font-size: 15px; }

    .empty { text-align: center; color: #6b7a8d; padding: 36px 16px; display: none; }
    footer { text-align: center; color: #6b7a8d; font-size: 12px; padding: 20px 16px 32px; }

    .crumbs { max-width: 1000px; margin: 0 auto; padding: 2px 10px 0; font-size: 13px; color: #6b7a8d; }
    .crumbs a { color: #1565c0; }
    .crumbs .sep { margin: 0 4px; color: #9fb0c2; }
    .crumbs .here { color: #26313d; font-weight: bold; }

    /* file-type icons — each base64 PNG defined ONCE here, not once per card */
<?= icon_css() ?>
</style>
</head>
<body>
<header>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p>Tap a card to open</p>
</header>

<div class="toolbar">
    <label class="search">
        <input id="q" type="search" placeholder="Search <?= htmlspecialchars($noun) ?>s&hellip;" autocomplete="off">
    </label>
    <div class="view" role="group" aria-label="View">
        <button data-view="grid" type="button"<?= $defaultView === 'grid' ? ' class="active"' : '' ?>>Grid</button>
        <button data-view="list" type="button"<?= $defaultView === 'list' ? ' class="active"' : '' ?>>List</button>
    </div>
    <div class="sort" role="group" aria-label="Sort">
        <button data-sort="name" class="active" type="button">A&ndash;Z</button>
        <button data-sort="new" type="button">Newest</button>
    </div>
</div>

<div class="wrap">
    <?php if ($subpath !== ''): ?>
    <div class="crumbs">
        <a href="<?= htmlspecialchars($SELF) ?>"><?= htmlspecialchars($title) ?></a>
        <?php $acc = ''; $segs = explode('/', $subpath); $lastSeg = count($segs) - 1;
        foreach ($segs as $ci => $seg):
            $acc = $acc === '' ? $seg : $acc . '/' . $seg; ?>
            <span class="sep">&rsaquo;</span>
            <?php if ($ci === $lastSeg): ?>
                <span class="here"><?= htmlspecialchars($seg) ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($SELF . '?path=' . url_path($acc)) ?>"><?= htmlspecialchars($seg) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="count" id="count"></p>
    <div id="grid" class="view-<?= htmlspecialchars($defaultView) ?>">
    <?php foreach ($items as $it):
        $rel = $subpath === '' ? $it['name'] : $subpath . '/' . $it['name'];
        if ($it['type'] === 'directory') {
            $href = $SELF . '?path=' . url_path($rel);   // navigate into the folder
            $target = '';
            $sub = 'Folder';
        } else {
            $href = url_path($rel);                       // relative URL to the file
            // Images open in a new tab to view; everything else gets a `download`
            // hint so the browser saves it instead of rendering inline.
            $target = in_array($it['ext'], $CONFIG['newtab'] ?? [], true)
                ? ' target="_blank" rel="noopener"'
                : ' download';
            $sub = strtoupper($it['ext'] ?: 'file');
        }
    ?>
        <a class="card" href="<?= htmlspecialchars($href) ?>"<?= $target ?>
           data-type="<?= $it['type'] ?>"
           data-name="<?= htmlspecialchars(strtolower($it['title'] . ' ' . $it['name'])) ?>"
           data-mtime="<?= (int) $it['mtime'] ?>">
            <?php if ($it['isimg']): ?>
                <span class="thumb photo" data-bg="<?= htmlspecialchars($SELF . '?thumb=' . url_path($rel) . '&v=' . $it['mtime']) ?>"></span>
            <?php else: ?>
                <span class="thumb ico ic-<?= $it['iconkey'] ?>"></span>
            <?php endif; ?>
            <div class="meta">
                <span class="name"><?= htmlspecialchars($it['title']) ?></span>
                <span class="sub">
                    <span class="badge"><?= htmlspecialchars($sub) ?></span>
                    <?php if ($it['size'] !== null): ?><span><?= htmlspecialchars(human_size($it['size'])) ?></span><?php endif; ?>
                </span>
            </div>
        </a>
    <?php endforeach; ?>
    </div>
    <p class="empty" id="empty">No matches for &ldquo;<span id="term"></span>&rdquo;.</p>
</div>

<footer>
    <?= count($items) ?> <?= htmlspecialchars($noun) ?>s &middot; <?= htmlspecialchars($title) ?>
</footer>

<script>
(function () {
    var grid  = document.getElementById('grid');
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.card'));
    var q     = document.getElementById('q');
    var count = document.getElementById('count');
    var empty = document.getElementById('empty');
    var term  = document.getElementById('term');
    var total = cards.length;
    var NOUN  = <?= json_encode($noun) ?>;
    var photos = Array.prototype.slice.call(grid.querySelectorAll('.photo'));

    function lazyLoad() {
        var vh = window.innerHeight || document.documentElement.clientHeight;
        for (var p = 0; p < photos.length; p++) {
            var el = photos[p];
            var url = el.getAttribute('data-bg');
            if (!url) { continue; }
            if (el.parentNode.style.display === 'none') { continue; }
            if (el.getBoundingClientRect().top < vh + 300) {
                el.style.backgroundImage = "url('" + url + "')";
                el.removeAttribute('data-bg');
            }
        }
    }
    var lazyTimer = null;
    function lazyThrottled() {
        if (lazyTimer) { return; }
        lazyTimer = setTimeout(function () { lazyTimer = null; lazyLoad(); }, 150);
    }

    function applyFilter() {
        var v = q.value.trim().toLowerCase();
        var shown = 0;
        cards.forEach(function (c) {
            var hit = !v || c.getAttribute('data-name').indexOf(v) !== -1;
            c.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        count.textContent = v
            ? shown + ' of ' + total + ' ' + NOUN + 's'
            : total + ' ' + NOUN + 's';
        empty.style.display = shown ? 'none' : 'block';
        term.textContent = v;
        lazyLoad();
    }

    function sortBy(mode) {
        var sorted = cards.slice().sort(function (a, b) {
            var ad = a.getAttribute('data-type') === 'directory' ? 0 : 1;
            var bd = b.getAttribute('data-type') === 'directory' ? 0 : 1;
            if (ad !== bd) { return ad - bd; }              // folders first
            if (mode === 'new') {
                return (+b.getAttribute('data-mtime')) - (+a.getAttribute('data-mtime'));
            }
            return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
        });
        sorted.forEach(function (c) { grid.appendChild(c); });
        lazyLoad();
    }

    var evs = ['input', 'keyup', 'change', 'search'];
    for (var e = 0; e < evs.length; e++) { q.addEventListener(evs[e], applyFilter); }

    var sortBtns = Array.prototype.slice.call(document.querySelectorAll('.sort button'));
    for (var i = 0; i < sortBtns.length; i++) {
        sortBtns[i].addEventListener('click', function () {
            for (var j = 0; j < sortBtns.length; j++) {
                sortBtns[j].className = sortBtns[j].className.replace(/ *\bactive\b/, '');
            }
            this.className += ' active';
            sortBy(this.getAttribute('data-sort'));
        });
    }

    var viewBtns = Array.prototype.slice.call(document.querySelectorAll('.view button'));
    for (var k = 0; k < viewBtns.length; k++) {
        viewBtns[k].addEventListener('click', function () {
            for (var m = 0; m < viewBtns.length; m++) {
                viewBtns[m].className = viewBtns[m].className.replace(/ *\bactive\b/, '');
            }
            this.className += ' active';
            grid.className = 'view-' + this.getAttribute('data-view');
            lazyLoad();
        });
    }

    window.addEventListener('scroll', lazyThrottled);
    window.addEventListener('resize', lazyThrottled);
    document.addEventListener('touchend', lazyThrottled);

    applyFilter();
})();
</script>
</body>
</html>
