# php-markdown-mirror

**One source of truth. Two representations. Zero duplication.**

A pure-PHP middleware that serves Markdown representations of your existing HTML pages — on demand, from the same codebase, with no content duplication. Drop two files into your project. No Composer. No Node. No templating engine.

Your HTML stays the single source of truth. Markdown is generated on the fly via structural DOM conversion whenever the client asks for it.

---

## Why?

AI crawlers, LLM agents, and API consumers increasingly prefer Markdown over HTML. At the same time, you don't want to maintain two copies of your content. php-markdown-mirror solves this by intercepting the HTTP response and converting the main content area to clean Markdown — only when the client explicitly asks for it.

Your regular visitors still get the full HTML experience. Bots and tools that send `Accept: text/markdown` (or append `?v=md`) get a clean, structural Markdown response with proper `Content-Type` headers.

Think of it as **content negotiation for the AI era**.

## Schema.org JSON-LD → Frontmatter

If your HTML contains `<script type="application/ld+json">` blocks (Schema.org structured data), php-markdown-mirror automatically extracts them and renders them as YAML frontmatter at the top of the Markdown output. This gives LLM agents immediate access to structured metadata without parsing HTML.

**Input** (in your HTML `<head>`):
```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "My Product",
  "offers": {"@type": "Offer", "price": "299", "priceCurrency": "CZK"}
}
</script>
```

**Markdown output**:
```yaml
---
@type: Product
name: My Product
offers:
  @type: Offer
  price: 299
  priceCurrency: CZK
---

# My Product
...
```

Supports `@graph` containers, nested objects, and multiple JSON-LD blocks per page.

## Quick Start

### Option A: Manual install (no Composer)

Download or clone the repo and copy it anywhere in your project:

```
your-project/
├── .htaccess               ← add Vary header (see .htaccess.example)
├── index.php
└── mdClass/
    ├── markdown_output.php    ← bootstrap
    └── src/
        ├── HtmlToMarkdown.php
        └── MarkdownMiddleware.php
```

Add one line to the top of your `index.php`:

```php
<?php
require_once __DIR__ . '/mdClass/markdown_output.php';
```

### Option B: Composer

```bash
composer require mediatoring/php-markdown-mirror
```

Then in your entry point:

```php
<?php
require_once 'vendor/autoload.php';
MarkdownMiddleware::register();
```

## How It Works

```
Client request
     │
     ├─ Accept: text/html (default)
     │    └─ Normal HTML response, untouched
     │
     ├─ Accept: text/markdown
     │    └─ HTML captured → single DOM parse
     │         ├─ JSON-LD extracted → YAML frontmatter
     │         └─ Main content converted → Markdown body
     │
     └─ ?v=md query parameter
          └─ Same as above
```

The middleware uses PHP's output buffering (`ob_start`) to capture the final HTML. It parses the DOM **once**, extracts Schema.org JSON-LD from `<script>` tags, finds the main content area using a priority-based selector strategy (`<main>` → `<article>` → `<div id="content">` → `<body>`), and passes the `DOMElement` directly to the converter — no re-parsing, no serialization round-trip.

## Detection

php-markdown-mirror recognizes Markdown requests via two mechanisms:

**HTTP Header** — `Accept: text/markdown`

```bash
curl -H "Accept: text/markdown" https://example.com/
```

**Query Parameter** — `?v=md`

```
https://example.com/?v=md
```

Both return `Content-Type: text/markdown; charset=utf-8` with `Vary: Accept` and `Cache-Control: no-transform` headers (Cloudflare-safe).

## Smart Filtering

Not everything in your HTML belongs in a Markdown representation. php-markdown-mirror applies universal heuristics to skip non-content elements automatically:

| Skipped automatically | Examples |
|---|---|
| Interactive elements | `<button>`, `<form>`, `<input>`, `<select>` |
| ARIA landmarks | `role="navigation"`, `role="banner"`, `role="complementary"` |
| Hidden elements | `aria-hidden="true"` |
| UI widgets (by class) | `.btn`, `.countdown`, `.modal`, `.popup`, `.sidebar` |
| Icon images | SVG in `<img>`, paths containing `/icon`, dimensions < 50px |
| Scripts & embeds | `<script>`, `<style>`, `<iframe>`, `<video>`, `<canvas>` |

For anything the heuristics don't catch, add `data-md-skip` to any HTML element:

```html
<div data-md-skip>This won't appear in the Markdown output.</div>
```

## Supported HTML → Markdown Conversions

| HTML | Markdown |
|---|---|
| `<h1>` – `<h6>` | `#` – `######` |
| `<p>` | Paragraphs |
| `<strong>`, `<b>` | `**bold**` |
| `<em>`, `<i>` | `*italic*` |
| `<u>`, `<ins>` | `*emphasis*` (no native MD underline) |
| `<del>`, `<s>` | `~~strikethrough~~` |
| `<code>`, `<kbd>`, `<samp>` | `` `inline code` `` |
| `<var>`, `<cite>`, `<dfn>` | `*italic*` |
| `<mark>` | `==highlight==` |
| `<q>` | `"inline quote"` |
| `<abbr title="...">` | `ABBR (Full Title)` |
| `<sub>`, `<sup>` | `<sub>`, `<sup>` (HTML passthrough) |
| `<time datetime="...">` | Visible text or datetime attribute |
| `<small>`, `<address>` | Plain text passthrough |
| `<pre><code>` | Fenced code blocks with language detection |
| `<a href>` | `[text](url)` with optional title |
| `<img>` | `![alt](src)` |
| `<ul>`, `<ol>` | Lists with nesting and `start` attribute support |
| `<table>` | Pipe tables with multibyte-safe alignment |
| `<blockquote>` | `> quoted text` |
| `<hr>` | `---` |
| `<dl>`, `<dt>`, `<dd>` | Definition lists |
| `<details>` / `<summary>` | Bold summary + body |
| `<figure>` / `<figcaption>` | Image + italic caption |
| `<br>` | Trailing space line break |
| JSON-LD `<script>` | YAML frontmatter |

## Content Extraction Strategy

The middleware searches for the main content area in this order:

1. `<main>`
2. `<article>`
3. `<div id="content">`
4. `<div class="content">`
5. `<div id="main">`
6. `<div id="main-content">`
7. `<div class="main-content">`
8. `<body>` (fallback)

Navigation, headers, footers, and sidebars outside the main content area are excluded automatically.

## Testing

```bash
# Standard HTML (unchanged behavior)
curl -i https://your-site.com/

# Markdown via Accept header
curl -i -H "Accept: text/markdown" https://your-site.com/

# Markdown via query parameter
curl -i "https://your-site.com/?v=md"
```

Verify that:
- HTML response has `Content-Type: text/html`
- Markdown response has `Content-Type: text/markdown; charset=utf-8`
- Markdown output contains no `<html>`, `<body>`, or other HTML tags
- `Vary: Accept` header is present in both responses

## Using the HtmlToMarkdown Class Directly

You can also use the converter standalone, without the middleware:

```php
<?php
require_once 'src/HtmlToMarkdown.php'; // or via Composer autoload

$converter = new HtmlToMarkdown();
$markdown  = $converter->convert('<h1>Hello</h1><p>World with <strong>bold</strong>.</p>');

echo $markdown;
// # Hello
//
// World with **bold**.
```

## Requirements

- PHP 7.4+ (works with 8.x)
- DOM extension (enabled by default)
- mbstring extension (enabled by default)
- Standard shared hosting (cPanel, LiteSpeed, Apache, Nginx)

No Composer. No external dependencies.

## Caching Considerations

For Markdown responses, the middleware sets `Vary: Accept` and `Cache-Control: no-transform` automatically. For HTML responses, add this to your `.htaccess` (see `.htaccess.example`):

```apache
<IfModule mod_headers.c>
    Header append Vary Accept
</IfModule>
```

This ensures proxies and CDNs (including Cloudflare) cache HTML and Markdown responses separately. The `no-transform` header prevents Cloudflare from minifying the Markdown output.

## How Is This Different From…

**…maintaining a separate .md file?** You'd have two sources of truth. Every content change needs to be made twice. php-markdown-mirror generates Markdown from your live HTML, so there's nothing to keep in sync.

**…`strip_tags()`?** That just removes tags and loses all structure. php-markdown-mirror does a full DOM traversal, preserving headings, lists, tables, code blocks, links, images, and formatting.

**…JavaScript-based conversion?** php-markdown-mirror runs entirely on the server. The client receives pure Markdown text, no JS execution needed. This is critical for AI crawlers and API consumers.

**…Composer packages like league/html-to-markdown?** Those are great general-purpose converters. php-markdown-mirror is purpose-built for serving Markdown representations of live pages — it includes the middleware layer, Schema.org JSON-LD extraction as frontmatter, and smart content filtering. It works with or without Composer.

## Use Cases

- **LLM / AI crawler optimization** — Serve clean Markdown to GPTBot, ClaudeBot, and other AI agents
- **API content negotiation** — Let API consumers request Markdown representations of your pages
- **llms.txt ecosystem** — Complement your `llms.txt` with actual Markdown-served pages
- **Documentation sites** — Offer downloadable Markdown versions of your docs
- **Content syndication** — Provide Markdown feeds without maintaining separate files
- **Headless CMS output** — Add Markdown as an output format to any PHP-based CMS

## License

MIT License. See [LICENSE](LICENSE) for details.

## Author

Created by [Mediatoring.com](https://mediatoring.com) in collaboration with [kubicek.ai](https://kubicek.ai/en/) and [optimalizace.ai](https://optimalizace.ai) — home of the ebook *Optimalizace webu pro AI* on how to make your website visible to LLMs.

---

**If your HTML already has the content, why write it twice?**
