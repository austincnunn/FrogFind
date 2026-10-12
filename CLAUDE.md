# CLAUDE.md

## What this is

FrogFind: a search engine + reading proxy for vintage computers (68k Macs, Netscape 1–2, text browsers). It searches via a bundled SearXNG instance (JSON API), re-renders results as HTML 2.0, and routes every clicked link through a Readability-based proxy that strips pages down to basic markup. GPLv3.

Category: plain PHP web app. No framework, no build step, no database, no CI.

## Layout

| File | Role |
|------|------|
| `index.php` | Home page + search. Queries SearXNG's JSON API (`$SEARXNG_URL`, default `http://searxng:8080`), renders 20 results per page (`&page=N`, Previous/Next links) as `/read.php?a=` links with escaped title/snippet. Slices SearXNG's ~80-result pages, fetching more only when needed; `searxng_search()` caches SearXNG answers 10 min in `/tmp` (shared across users, atomic writes, ~1-in-20 sweep of expired files) so pages stay consistent. |
| `read.php` | Article proxy (`?a=<url>`). One `safe_fetch()` GET; non-HTML/text responses ≤ 8 MB are passed through as downloads. HTML is run through `fivefilters/readability.php` 4.x (`parse()` returns an `Article`; read `->title`, `->content`, `->images`), then `simplify_html()` rebuilds it from whitelisted tags with no attributes, `<strong>/<em>` → `<b>/<i>`, `http(s)` links routed to `/read.php?a=`. Don't use `strip_tags()` here: it breaks on `<` inside attributes (Wikipedia's `data-mw`). Lists page images as links to `image.php`. |
| `image.php` | Image viewer page (`?i=<url>`); embeds `image_compressed.php`. |
| `image_compressed.php` | Fetches any GD-readable image via `safe_fetch()`, downscales to max 300 wide (landscape) or 200 tall (portrait), always outputs JPEG (white background under transparency). |
| `about.php` | Static FAQ. |
| `lib.php` | Shared helpers: `clean_str()`, `h()` (HTML escape), `is_public_url()` / `safe_fetch()` (SSRF-guarded remote fetch). |
| `lib_test.php` | CLI-only self-check for `lib.php`. |
| `searxng/` | `settings.yml` (JSON format on, limiter off) baked into a SearXNG image by `searxng/Dockerfile`. Not bind-mounted. |
| `Dockerfile` / `compose.yaml` | `php:8.4-apache` + GD (jpeg/webp); Composer runs in a build stage. FrogFind serves on host port 8180; SearXNG has no published port. |

Only dependency: `fivefilters/readability.php` ^4.0 (Composer), which needs PHP 8.4+. `composer.json` pins `config.platform.php` to 8.4 to match the Docker image. `vendor/` is gitignored; commit `composer.lock` once generated.

## Running locally

```bash
echo "SEARXNG_SECRET=$(openssl rand -hex 32)" > .env   # once; .env is gitignored
docker compose up -d --build
```

Then browse to `http://<this machine's LAN IP>:8180/` from the vintage machine. Without Docker: PHP 8.4+ with GD, `composer install`, `php -S localhost:8000` from the repo root (links are root-absolute, so it must be served at `/`).

## Conventions

- Output must stay renderable on ~1995 browsers: `HTML 2.0` doctype, `<font>`, `<center>`, `<b>`/`<i>`, no CSS, no JS, no HTTPS-only assets. Target is 1-bit color at 512x384 (SE/30).
- Don't scrape search engines' HTML (DuckDuckGo was dropped for bot-blocking). Search goes through SearXNG's JSON API; tune engines in `searxng/settings.yml`.
- Style: procedural PHP, inline HTML, `snake_case` vars, short `//` comments. Match it; don't introduce frameworks or classes.

## Security rules

- Escape every user- or remote-controlled value echoed into HTML with `h()`; put URLs into query strings with `urlencode()`.
- Never fetch a user-supplied URL with `file_get_contents`/`imagecreatefrom*`/`readfile` directly. Use `safe_fetch()`, which rejects non-http(s) schemes and private/reserved IPs on every redirect hop.
- Proxied article HTML goes through `simplify_html()` in `read.php`, which rebuilds it from whitelisted tags with no attributes except proxied `<a href>`.
- `index.php`'s SearXNG request deliberately bypasses `safe_fetch()` (it's a fixed, private Docker address). Never pass user input as that URL.
- Known gap: DNS rebinding can beat the `safe_fetch()` check (marked `ponytail:` in `lib.php`). Egress firewalling on the host is the real backstop.

## Checks

```bash
docker compose run --rm frogfind php -d zend.assertions=1 -d assert.exception=1 lib_test.php
```

No other tests. After changes, manually try `/?q=test` (SearXNG needs a few seconds after start), a result link, and an image link.
