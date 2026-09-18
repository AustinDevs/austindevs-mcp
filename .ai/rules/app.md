---
paths:
  - 'app/**'
---

# App

## Personal remote MCP gateway
This is a single-owner SQLite gateway, not a multitenant copy of zql-mcp. Connections are database-driven remote HTTP MCPs, each exposed as one wrapper tool through /mcp. Keep incoming client authentication separate from encrypted upstream credentials. The upstream OAuth callback is shared and stable so manually registered providers can be configured before a connection exists.

## Gateway activity log goes through ActivityLogger
All gateway activity (tool calls, upstream failures, upstream OAuth steps, rejected /mcp requests) is written via App\Services\ActivityLogger, which redacts secret keys, scrubs bearer/token patterns inside strings, and truncates long values before inserting into gateway_logs. Never create GatewayLog rows directly. Upstream response bodies belong only in the log context, never in Filament notifications; notifications carry the exception message. The gateway_logs MCP tool and the Activity log page read the same table, so anything logged is visible to connected MCP clients. Passport blanks the Authorization header after a failed token check, so capture bearer presence before calling the api guard.
