# User API

OAuth2-authenticated user management for this Drupal 11 site: registration with
email confirmation, login, profile update, forgot password and logout. Tokens
are issued and validated by [simple_oauth] 6.x; this module adds the password
grant that simple_oauth 6.x does not ship, plus the JSON endpoints around it.

## Contents

- [Endpoint reference](#endpoint-reference)
- [Setup](#setup)
- [Response shape](#response-shape)
- [Error codes](#error-codes)
- [Configuration](#configuration)
- [Design notes](#design-notes)

## Endpoint reference

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/api/user/register` | none | Create an account (blocked) and email an activation link |
| `GET` | `/api/user/activate/{token}` | none | Redeem the token from the emailed link |
| `POST` | `/api/user/activate` | none | Redeem a token supplied in the body |
| `POST` | `/api/user/activation/resend` | none | Email a fresh activation link |
| `POST` | `/api/user/login` | none | Exchange credentials for a token + profile |
| `POST` | `/api/user/password/forgot` | none | Email a password reset token to an active account |
| `POST` | `/api/user/password/reset` | none | Redeem the token and set a new password |
| `GET` | `/api/user/me` | Bearer | Read the authenticated user's profile |
| `PATCH` | `/api/user/me` | Bearer | Update the authenticated user's profile |
| `POST` | `/api/user/logout` | Bearer | Clear the token used for the request |
| `POST` | `/oauth/token` | none | Raw OAuth2 token endpoint (simple_oauth) |

`POST` is also accepted on `/api/user/me` for clients that cannot send `PATCH`.

All endpoints accept a JSON body and return JSON. A form-encoded body works too.
No `?_format=json` is needed.

---

### Register

Creates a **blocked** account and emails an activation link. The account cannot
log in until the link is followed.

```bash
curl -X POST https://your-site/api/user/register \
  -H 'Content-Type: application/json' \
  -d '{
        "name": "janedoe",
        "mail": "jane@example.com",
        "password": "Str0ng-Pass!2026",
        "field_fullname": "Jane Doe",
        "field_phone_number": "+971500000001"
      }'
```

`name`, `mail` and `password` are required. Any field listed in
`user_api.settings:editable_fields` may also be sent — on this site
`field_fullname` and `field_phone_number` are **required by the user entity**, so
registration fails without them.

`201 Created`:

```json
{
  "message": "Your account has been created. Check your email for the activation link to finish signing up.",
  "data": {
    "user": { "uid": 3, "name": "janedoe", "status": "blocked", "...": "..." },
    "activation_email_sent": true
  }
}
```

Aliases: `username` for `name`, `email` for `mail`, `pass` for `password`.

### Activate

The emailed link points at `GET /api/user/activate/{token}`. Apps that intercept
the link (deep link, or a front-end page set through `activation_url`) can post
the token instead:

```bash
curl -X POST https://your-site/api/user/activate \
  -H 'Content-Type: application/json' \
  -d '{"token": "OcMFVgqgNWcu8sKyPr_pcpKEQGaA8pTJu8ipbKlDE6w"}'
```

`200 OK`:

```json
{
  "message": "Your account is now active. You can log in.",
  "data": {
    "user": { "uid": 3, "status": "active", "...": "..." },
    "email_confirmed": true,
    "can_log_in": true
  }
}
```

A token works **once** and expires after `activation_token_ttl` (24h default).
Requesting a new link invalidates the previous one.

If the site's registration mode is *Visitors, but administrator approval is
required*, confirming the address does **not** activate the account:
`can_log_in` comes back `false`, the account stays blocked, and the administrator
is notified.

### Resend activation

```bash
curl -X POST https://your-site/api/user/activation/resend \
  -H 'Content-Type: application/json' \
  -d '{"mail": "jane@example.com"}'
```

Always answers `200` with the same message whether or not the address is
registered, so it cannot be used to discover which addresses have accounts.

### Login

```bash
curl -X POST https://your-site/api/user/login \
  -H 'Content-Type: application/json' \
  -d '{
        "username": "janedoe",
        "password": "Str0ng-Pass!2026",
        "client_id": "app_client"
      }'
```

`username` accepts either a username or an email address. Send `client_secret`
too if the consumer is marked confidential. `scope` is optional.

`200 OK`:

```json
{
  "message": "Login successful.",
  "data": {
    "token_type": "Bearer",
    "expires_in": 3600,
    "access_token": "eyJ0eXAiOiJKV1Qi...",
    "refresh_token": "def50200f592b570...",
    "user": { "uid": 3, "name": "janedoe", "...": "..." }
  }
}
```

Use the token on every authenticated call:

```
Authorization: Bearer <access_token>
```

### Forgot password

```bash
curl -X POST https://your-site/api/user/password/forgot \
  -H 'Content-Type: application/json' \
  -d '{"mail": "jane@example.com"}'
```

`200 OK`, always the same response whether or not the address belongs to an
account, so this endpoint cannot be used to discover which addresses are
registered:

```json
{
  "message": "If that address belongs to an active account, a password reset email is on its way.",
  "data": {}
}
```

A **blocked** account is treated the same as an unknown address: it cannot log
in regardless of its password, so no reset email is sent for it. Rate limited
per IP by `password_reset_flood_limit` / `password_reset_flood_window`
(`flood_password_reset`, `429`, once tripped).

If `password_reset_url` is configured, the email links to that front end with
`[token]` substituted in. Otherwise it contains the raw token as a code for
the app to collect alongside the new password and submit itself — there is no
site page this can link to the way `GET /api/user/activate/{token}` completes
activation on its own, because a reset also needs the new password.

### Reset password

```bash
curl -X POST https://your-site/api/user/password/reset \
  -H 'Content-Type: application/json' \
  -d '{
        "token": "xQwpNFk9EUJezTA4K3ebBGhQdIZEBAEhgLOpWHO1b-g",
        "password": "N3w-Str0ng-Pass!2026"
      }'
```

`200 OK`:

```json
{
  "message": "Your password has been reset. You can now log in with your new password.",
  "data": {
    "user": { "uid": 3, "name": "janedoe", "...": "..." }
  }
}
```

The token works **once** and expires after `password_reset_token_ttl` (1 hour
default); requesting a new one invalidates the previous. Unlike
`PATCH /api/user/me`, no `current_password` is needed — proving ownership of
the emailed token stands in for it, exactly as it does with core's own
`user.pass` form. The reset does not return a token: call `POST
/api/user/login` afterwards. Every existing access and refresh token on the
account is cleared, the same as a credential change through the profile
endpoint.

### Read profile

```bash
curl https://your-site/api/user/me -H "Authorization: Bearer $ACCESS_TOKEN"
```

### Update profile

```bash
curl -X PATCH https://your-site/api/user/me \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"field_bio": "Travel writer.", "field_phone_number": "+971500000099"}'
```

Changing `mail` or `password` additionally requires `current_password`, matching
Drupal core:

```bash
curl -X PATCH https://your-site/api/user/me \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
        "mail": "new@example.com",
        "current_password": "Str0ng-Pass!2026"
      }'
```

Accepted keys: `name`, `mail`, `password`, `current_password`, and anything in
`editable_fields`. Send `null` to clear an optional field.

`200 OK`:

```json
{
  "message": "Profile updated.",
  "data": {
    "user": { "...": "..." },
    "reauthentication_required": false
  }
}
```

**`reauthentication_required` is the field to branch on.** Changing the email
address or password clears every token on the account — including refresh
tokens — so the client must log in again. A change to any other field leaves the
current token working.

### Logout

```bash
curl -X POST https://your-site/api/user/logout \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -H 'Content-Type: application/json' \
  -d "{\"refresh_token\": \"$REFRESH_TOKEN\"}"
```

Deletes the access token behind the request. Include `refresh_token` to clear it
too — **a refresh token cannot be found from the access token alone**, so a
client that omits it keeps a usable refresh token and is not really logged out.

`200 OK`:

```json
{
  "message": "Logged out. The token is no longer valid.",
  "data": { "access_token_cleared": true, "refresh_token_cleared": true }
}
```

Only this session is affected; other devices stay signed in.

### Refresh a token

There is no wrapper for this; use simple_oauth's endpoint directly.

```bash
curl -X POST https://your-site/oauth/token \
  -d grant_type=refresh_token \
  -d client_id=app_client \
  -d "refresh_token=$REFRESH_TOKEN"
```

`/oauth/token` also serves the password grant directly, if you prefer a standard
OAuth2 client over `/api/user/login`:

```bash
curl -X POST https://your-site/oauth/token \
  -d grant_type=password -d client_id=app_client \
  -d username=janedoe -d 'password=Str0ng-Pass!2026'
```

The difference is only in packaging: `/api/user/login` adds the user profile and
the JSON envelope, `/oauth/token` returns the bare OAuth2 response.

## Setup

1. **Generate a key pair** outside the web root. This install has `docroot: ""`,
   so the repository root *is* web-accessible — do not put keys inside it.

   ```bash
   mkdir -p /path/outside/webroot/keys
   openssl genrsa -out /path/outside/webroot/keys/private.key 2048
   openssl rsa -in /path/outside/webroot/keys/private.key -pubout \
     -out /path/outside/webroot/keys/public.key
   chmod 600 /path/outside/webroot/keys/*.key
   ```

   ```bash
   drush cset simple_oauth.settings public_key  /path/outside/webroot/keys/public.key
   drush cset simple_oauth.settings private_key /path/outside/webroot/keys/private.key
   ```

   The web server user needs read access. Keys must be identical across all web
   nodes, and must not be committed.

2. **Create a consumer** at `/admin/config/services/consumer/add`:
   - enable the **Password** and **Refresh Token** grants;
   - untick **Confidential** for a mobile app or SPA (it cannot keep a secret);
   - leave it ticked and set a secret for a server-side client, then send
     `client_secret` on every token request.

3. **Allow registration** at `/admin/config/people/accounts`. With *Administrators
   only*, `POST /api/user/register` answers `403 registration_disabled`.

4. **Check outbound mail works.** Registration is useless if the activation email
   never arrives.

## Response shape

Success — always `message` plus an object under `data`:

```json
{ "message": "...", "data": { } }
```

Error — always a single `error` object:

```json
{ "error": { "code": "validation_failed", "message": "...", "details": { } } }
```

`details` appears on validation failures and maps field name to messages:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The submitted values are not valid.",
    "details": {
      "name": ["The username janedoe is already taken."],
      "mail": ["The email address jane@example.com is already taken."]
    }
  }
}
```

Field messages come from Drupal's entity validation, so they arrive already
translated and may contain HTML emphasis markup.

## Error codes

| Code | Status | Meaning |
| --- | --- | --- |
| `validation_failed` | 422 | See `details` |
| `invalid_json` | 400 | Body was not valid JSON |
| `registration_disabled` | 403 | Site allows admin-created accounts only |
| `missing_token` | 400 | No activation or reset token supplied |
| `invalid_activation_token` | 400 | Activation token unknown, already used, or expired |
| `invalid_reset_token` | 400 | Password reset token unknown, already used, or expired |
| `flood_password_reset` | 429 | Too many password reset requests from this IP |
| `account_not_found` | 404 | Account behind the token or session is gone |
| `invalid_grant` | 400 | Wrong username or password |
| `invalid_client` | 401 | Unknown or disabled `client_id` |
| `unsupported_grant_type` | 400 | Grant not enabled on that consumer |
| `account_not_activated` | 403 | Correct password, activation link not yet used |
| `account_pending_approval` | 403 | Address confirmed, awaiting an administrator |
| `account_blocked` | 403 | Account blocked by an administrator |
| `flood_user` | 429 | Too many failed logins for this account |
| `flood_ip` | 429 | Too many failed logins from this IP |
| `flood_register` | 429 | Too many registrations from this IP |
| `nothing_to_update` | 400 | No supported field in the body |
| `unauthenticated` | 401 | Missing, expired or cleared token |
| `access_denied` | 403 | Authenticated but not permitted |
| `not_found` / `method_not_allowed` | 404 / 405 | Wrong path or HTTP method |

Account state is only ever disclosed **after** the password checks out, so these
endpoints cannot be used to enumerate accounts.

## Configuration

`drush cget user_api.settings`

| Key | Default | Purpose |
| --- | --- | --- |
| `activation_token_ttl` | `86400` | Activation token lifetime, in seconds |
| `activation_url` | `''` | Front-end activation URL; `[token]` is substituted. Empty means link to this site's `GET /api/user/activate/{token}` |
| `register_flood_limit` | `5` | Registration/resend attempts per IP |
| `register_flood_window` | `3600` | Window for the above, in seconds |
| `password_reset_token_ttl` | `3600` | Password reset token lifetime, in seconds |
| `password_reset_url` | `''` | Front-end reset URL; `[token]` is substituted. Empty means the email carries the raw token as a code instead of a link |
| `password_reset_flood_limit` | `5` | Password reset requests per IP |
| `password_reset_flood_window` | `3600` | Window for the above, in seconds |
| `editable_fields` | see below | Extra fields accepted at registration and update |
| `exposed_fields` | see below | Extra fields included in user payloads |

Both field lists default to `field_fullname`, `field_phone_number`, `field_bio`,
`timezone`, `preferred_langcode`. **Any user field that is required must appear in
`editable_fields`**, or registration cannot satisfy entity validation.

Point activation links at your app rather than the API:

```bash
drush cset user_api.settings activation_url \
  'https://app.example.com/activate?token=[token]'
```

Login flood limits come from core's `user.flood` settings (`user_limit`,
`user_window`, `ip_limit`, `ip_window`), so they match the site's web login.

## Design notes

Points where this module deliberately departs from what the surrounding
contrib code does on its own.

**The password grant is added here.** simple_oauth 6.x ships only
`authorization_code`, `client_credentials` and `refresh_token`. Rather than take
on another contrib dependency, `Plugin/Oauth2Grant/Password.php` wraps
`League\OAuth2\Server\Grant\PasswordGrant`, which is already in `vendor/`. Tokens
are therefore minted by simple_oauth's own authorization server and are
indistinguishable from tokens issued to any other grant. The grant is opt-in per
consumer, so no existing client gains it.

Note that OAuth 2.1 discourages the password grant. It is the right fit for a
first-party app talking to its own backend, which is what this API is for; for
third-party clients, use `authorization_code` with PKCE.

**Blocked accounts are rejected explicitly.** `Drupal\user\UserAuthentication`
only compares the password hash — it does not look at account status. Without the
check in `CredentialsValidator`, an unactivated account could obtain a token and
the email confirmation step would be decorative.

**Only the token digest is stored.** Activation tokens are 256 bits from
`Crypt::randomBytesBase64()`; the database holds a SHA-256 digest in an expirable
key/value collection, so a database read cannot be replayed as an activation
link, and expiry is handled by the store.

**Harmless profile edits no longer sign the user out.** simple_oauth deletes
every token on the account whenever that account is saved
(`simple_oauth_user_update()`), with no way to configure it. That is correct after
a credential change but makes a profile endpoint unusable — editing a phone
number would log the user out of every device. `TokenExpiryTriggerHandler`
decorates simple_oauth's service and skips the purge only for saves this
module's profile endpoint has flagged as non-sensitive. Every other path — an
administrator editing the account, drush, other modules, and any update that did
change the email or password — behaves exactly as before.

**Credential changes also clear refresh tokens.** simple_oauth's own cleanup
excludes them (`ExpiredCollector::collectForAccount()` filters out the
`refresh_token` bundle), so after a password change an old refresh token could
still be exchanged for a fresh access token. `TokenRevoker::revokeAllForAccount()`
closes that window.

**Errors are JSON, including the ones Drupal raises.** Access and method failures
happen in the kernel before a controller runs, so `ApiExceptionSubscriber`
converts them for `/api/user/*`. It also answers `401` rather than `403` when no
`Authorization` header was sent at all, which tells a client to get a token
instead of implying the one it holds is insufficient.

**One activation email, not two.** Core emails a "status activated" notice —
carrying a one-time login URL — whenever an account goes from blocked to active.
After a user has just confirmed their own address that is redundant, so
`user_api_mail_alter()` cancels that single message during API activation.
Administrator approvals still send it.

**Email confirmation is not account approval.** On a site set to *Visitors, but
administrator approval is required*, confirming the address records the
confirmation and notifies the administrator, but leaves the account blocked —
otherwise this API would quietly bypass the site's approval policy.

**Forgot password reuses the activation module's token pattern.** Only a
SHA-256 digest of the reset token is stored, in the same kind of expirable
key/value collection as activation tokens
(`\Drupal\user_api\Service\PasswordReset`), for the same reason: a database
read cannot be replayed as a reset link. A blocked account is treated the same
as an unregistered address by `POST /api/user/password/forgot`, since neither
can log in, which keeps that endpoint from disclosing account state the way
`resend()` already avoids doing for activation. `POST /api/user/password/reset`
runs anonymously against the target account directly through the entity API,
so core's `ProtectedUserFieldConstraint` -- which only fires when the *current
session's* user matches the account being saved -- never asks for a
`current_password` the caller could not supply.

### Not included

- **Account cancellation or deletion.**
- **Email re-verification on change.** Changing `mail` through
  `PATCH /api/user/me` requires the current password and takes effect
  immediately, as it does in core. If you want the new address confirmed before
  it becomes live, that needs a pending-email flow.
- **A "sign out everywhere" endpoint.** `TokenRevoker::revokeAllForAccount()`
  already does the work if you want to expose it.
- **Automated tests.** Verified manually against DDEV; see the flows in this
  README.

[simple_oauth]: https://www.drupal.org/project/simple_oauth
