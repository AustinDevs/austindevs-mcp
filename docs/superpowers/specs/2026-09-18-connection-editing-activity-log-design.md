# Connection editing, gateway activity log, and upstream OAuth fixes

**Date:** 2026-09-18
**Status:** Approved

## Goal

Make the personal MCP gateway operable from its own dashboard and from the MCP itself:

1. Existing MCP connections can be edited.
2. Gateway activity (tool calls, upstream failures, OAuth steps, incoming auth failures) is recorded in the database and shown on a dashboard page.
3. The same activity log is exposed as a tool on the unified MCP server so an agent can read it.
4. Upstream OAuth failures report their real cause, and discovery handles servers that deviate from the happy path.

## Context

- Single-owner Laravel 13 app, SQLite, Filament v5 panel at `/app`, Laravel MCP server at `/mcp`, Passport for incoming auth.
- Deployed as a Docker container behind Cloudflare with SQLite in a `/data` volume. File logs do not survive redeploys; database rows do.
- Today every exception in the upstream OAuth flow and in tool calls is swallowed and replaced with generic text. Nothing is logged. This is why the upstream OAuth failure on production cannot currently be diagnosed.
- A discovery probe against Sentry, Linear, Notion, GitHub, Asana, Cloudflare, Stripe and Atlassian showed discovery succeeds for all but Atlassian (no protected-resource metadata) and that Stripe sends `resource_metadata` without quotes.

## 1. Edit connections

- Add `EditAction` to the row actions of the `McpConnectionResource` table. It reuses `McpConnectionResource::form()` in a modal, matching the existing `CreateAction` on the manage page.
- **Credential merge.** The form binds only `credentials.client_id`, `client_secret`, `scope`, `bearer_token`, and `headers`. On save, these keys are merged into the record's existing `credentials` array. Keys not on the form (`access_token`, `refresh_token`, `expires_at`, `metadata`) are preserved. Blank form values for `client_id`, `client_secret`, `scope`, `bearer_token` remove that key.
- **Reset on identity change.** If `url` or `auth_type` changed: remove `access_token`, `refresh_token`, `expires_at`, `metadata` from credentials; set `status` to `Authorization required` for `oauth`, otherwise `Not checked`; refetch `favicon` via `FaviconFetcher` when `url` changed.
- Secret fields remain password inputs with reveal, as on create.

## 2. Gateway activity log (storage)

### Schema: `gateway_logs`

| column | type | notes |
| --- | --- | --- |
| id | bigint | |
| level | string(16) | `info`, `warning`, `error` |
| category | string(32) | `tool_call`, `upstream`, `oauth`, `auth` |
| mcp_connection_id | nullable FK | `nullOnDelete` |
| message | string(255) | human-readable summary |
| context | json, nullable | redacted structured detail |
| duration_ms | unsigned int, nullable | for tool calls |
| created_at | timestamp | indexed; no `updated_at` |

Indexes: `created_at`, `(category, created_at)`, `mcp_connection_id`.

### Model: `GatewayLog`

- `$timestamps = false`, `created_at` cast to datetime, `context` cast to array.
- `belongsTo(McpConnection::class)`.
- Uses `MassPrunable`; `prunable()` returns rows older than 30 days. `model:prune` is scheduled daily in `routes/console.php`.

### Service: `App\Services\ActivityLogger`

The only writer. Public methods:

```php
public function info(string $category, string $message, array $context = [], ?McpConnection $connection = null, ?int $durationMs = null): GatewayLog;
public function warning(...): GatewayLog;
public function error(...): GatewayLog;
```

Redaction, applied recursively to `context` before insert:

- Keys matching (case-insensitive) `authorization`, `access_token`, `refresh_token`, `bearer_token`, `client_secret`, `code`, `code_verifier`, `token`, `secret`, `password`, `cookie`, `set-cookie` are replaced with `[redacted]`.
- Any string longer than 2,000 characters is truncated with a `… [truncated]` suffix.
- Headers arrays are redacted by the same key rule.

Logging must never break the request: `ActivityLogger` catches and reports its own failures to the default Laravel log and returns without throwing.

### Recorded events

| where | level | category | message / context |
| --- | --- | --- | --- |
| `ConnectionTool::handle` success | info | tool_call | `Called {upstream tool} on {connection}`; context: `tool_name`, `argument_bytes`, `result_bytes`, `is_error`; `duration_ms` |
| `ConnectionTool::handle` failure | error | tool_call | `Tool call failed on {connection}`; context: `tool_name`, `exception`, `error` (message) |
| `RemoteMcpClient::rpc` non-2xx | error | upstream | `Upstream returned HTTP {status}`; context: `method`, `status`, `body` (truncated) |
| `RemoteMcpClient::rpc` invalid body / protocol error | error | upstream | `Upstream returned an invalid response`; context: `method`, `status`, `body` (truncated), `rpc_error` if present |
| `UpstreamOAuth::authorize` | info | oauth | `Discovered OAuth server for {connection}`; context: `resource_metadata_url`, `issuer`, `authorization_endpoint`, `token_endpoint`, `registration` (`dynamic`/`manual`), `scope` |
| `UpstreamOAuth::authorize` failure | error | oauth | actual exception message; context: `step`, `url` where applicable, `status`, `body` (truncated) |
| `UpstreamOAuthController::callback` received | info | oauth | `OAuth callback received for {connection}`; context: `has_code`, `error`, `error_description` |
| `UpstreamOAuth::token` success | info | oauth | `OAuth {grant_type} succeeded for {connection}`; context: `grant_type`, `expires_in`, `has_refresh_token` |
| `UpstreamOAuth::token` failure | error | oauth | `OAuth {grant_type} failed for {connection}`; context: `grant_type`, `status`, `error`, `error_description` |
| `UpstreamOAuthController::callback` state mismatch | warning | oauth | `OAuth callback rejected`; context: `reason` |
| `AuthenticateGateway` 401/403 | warning | auth | `Gateway request rejected`; context: `ip`, `has_bearer`, `has_custom_header`, `reason` |

`RuntimeException`s thrown inside `UpstreamOAuth` carry the real cause in their message. The controller shows that message in the Filament notification body and logs it. Response bodies from upstream never appear in notifications, only in the (truncated, redacted) log context.

## 3. Logs page and MCP tool

### Filament resource: `GatewayLogResource`

- Read-only: `canCreate()` false, no edit or delete of single rows. Navigation icon: a document/list icon; label "Activity log".
- Table, default sort `created_at desc`, `poll('10s')`:
  - `created_at` (since-style, with full timestamp tooltip)
  - `level` badge (info gray, warning amber, error red)
  - `category` badge
  - `connection.name`
  - `message` (searchable, wraps)
- Filters: `level` (select), `category` (select), `mcp_connection_id` (select from connections), `created_at` from/to.
- Row action "Details": modal showing `message`, `duration_ms`, and `context` rendered as pretty-printed JSON in a `<pre>` block.
- Header action "Clear logs" (danger, confirmation) truncates the table.

### MCP tool: `GatewayLogsTool`

Registered on `UnifiedServer` alongside connection tools, always present.

- Name `gateway_logs`; description explains it returns the gateway's own activity log for debugging connections and OAuth.
- Schema (all optional): `limit` integer 1–200 default 50; `level` enum; `category` enum; `connection` string (matches connection name, case-insensitive contains); `since` string (ISO 8601 or relative like `2 hours ago`, parsed with `Carbon::parse`); `search` string (message contains).
- Returns `Response::json(['logs' => [...]])` with rows: `id`, `created_at` (ISO), `level`, `category`, `connection`, `message`, `duration_ms`, `context`.
- Invalid `since` returns `Response::error`.

## 4. Upstream OAuth fixes

In `UpstreamOAuth::authorize`:

1. Parse `resource_metadata` with or without quotes: `/resource_metadata="?([^",\s]+)"?/`.
2. If neither protected-resource metadata URL loads, fall back to the MCP server origin as the authorization server issuer (MCP spec pre-RFC 9728 behavior). Log which path was taken.
3. If the issuer has no `registration_endpoint` and no `client_id` was supplied, throw `This server does not support dynamic registration. Edit the connection and enter a client ID and secret.`
4. Issuer comparison: compare `rtrim($x, '/')` of both sides.
5. Every thrown `RuntimeException` names the failing step and URL. Upstream bodies go to the log only.

In `UpstreamOAuthController`: notification body is the exception message; both success and failure are logged.

## Testing (Pest, feature)

- Edit action: editing name preserves `access_token` and `metadata`; changing URL clears tokens, resets status, refetches favicon; blank client secret removes the key.
- `ActivityLogger`: redacts nested secret keys and Authorization headers; truncates long strings; swallows its own DB failure.
- `ConnectionTool` and `RemoteMcpClient`: successful call writes an info row with duration; HTTP 500 writes an upstream error row with truncated body; the returned error message stays generic to the MCP client.
- `GatewayLogsTool` via `POST /mcp`: filters by level, category, connection, since, search; enforces limit cap; rejects unparsable `since`.
- `GatewayLogResource`: page renders for owner; details action shows context; clear action empties table.
- `UpstreamOAuth`: unquoted `resource_metadata`; Atlassian-style 404 falls back to MCP origin; missing `registration_endpoint` yields the registration message; failure notification body carries the message; discovery and token outcomes are logged.
- Existing `UpstreamOAuthTest`, `McpGatewayTest`, `RemoteMcpClientTest` continue to pass.

## Out of scope

- Raw `laravel.log` access in UI or MCP.
- Log export, metrics, charts.
- Changes to the incoming Passport authorization flow.
- Multi-user support.
