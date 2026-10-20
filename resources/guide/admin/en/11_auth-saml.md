---
key: auth-saml
title: SSO (SAML) sign-in
order: 11
summary: SSO sign-in with one SAML 2.0 IdP — importing IdP metadata, registering the SP (entity ID, ACS URL, metadata), the fields on the SSO (SAML) settings tab, the SP key, settings check, certificate rollover and expiry warnings, common errors, and a production checklist
keywords: SAML, SSO, ADFS, Entra ID, Okta, Keycloak, IdP, SP, entity ID, ACS, metadata, NameID, certificate, rollover, expiry, SP key, request signing, encrypted assertions, E-SSO-STATE, E-SSO-TOKEN
related: external-auth, auth-oidc, accounts, settings
audience: admin
---

## What this feature does

It provides SSO sign-in through **one** SAML 2.0 IdP (ADFS, Entra ID, Okta, Keycloak, and so on). Only **SP-initiated sign-in** is supported: SAML button on the sign-in screen → IdP sign-in → back to the app.

- **Sign-ins started from an app tile in the IdP portal (IdP-initiated) are refused** (E-SSO-STATE). Tell users to start from the app's sign-in screen.
- **There is no single logout (SLO).** Signing out ends only the app session.
- Accounts are identified by **(IdP entity ID, NameID or a chosen attribute)**. An account is never linked just because the email matches.
- XML signatures in responses are verified only with a proven library (onelogin/php-saml). If the `vendor/` folder of the release bundle is missing, SAML behaves as if it were off and the [Checks](/admin/checks) screen reports that the SAML library (vendor/) is missing.
- See [External authentication overview](guide:external-auth) for the shared rules (pending approval, keeping a local admin).

## Preparation

1. Apply **migrations 027 and 028** (`php tools/migrate.php`). Before 027, SAML behaves as if it were off; before 028, only the certificate expiry notifications are not created.
2. The **public address** is the same as for OIDC: `oidc.redirect_base`, or `app.base_url` when empty. It must be https (http only for loopback development addresses). The request's Host header is never used.
3. Get the IdP metadata URL (or XML) from your IdP administrator.

## Entering IdP values: importing metadata

Work on the **{{ui:config_settings.group.auth_saml}}** tab under System admin > [Settings](/admin/config-settings).

1. In the **{{ui:saml.import.title}}** area, enter the **{{ui:saml.import.url_label}}** (https) or paste XML into **{{ui:saml.import.xml_label}}**.
2. Click **{{ui:saml.import.run}}**. The IdP entity ID, IdP SSO URL, and signing certificate fields are filled in. If the metadata has two certificates, the next certificate field is filled in too (`auth.saml_metadata_import`).
3. Importing **only fills the fields and does not save.** Check the values, then save with the **Review setting changes** button at the bottom.

Import refuses XML that contains a DOCTYPE or entities. The errors are `saml_metadata_invalid` (not XML, required elements or certificate missing, over 1 MiB), `saml_metadata_url_invalid` (not https), and `saml_metadata_unreachable` (could not be fetched).

## Fields on the tab

- **{{ui:config_settings.key.saml.idp_entity_id.label}}** (`saml.idp_entity_id`): must match the Issuer in responses exactly.
- **{{ui:config_settings.key.saml.idp_sso_url.label}}** (`saml.idp_sso_url`): the SSO URL of the HTTP-Redirect binding (https).
- **{{ui:config_settings.key.saml.idp_cert.label}}** (`saml.idp_cert`): if you paste a PEM, only the body is kept. Verification uses the whole certificate, not a fingerprint.
- **{{ui:config_settings.key.saml.idp_cert_next.label}}** (`saml.idp_cert_next`): used during certificate replacement (see rollover below).
- **{{ui:config_settings.key.saml.sp_entity_id.label}}** (`saml.sp_entity_id`): when empty, `<public address>/auth/saml/metadata`.
- **{{ui:config_settings.key.saml.name_id_format.label}}** (`saml.name_id_format`): persistent by default. When NameID is the identifier, transient cannot be used (it changes at every sign-in).
- **{{ui:config_settings.key.saml.id_source.label}}** (`saml.id_source`): nameid, or the name of an attribute that never changes.
- **{{ui:config_settings.key.saml.attr_login.label}}** (`saml.attr_login`), **{{ui:config_settings.key.saml.attr_name.label}}** (`saml.attr_name`), **{{ui:config_settings.key.saml.attr_email.label}}** (`saml.attr_email`): the attribute Name (a URI is fine) or FriendlyName. The login ID must meet the app's login ID rules; name and email are used once, when the account is created.
- **{{ui:config_settings.key.saml.sign_requests.label}}** (`saml.sign_requests`): turn on only if the IdP requires it. Needs an SP key.
- **{{ui:config_settings.key.saml.want_encrypted.label}}** (`saml.want_encrypted`): when on, unencrypted assertions are refused. Needs an SP key, and the IdP must be set to encrypt with the SP certificate.
- **{{ui:config_settings.key.saml.button_label.label}}** (`saml.button_label`): up to 40 characters. When empty, "Sign in with SAML".
- **{{ui:config_settings.key.saml.enabled.label}}** (`saml.enabled`): turn it on last. It requires the IdP entity ID, SSO URL, certificate, login ID attribute, and an https public address.

Notes by provider:

- **ADFS**: metadata at `https://<adfs>/FederationMetadata/2007-06/FederationMetadata.xml`. Use relying party claim rules to send a persistent NameID, or send objectGUID as an attribute and use it as the identifier.
- **Entra ID**: the "App Federation Metadata Url" under Enterprise applications > SAML. The objectidentifier claim attribute is recommended as the identifier.
- **Okta**: the "Metadata URL" under the app's Sign On tab. Use the Persistent NameID format and define uid, displayName, and mail in Attribute Statements.
- **Keycloak**: `https://<host>/realms/<realm>/protocol/saml/descriptor`. Set the client's Name ID format to persistent and create uid, displayName, and mail with mappers.

## Registering the SP with the IdP

Register the SP information shown on the same tab with the IdP. Each value has a copy button.

- **{{ui:saml.sp.entity_id}}**: `<public address>/auth/saml/metadata` (or the SP entity ID you set)
- **{{ui:saml.sp.acs_url}}**: `<public address>/auth/saml/acs`
- **{{ui:saml.sp.metadata_url}}**: give this to the IdP if it accepts registration by metadata URL. If it needs a file, click **{{ui:saml.sp.metadata_download}}**. Metadata can be generated only after the IdP settings are saved.

The ACS URL is the only path that receives a cross-site POST from the IdP. The app checks the request ID, RelayState, and one-time use, then finishes the sign-in in the same browser. Verify in your production environment that no proxy in front blocks or rewrites this path.

## SP key

Needed only for request signing or encrypted assertions.

- **{{ui:saml.key.generate}}**: creates an RSA 2048 private key and a self-signed certificate (10 years) and stores them encrypted. The private key never appears in plain text on screen, in the API, in logs, or in backups (`auth.saml_sp_key_generate`; deletion is `auth.saml_sp_key_delete`).
- When a key exists, its certificate is included in the SP metadata.
- **{{ui:saml.key.replace}}** changes the certificate, so you must **register the SP metadata with the IdP again**.
- If the key shows as unreadable because the encryption key changed, recreate it and register again with the IdP.

## Settings check

**{{ui:saml.test.run}}** checks the following in order using the saved settings. It does not perform a real sign-in.

1. {{ui:saml.test.step.idp_cert}}: format and expiry (days left)
2. {{ui:saml.test.step.sso_url}}
3. {{ui:saml.test.step.sp}}
4. {{ui:saml.test.step.sp_metadata}}

The settings check and metadata import together are limited to {{limit:saml.test_per_minute}} per minute per admin (beyond that, `saml_test_rate_limited`). Turn the feature on once it passes. If OIDC is also on, the sign-in screen shows two buttons. When SAML is on, the [Checks](/admin/checks) screen shows an SSO (SAML) item.

## Certificate rollover and expiry warnings

When the IdP replaces its signing certificate:

1. Put the new certificate in **{{ui:config_settings.key.saml.idp_cert_next.label}}** first and save (metadata import fills it automatically when it finds two certificates). During this period, **responses signed with either certificate are accepted.**
2. The IdP switches to the new certificate.
3. After confirming that sign-in works, move the new certificate to **{{ui:config_settings.key.saml.idp_cert.label}}** and clear the next certificate field.

When a registered certificate (current or next) expires within {{limit:notification.cert_expiry_warn}}:

- The SAML tab shows the expiry date and days left as a warning, and the Checks screen shows a warning. Once expired, it becomes a failure.
- The worker checks every hour and sends admins an in-app notification (one per certificate per day).

The warning period is `notification.cert_expiry_warn` on the notification tab of Settings (the same value used for certificate warnings of allowed repository hosts).

## Common errors

Use the code on the sign-in screen and the reason in the audit log entry `auth.login_failed` (starting with saml_) to find the cause. Response content and attribute values are never recorded.

- **E-SSO-STATE**: no request record, reused or expired request (10 minutes), IdP-initiated sign-in, RelayState mismatch, or finished in another browser (reasons such as saml_request_unknown, saml_unsolicited, saml_relay_mismatch, saml_finish_invalid).
- **E-SSO-IDP**: the IdP returned a non-success status (saml_response.idp_status).
- **E-SSO-TOKEN**: the response failed verification: missing or wrong signature, assertion count, Destination, Audience, or Issuer mismatch, outside the time window (60 seconds of skew), replay, a weak algorithm such as SHA-1, a transient NameID, or no identifier (for example saml_response.bad_signature, saml_response.audience, saml_response.expired). Check the certificate, the SP entity ID and ACS URL registered at the IdP, and the server clock.
- **E-SSO-CONFIG**: turned off, missing settings, no SP key, or a certificate problem (saml_not_configured, saml_sp_key_missing).
- **E-SSO-RATE**: more than {{limit:saml.start_per_minute}} sign-in starts per minute from one IP, or the IP is blocked (saml_start_rate).
- **E-SSO-DENIED**: another account with the same login ID, an inactive account, or an invalid login ID.

Examples of settings check failures:

- "{{ui:saml.reason.not_configured}}"
- "{{ui:saml.reason.cert_expired}}": get a new certificate from the IdP administrator and enter it.
- "{{ui:saml.reason.sp_key_missing}}"
- "{{ui:saml.reason.unavailable}}"
- "{{ui:saml.reason.tls}}"

An IdP outage or certificate problem stops only SAML sign-in. Local admins can still sign in with their ID and password.

## Production checklist

This feature was tested only against a mock IdP, and the real flow under Apache with https was not checked. Verify in your production environment:

1. Metadata import fills the fields, and after saving, all four settings check steps pass.
2. The SP metadata (entity ID, ACS, and the SP certificate if needed) is registered with the IdP.
3. A first-time user sees the pending notice, and the Users screen shows one pending account with the sign-in method {{ui:account.auth_saml}}.
4. After approval and sign-in, My account shows the sign-in method {{ui:account.auth_saml}} and the name and email from the IdP attributes.
5. After the name is changed at the IdP, signing in again leaves the app name unchanged and uses the same account.
6. With a local account using the same login ID, sign-in is refused with E-SSO-DENIED and the local account is untouched.
7. Entering through an app tile in the IdP portal is refused with E-SSO-STATE. Prepare guidance for users.
8. Resending to the ACS with the browser back button is refused.
9. Rehearse a certificate rollover: register the next certificate → the IdP switches → sign in → move it to the current field.
10. If you use request signing or encryption, confirm sign-in together with the IdP-side settings.
11. Signing out signs out of the app only; the IdP session remains.
12. The audit log entry `auth.login_failed` contains only reasons, with no response content or attribute values.

## Related sections

- [External authentication overview](guide:external-auth)
- [SSO (OIDC) sign-in](guide:auth-oidc)
- [Managing accounts](guide:accounts)
- [Settings](guide:settings)
