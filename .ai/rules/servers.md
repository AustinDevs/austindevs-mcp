---
paths:
  - 'app/Mcp/Servers/**'
---

# Servers

## Return the full gateway inventory on the first discovery page
Size both defaultPaginationLength and maxPaginationLength to the registered tool inventory on each boot. Codex exposed only the first 15 wrappers with Laravel MCP's defaults, hiding later connections and gateway_logs. Do not replace this with a fixed page size that can truncate the inventory as connections grow.
