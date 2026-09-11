# Agent Native WP

> Provides AI agents (ChatGPT, Claude, Perplexity, etc.) with a clean, structured version of your content—while human visitors continue to see your regular theme as usual.

![Version](https://img.shields.io/badge/version-1.0.0-0b6e68)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-GPLv2-3da639)

A WordPress plugin that identifies well-known AI agents based on their user-agent string and delivers a streamlined, machine-readable version of individual pages and posts—as clean, semantic HTML with JSON-LD or as Markdown. Human users continue to see your usual design. It also includes an `llms.txt` index, rate limiting, and an access log with CSV export.

---

## Features

- **Agent Detection** - detects known AI agents based on the user-agent string using a configurable list.
- **Structured Output** - streamlined version per page: clean HTML + JSON-LD **or** Markdown.
- **Format per Request** - append `?format=md` or `?format=html` to each post URL.
- **No Impact on Humans** - regular visitors will continue to see your theme without being redirected.
- **Discovery** - `/llms.txt` (index) and `/llms-full.txt` (full text), plus a note in `robots.txt`.
- **Clean linking** - `canonical` and `<link rel="alternate" type="text/markdown">` on every agent page.
- **Rate limiting** - only for real agents, fixed time window per IP, `HTTP 429` with `Retry-After`.
- **Logging & Monitoring** - Dedicated DB table with a dashboard and GDPR-compliant IP handling (masked / hashed / full / none) as well as an automatic retention limit.
- **CSV Export** - of the logs, with UTF-8 BOM for Excel, batch streaming, and protection against formula injection.

## Installation

1. Upload the plugin ZIP file under **Plugins → Install → Upload Plugin**.
2. **Activate**—this creates the log table and flushes the rewrite rules for `/llms.txt`.
3. Configure under **Settings → Agent Native**.
4. Monitor under **Tools → Agent Native Log**.

## VUsage & Testing

Force agent view in the browser:

```
?agent_view=1     Append to a post URL
?format=md        Markdown version
```

Test using a real user-agent:

```bash
curl -A "ClaudeBot" https://your-domain.tld/your-page/
```

Trigger a rate limit:

```bash
for i in $(seq 1 100); do
  curl -s -A "ClaudeBot" -o /dev/null -w "%{http_code}\n" https://your-domain.tld/your-page/
done
```

Check Discovery Endpoints:

```
https://your-domain.tld/llms.txt
https://your-domain.tld/llms-full.txt
```

## Notes & Limitations

> [!IMPORTANT]
> Show agents the same content as humans, just formatted differently. Displaying different content would constitute deceptive **cloaking** and may result in penalties. The `canonical` tag therefore always points to the human-readable URL.

> [!NOTE]
> - **User-Agent detection** is spoofable and incomplete—it’s a best-effort heuristic.
> - **Rate limiting and IP** use `REMOTE_ADDR`. If behind a CDN or proxy, derive the real client IP from a trusted header if necessary (deliberately not the default, as it can be spoofed).
> - The **Markdown converter** is intentionally lightweight (standard tags); for complex Gutenberg blocks, HTML mode is more reliable.

## Changelog

### 1.0.0
- CSV export of the access log (**Tools → Agent Native Log**).
- UTF-8 BOM, memory-safe batch streaming, protection against CSV formula injection.
- Per-request format (`?format=md` / `?format=html`).
- `/llms-full.txt` full-text export + `robots.txt` integration + alternate link.
- Rate limiting for real agents (`429` + `Retry-After`).
- Logging, including a monitoring dashboard and IP privacy modes.
- Initial release: Agent detection, clean HTML/JSON-LD, `/llms.txt`.

## License

Released under [GPLv2 (or later)](https://www.gnu.org/licenses/gpl-2.0.html).
