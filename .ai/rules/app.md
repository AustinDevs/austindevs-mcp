---
paths:
  - 'app/**'
---

# App

## Personal remote MCP gateway
This is a single-owner SQLite gateway, not a multitenant copy of zql-mcp. Connections are database-driven remote HTTP MCPs, each exposed as one wrapper tool through /mcp. Keep incoming client authentication separate from encrypted upstream credentials. The upstream OAuth callback is shared and stable so manually registered providers can be configured before a connection exists.
