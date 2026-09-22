---
paths:
  - app/Http/Controllers/GoogleLoginController.php
---

# Controllers

## Google SSO identifies the single gateway owner
Admin sign-in accepts only Google's verified kevin@austindevs.com identity and maps it to the existing first User, preserving owner tokens and OAuth clients. Do not create users from Google callbacks or restore password login. Keep Socialite state validation and PKCE enabled; Workspace connection tokens use a separate UpstreamOAuth flow.
