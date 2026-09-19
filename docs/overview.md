# Architecture overview

`austindevs-mcp` is Kevin's personal/company MCP gateway, deployed at `mcp.austindevs.com`. It is
a Laravel 13 (PHP 8.4) app built on `laravel/mcp` + `laravel/passport` + Filament 5, and it is the
single OAuth-capable custom connector Kevin adds to claude.ai (and other MCP clients) to reach
everything else: Slack (5 workspaces), GitHub, Trello, Asana, Spark email, Coolify, Cloudflare,
Hermes, mcpvault, and more.

This file is the general architecture/operations reference. Feature-specific implementation plans
and design specs live under `docs/superpowers/{plans,specs}` (one file per feature, dated) — this
file won't duplicate those; it's the standing picture that ties them together.

## Why this exists / history

Earlier planning (mid-2026, before this repo) referred to the aggregation layer as `zql-mcp`: a
hand-rolled "progressive discovery" dispatch pattern — call `list_available_tools` first, then
invoke a specific tool by name — aggregating ~25 internal services behind
`mcp.zollege.ai` / `mcp.austindevs.com`, built to avoid burning context on a large tool list up
front. That pattern is exactly what `ConnectionTool` below implements now that the gateway has
been rebuilt as this Laravel app.

## Core pieces

- **`app/Mcp/Servers/UnifiedServer.php`** — the single `laravel/mcp` server every client connects
  to. It registers two tools:
  - **`app/Mcp/Tools/ConnectionTool.php`** — the dynamic per-connection dispatcher. Each configured
    upstream is a row in the `McpConnection` model (DB-backed, managed through a Filament admin
    panel), and this tool implements the `list_available_tools` → `tool_name` + `arguments`
    pattern against whichever connection is named.
  - **`app/Mcp/Tools/GatewayLogsTool.php`** — exposes the gateway's own activity log as an MCP
    tool (added 2026-09-18), so an agent can inspect recent tool calls/failures without shelling
    into the box.
- **`app/Models/McpConnection.php`** — one row per upstream service/account (name, upstream URL,
  auth style, stored OAuth token if applicable, icon).
- **`app/Models/GatewayLog.php`** + **`app/Services/ActivityLogger.php`** — record every gateway
  tool call and upstream OAuth/auth failure, with redaction, for the activity log page and the
  `GatewayLogsTool` above.
- **`app/Services/ConnectionEditor.php`** — lets the Filament admin edit a connection (URL, scopes,
  etc.) without clobbering its already-stored OAuth token.
- **`app/Services/UpstreamOAuth.php`** — brokers per-connection OAuth against upstream MCP servers
  (discovery, token exchange/refresh), independent of the gateway's own OAuth server role below.
- **`app/Services/RemoteMcpClient.php`** / **`RemoteUrl.php`** — the actual outbound MCP client used
  to call an upstream connection.
- **`app/Services/FaviconFetcher.php`** / **`ServiceIcon.php`** — fetch a connection's favicon for
  the admin UI, falling back to a bundled brand icon matched by name/host, then a neutral
  placeholder, so the connections list never shows a broken image.
- **`laravel/passport`** — the gateway is itself an OAuth 2.1 authorization server toward claude.ai
  and other clients. `config/mcp.php` allows the `claude:` custom URI scheme (RFC 8252, for
  desktop/native OAuth callbacks) and currently wildcards `redirect_domains`.

## Tool naming

Connection tools were originally exposed with opaque `server_N` identifiers. Fixed 2026-09-18 to
slug-based names (`hermes_12`, `github_17`, `slack_dineup_27`, etc.) for human readability and to
help smaller models pick the right tool without guessing from a number. This is why MCP clients see
tool names like `mcp__claude_ai_Austin_Devs_MCP__github_17`.

## Deployment / container topology (Coolify, end state as of 2026-09)

- Builds via a real **Dockerfile** (`serversideup/php:8.4-fpm-nginx` + a Vite build stage), not
  Nixpacks — Nixpacks died on a `filament/blueprint` dependency that needs a paid Filament v5
  license (`COMPOSER_AUTH`); that dependency was dropped instead.
- After first deploy, `/mcp` 500'd with `Invalid key supplied` until Passport's RSA keys were
  regenerated and the app restarted — worth checking first if that error reappears after a fresh
  deploy or volume reset.
- Only `mcp.austindevs.com` itself keeps a public Cloudflare Tunnel. `actual-mcp`, `browser-mcp`,
  and `github-mcp` are plain containers reached over Coolify's internal Docker network instead of
  each getting their own public tunnel. GitHub's own hosted MCP is called directly at
  `https://api.githubcopilot.com/mcp/`, so it needs no container at all.
- See `[[Coolify Homelab Migration and Architecture]]` in Obsidian for the broader homelab
  container topology this fits into.

## Client compatibility quirks observed against this gateway

- **Codex** doesn't support dynamic client registration (hardcodes `client_id=codex`) — use a
  bearer token or the desktop app's Header name/value fields instead of OAuth.
- **Codex mobile** only reaches MCP servers for threads executing on a paired always-on Mac;
  cloud-run threads have no MCP access at all.
- **Gemini's** consumer app only supports custom MCP servers as "Connected Apps," gated behind
  Gemini Spark, web-setup only, and Streamable HTTP only (no SSE support).

## Relationship to `mcp-proxy`

`kevincolten/mcp-proxy` (a separate Cloudflare Worker) is kept as a **separate system**, split by
auth style, not folded into this gateway:

- `austindevs-mcp` (this repo) handles general aggregation of connections that use static
  credentials or their own per-connection OAuth broker (`UpstreamOAuth`).
- `mcp-proxy` specifically solves the "one account per URL" problem for third-party MCP servers
  (Slack, Sentry, Trello) that claude.ai can otherwise only connect to a single account of — each
  `<service>/<account>` gets its own URL and its own token in Cloudflare KV.

Self-hosted aggregators (MetaMCP, MCPJungle) and gateway SaaS (Zuplo, Speakeasy, Composio, MintMCP,
Glama, orq.ai) were evaluated as alternatives to this split; conclusion was that MetaMCP suits
consolidating self-hosted/static-credential servers but doesn't replace `mcp-proxy`'s per-account
OAuth brokering, so both are kept.
