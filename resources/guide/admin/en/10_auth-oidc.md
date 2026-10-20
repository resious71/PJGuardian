---
key: auth-oidc
title: SSO (OIDC) sign-in
order: 10
summary: SSO sign-in with one OpenID Connect provider — registering with the provider (redirect URI), the fields on the SSO (OIDC) settings tab, the client secret, connection test, how accounts are matched, common errors, and a production checklist
keywords: OIDC, OpenID Connect, SSO, Entra ID, Azure AD, Okta, Keycloak, Google, redirect URI, client ID, client secret, issuer, PKCE, JWKS, E-SSO-STATE, E-SSO-TOKEN, E-SSO-DENIED
related: external-auth, auth-saml, accounts, settings
audience: admin
---

## What this feature does

It provides SSO sign-in through **one** standard OIDC provider (Entra ID, Okta, Keycloak, Google, and so on). Clicking the SSO button on the sign-in screen takes users to the provider's sign-in page and back. The flow is Authorization Code + PKCE (S256).

- People signing in for the first time get a pending account.
- Accounts are identified by **(issuer, sub)**. An account is never linked just because the email matches.
- By default, signing out ends only the app session.
- See [External authentication overview](guide:external-auth) for the shared rules.

## Preparation

1. Apply **migration 027** (`php tools/migrate.php`). Before that, OIDC behaves as if it were off and the settings tab shows a notice.
2. Decide the **public address**. The redirect URI is built from **{{ui:config_settings.key.oidc.redirect_base.label}}** (`oidc.redirect_base`); when empty, `app.base_url` is used. It must be https (http is allowed only for loopback development addresses). The request's Host header is never used.
3. Register the app (client) with the provider. The values to register appear on the SSO (OIDC) tab and can be copied with **{{ui:oidc.redirect.copy}}**.
   - **{{ui:oidc.redirect.label}}**: `<public address>/auth/oidc/callback`
   - Only when provider sign-out is on, **{{ui:oidc.redirect.logout_label}}**: `<public address>/login?logged_out=1`
   - Response type code, PKCE S256, scopes openid profile email by default
4. Have the issuer URL, client ID, and client secret from the provider ready.

Issuer and login ID claim by provider:

- **Entra ID**: issuer `https://login.microsoftonline.com/<tenant ID>/v2.0`, login ID claim preferred_username (UPN). Multi-tenant (common) is not used because the issuer differs per tenant.
- **Okta**: issuer `https://<org>.okta.com/oauth2/default` (or `https://<org>.okta.com`), preferred_username.
- **Keycloak**: issuer `https://<host>/realms/<realm>`, preferred_username. An internal address (private IP) is fine.
- **Google**: issuer `https://accounts.google.com`. There is no preferred_username, so use email_localpart (the part of a verified email before the @) or email.

## The SSO (OIDC) settings tab

Fill in the **{{ui:config_settings.group.auth_oidc}}** tab under System admin > [Settings](/admin/config-settings) and save with the **Review setting changes** button at the bottom. No restart is needed.

- **{{ui:config_settings.key.oidc.issuer.label}}** (`oidc.issuer`): an https address. It must match the issuer in the provider's discovery document **exactly**, including any trailing `/`.
- **{{ui:config_settings.key.oidc.client_id.label}}** (`oidc.client_id`)
- **{{ui:config_settings.key.oidc.client_auth.label}}** (`oidc.client_auth`): basic (default), post, or none (a public client with no secret, plus PKCE).
- **{{ui:config_settings.key.oidc.scopes.label}}** (`oidc.scopes`): separated by spaces; openid is required.
- **{{ui:config_settings.key.oidc.claim_login.label}}** (`oidc.claim_login`): the claim that becomes the app login ID. It must meet the login ID rules.
- **{{ui:config_settings.key.oidc.claim_name.label}}** (`oidc.claim_name`): used once, when the account is created.
- **{{ui:config_settings.key.oidc.use_userinfo.label}}** (`oidc.use_userinfo`): turn on only for providers whose ID token lacks name and email.
- **{{ui:config_settings.key.oidc.allowed_algs.label}}** (`oidc.allowed_algs`): choose from RS256 and ES256 only.
- **{{ui:config_settings.key.oidc.button_label.label}}** (`oidc.button_label`): up to 40 characters. When empty, "Sign in with SSO".
- **{{ui:config_settings.key.oidc.provider_logout.label}}** (`oidc.provider_logout`): when on, OIDC users are sent to the provider's sign-out page when they sign out.
- **{{ui:config_settings.key.oidc.jwks_cache_ttl.label}}** (`oidc.jwks_cache_ttl`, currently {{limit:oidc.jwks_cache_ttl}}), **{{ui:config_settings.key.oidc.jwks_stale_max.label}}** (`oidc.jwks_stale_max`, currently {{limit:oidc.jwks_stale_max}}): the discovery and signing key cache, and how much longer a stale cache may be used when the provider cannot be reached.
- **{{ui:config_settings.key.oidc.enabled.label}}** (`oidc.enabled`): turn it on last. The sign-in screen then shows the button, and OIDC appears in the sign-in method lists (CSV, sign-in method change).

A provider that uses an internal CA certificate relies on `repository.ca_cert_file`, the same file as repository connections. There is no setting to turn off certificate verification.

### Client secret

Enter it in **{{ui:oidc.secret.label}}** on the same tab and click **{{ui:oidc.secret.save}}**. It is stored encrypted, separately from the settings, and never shown again (only **{{ui:oidc.secret.replace}}** or delete; `auth.oidc_secret_set`, `auth.oidc_secret_delete`). It is not needed when client authentication is none. For providers whose secrets expire, such as Entra ID, record the expiry date and replace the secret before it expires.

## Connection test

**{{ui:oidc.test.run}}** checks the following in order using the saved settings. No credentials are sent.

1. {{ui:oidc.test.step.discovery}}
2. {{ui:oidc.test.step.jwks}}: shows the number of keys and the usable algorithms.
3. {{ui:oidc.test.step.token_endpoint}}: a 4xx response to a request without credentials counts as reachable.

Each admin can run it {{limit:oidc.test_per_minute}} times per minute (beyond that, `oidc_test_rate_limited`). Turn the feature on once it passes. When OIDC is on, the [Checks](/admin/checks) screen shows an SSO (OIDC) item.

## How accounts are matched

- If an account is linked to the (issuer, sub), that account is used, even if the login ID claim value changes.
- If none is linked and no account has the same login ID, a **pending account** is created. The email is filled in only when the provider marks it as verified.
- If an OIDC account with the same login ID exists but is not linked yet (pre-created through CSV or a sign-in method change), this sign-in links it (`user.external_link`).
- If the account with the same login ID is local, LDAP, or an OIDC account linked to a different sub, sign-in is **refused** (E-SSO-DENIED, admins are notified).
- If the login ID claim is missing or does not meet the rules, sign-in is refused.

The app cannot tell when the provider blocks a user. Deactivate departed users in the app too. Tokens are used only within the sign-in request and never stored.

## Common errors

Use the code on the sign-in screen and the reason in the audit log entry `auth.login_failed` (starting with oidc_) to find the cause.

- **E-SSO-STATE**: more than 10 minutes since sign-in started, the callback address was reopened with the back button, or the sign-in finished in another browser (reasons such as oidc_state_invalid, oidc_state_missing).
- **E-SSO-IDP**: the provider returned an error, such as declined consent or the app not assigned (for example oidc_idp_error.access_denied).
- **E-SSO-TOKEN**: the code exchange or ID token verification failed (for example oidc_token_error.invalid_grant, oidc_id_token.nonce, oidc_id_token.bad_signature). Check for an expired client secret, a redirect URI mismatch, and the server clock.
- **E-SSO-CONFIG**: missing settings, no secret, an issuer mismatch in discovery, or a forbidden address (for example oidc_issuer_mismatch, oidc_secret_missing, oidc_blocked_address).
- **E-SSO-UNAVAILABLE**: the provider could not be reached, timed out, or sent a response that was too large.
- **E-SSO-RATE**: more than {{limit:oidc.start_per_minute}} sign-in starts per minute from one IP, or the IP is blocked (oidc_start_rate).
- **E-SSO-DENIED**: login ID conflict (login_id_conflict), inactive account, or invalid login ID (invalid_login_id).

Connection test failure reasons:

- "{{ui:oidc.reason.issuer_mismatch}}": make the trailing `/` of the issuer URL match.
- "{{ui:oidc.reason.tls}}"
- "{{ui:oidc.reason.blocked_address}}": link-local and metadata addresses are always refused, and if the issuer is public, the endpoints must be public too.
- "{{ui:oidc.reason.jwks_no_usable_key}}"

### Outages and rotation

- **Provider outage**: only SSO stops working. Local admins sign in with the ID and password on the sign-in screen.
- **Client secret expired**: create a new secret at the provider and use **{{ui:oidc.secret.replace}}**.
- **Provider signing key rotation**: nothing to do. An unknown key triggers an automatic refresh of the key list.
- **Encryption key changed or lost**: the client secret shows as unreadable. Save it again.

## Production checklist

This feature was tested only against a fake IdP. Verify in your production environment:

1. All three connection test steps pass (discovery issuer, key count and algorithms, token endpoint 4xx).
2. A first-time user sees the pending notice, and the Users screen shows one pending account with the sign-in method {{ui:account.auth_oidc}}.
3. After approval and sign-in, My account shows the sign-in method {{ui:account.auth_oidc}} and the name and email from the provider.
4. After the name is changed at the provider, signing in again leaves the app name unchanged.
5. With a local account using the same login ID, sign-in is refused with E-SSO-DENIED and the local account is untouched.
6. Signing out signs out of the app only. With provider sign-out on, the user passes through the provider's sign-out page and returns to the sign-in screen.
7. Reopening the callback address with the browser back button shows E-SSO-STATE.
8. Blocking the provider with a firewall shows E-SSO-UNAVAILABLE, and a local admin can still sign in.
9. For Entra ID, the client secret expiry date is recorded; for Google, email verification is confirmed.
10. The audit log entry `auth.login_failed` contains only reasons, with no codes or tokens.

## Related sections

- [External authentication overview](guide:external-auth)
- [SSO (SAML) sign-in](guide:auth-saml)
- [Managing accounts](guide:accounts)
- [Settings](guide:settings)
