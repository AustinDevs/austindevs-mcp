---
paths:
  - app/Services/UpstreamOAuth.php
---

# Services

## Refresh expiry and inactivity are separate
Preserve provider refresh-token deadlines when a response omits them; clear an old deadline only when the refresh token changes. Google’s six-month inactivity deadline is an estimate based on the last successful token exchange, not a guarantee of validity or a way around Testing-mode/time-limited consent. Scheduled and request-driven refreshes share the same per-connection lock. Transient failures remain retryable; invalid grants require reconnecting.

## Google connections default to the gateway's Google app
When a connection's issuer is https://accounts.google.com and it has no client_id, authorize and token exchange use services.google (GOOGLE_CLIENT_ID/SECRET, the same client as admin SSO), resolved at use time. Never copy that client into connection credentials, so the secret lives only in the Coolify environment. A per-connection client_id still wins. Many Google accounts share the one app; each connection holds its own tokens.

## Google authorization always shows the account chooser
For issuer https://accounts.google.com, authorize always sends prompt containing select_account (merged with any configured prompt, default consent) and access_type=offline. The owner connects several Google accounts; without select_account Google silently reuses the only signed-in session.
