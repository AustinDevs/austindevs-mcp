---
paths:
  - app/Services/UpstreamOAuth.php
---

# Services

## Refresh expiry and inactivity are separate
Preserve provider refresh-token deadlines when a response omits them; clear an old deadline only when the refresh token changes. Google’s six-month inactivity deadline is an estimate based on the last successful token exchange, not a guarantee of validity or a way around Testing-mode/time-limited consent. Scheduled and request-driven refreshes share the same per-connection lock. Transient failures remain retryable; invalid grants require reconnecting.
