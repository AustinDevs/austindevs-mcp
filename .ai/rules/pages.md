---
paths:
  - app/Filament/Pages/ClientAccess.php
---

# Pages

## Client access records and legacy compatibility
New static credentials are named GatewayToken records containing only SHA-256 hashes, with individual revocation and last-used timestamps. Keep the previous users.gateway_token_hash valid and visible as a revocable legacy token. OAuth grant revocation must revoke its refresh tokens too. Passport 13 clients use polymorphic owner_type/owner_id: query whereMorphedTo('owner', $user), not HasApiTokens::clients(), which targets the obsolete user_id column.
