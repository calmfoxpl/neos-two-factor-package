# Neos Two-Factor Package

[![Build](https://github.com/calmfoxpl/neos-two-factor-package/actions/workflows/build.yml/badge.svg)](https://github.com/calmfoxpl/neos-two-factor-package/actions/workflows/build.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**English** | [Polski](README.pl.md)

Two-factor authentication for the Neos CMS backend. Each user chooses their second factor: a **passkey** (fingerprint, face or device PIN) or an **authenticator app** (TOTP).

## Features

- **A choice at setup:** the passkey is offered first, because it cannot be phished and there is nothing to retype. An authenticator app, with a QR code or a key to type by hand, comes next.
- **At sign-in:** after the password, a passkey takes one gesture and an app takes six digits. The page looks like Neos' own sign-in, including a logo or background the project changed.
- **A policy in the backend:** *Administration → Two-Factor Authentication*:
  - **required:** every backend user must pair something; until they do they only reach the setup page,
  - **optional:** each user decides in their own settings,
  - **turned off:** nobody is asked, and anything paired is kept for when the policy changes back.
- **Where users are managed:** a user's page in *Administration → User Management* (details and edit) shows their second factor with the same two actions, so a lost phone is dealt with where the user is. Administrators only.
- **An overview for administrators:** the policy module lists every backend account with what it has paired, and offers two actions per account:
  - **Reset:** removes everything paired, signs the user out everywhere and demands a new pairing at the next sign-in, whatever the policy says. For a lost phone.
  - **Turn off:** removes everything paired and leaves the rest to the policy.
- **Self-service for everyone:** *User → Two-Factor Authentication* lists the user's passkeys (with the date each was last used) and their app. Anyone can add a passkey on each device they use, pair or remove the app. Under a required policy the last method cannot be removed; adding the new one first and then removing the old one works.
- **The command line:** `./flow twofactor:reset <username> [--disable]`, `./flow twofactor:policy [--policy required|optional|disabled]` and `./flow twofactor:list`, for when nobody can reach the backend.
- **Guessing does not pay:** after five wrong codes (configurable) the session ends and the password has to be typed again.
- **Careful with secrets:** the authenticator secret is sealed with libsodium under a key derived from the installation's own Flow encryption key, and only lands on the account after a code it produced has been typed. A passkey is stored only after its signature has been verified, and only as a public key.
- **Careful with passkeys:**
  - user verification is required,
  - challenges are used once and bound to the session,
  - origins are checked strictly rather than by suffix,
  - only the account's own keys are accepted,
  - the signature counter is remembered, so a replayed response is noticed.

  Built on [lbuchs/webauthn](https://github.com/lbuchs/WebAuthn), which has no dependencies of its own.
- **Impersonation stays usable:** while an administrator acts as another user, the second factor that counts is the administrator's own.
- **Translations:** English and Polish. The pages speak the language each user chose for the backend.

## Requirements

| | Version |
|---|---|
| Neos | 8.3, 8.4, 9.x |
| PHP | 8.2, 8.3, 8.4, with `sodium` and `openssl` |
| Database | MySQL / MariaDB or PostgreSQL |
| Browser for passkeys | any current browser, over https (plain http works on `localhost` only) |

Tested on Neos 8.3.35 (Flow 8.3.20, PHP 8.2), Neos 8.4.8 (Flow 8.4.5, PHP 8.2) and Neos 9.1.10 (Flow 9.1.3, PHP 8.3), all on MariaDB.

This package replaces other second-factor packages for the backend, such as `sandstorm/neostwofactorauthentication`. Running two would ask users for two second factors; pick one.

## Installation

```bash
composer require calmfox/neos-two-factor-package

./flow doctrine:migrate
./flow flow:cache:flush
```

The migration adds two tables: `calmfox_twofactor`, one row per backend account that has paired something, and `calmfox_twofactor_policy`, which holds the policy once somebody chooses one in the backend. Deleting a user in Neos deletes their row.

Nothing has to be added to the project's `Routes.yaml`: the package answers its few addresses (`/neos/two-factor/...`) itself, right after Flow's router, so they work whether the project keeps its own `Configuration/Routes.yaml` or not.

**Before you deploy to a live site**, decide on the policy. The default is **required**, so at their next sign-in every user, including you, is sent to the setup page. That works, but may be a surprise. To introduce it gradually, start with:

```bash
./flow twofactor:policy --policy optional
```

Users then pair from *User → Two-Factor Authentication*, `./flow twofactor:list` shows who has, and once everyone is ready, `./flow twofactor:policy --policy required` makes it mandatory.

## Configuration

Who has to use a second factor is chosen in the backend. The rest is in `Settings.yaml`:

```yaml
Calmfox:
  TwoFactor:
    # until somebody chooses in the backend or with ./flow twofactor:policy
    defaultPolicy: required          # required | optional | disabled
    # providers whose users are asked; Neos signs editors in through Neos.Neos:Backend
    authenticationProviders:
      - 'Neos.Neos:Backend'
    totp:
      issuer: ''                     # label in the app; empty: the site's host name
      window: 1                      # 30-second steps either side of now still accepted
    passkeys:
      enabled: true
      name: ''                       # what the device calls this site; empty: host name
      domain: ''                     # relying party id, e.g. example.com; empty: request host
    maximumAttempts: 5               # wrong codes before the session ends
```

- **`passkeys.domain`:** set it before anybody pairs a passkey if the backend is reached under more than one host name. **Changing it later makes every paired passkey stop working.**
- **Another authentication provider** (single sign-on, for example) can be added to `authenticationProviders`. Its users also need the package's request pattern on that provider, as the package adds it to `Neos.Neos:Backend`: see `Neos.Flow.security.authentication.providers` in [Configuration/Settings.yaml](Configuration/Settings.yaml).

## How it works

**The guard** is a middleware placed right after Flow's security entry point, not a hook on the sign-in itself, because the question is not "did you just sign in" but "may this request go on". A session that never answered its challenge is stopped on every request, including one opened from a bookmark hours later, and including the frontend, where a backend session would otherwise show unpublished content. Its own pages and Neos' sign-in and sign-out are always allowed, so nobody who cannot answer is trapped. A request from a script (the content editor fetching JSON) gets a plain `403` instead of a redirect, and the next page the person loads takes them where they need to go.

**What counts as answered** is kept in the Flow session and records which account answered, so it cannot be inherited by whoever signs in next on the same browser. A fresh password wipes it, signing out destroys it, and answering gives the session a new id.

**One-time passwords** are RFC 6238 over RFC 4226 with the parameters every authenticator app uses: SHA-1, a 30-second step, six digits. They are implemented in this package rather than pulled in as a dependency: it is forty lines of arithmetic, and the RFC's own test vectors are in the test suite.

**A code is spent once.** A code is right for as long as its thirty-second step lasts, and a step either side of now is accepted for clocks that drift, so the same six digits would otherwise answer twice: read off a screen share, seen over a shoulder, or replayed from a captured request. The newest step an account has used is recorded, and anything from that step or earlier is refused. The code typed to pair the app counts as spent too. The record is claimed with a single conditional `UPDATE` rather than a read and then a write, because two requests arriving together with the same code would both pass the latter.

**Passkeys** are the one second factor phishing cannot relay: the signature covers the address of the site, so a look-alike page never gets a valid one. The library accepts any host that merely ends with the configured domain, so the package checks the origin itself: the exact site or a real subdomain, over https, with plain http allowed only on `localhost` as browsers do.

**Setting up leaves nothing half-done.** The authenticator secret waits in the session and only reaches the account once a code it produced has been typed; a passkey is stored only once its signature verifies.

**The secret at rest.** Flow has no encryptor for values that have to be read back, and a TOTP secret has to be. It is sealed with libsodium's secretbox under a key derived (HKDF) from the installation's Flow encryption key, so a dump of the table is not enough to produce codes. The flip side: a new Flow encryption key makes stored secrets unreadable. Those users are then treated as having no app and asked to pair again; passkeys are not affected.

## Recovering an account

If a user loses their phone, an administrator resets them on the user's page in *Administration → User Management*, or in the overview under *Administration → Two-Factor Authentication*. If nobody can sign in at all:

```bash
./flow twofactor:reset anna
```

They keep their password and pair a new device at the next sign-in. `--disable` removes what is paired without demanding a new pairing, and the policy then decides. `--provider` selects an account of another authentication provider.

## Appearance

- **Pages:** the verification and setup pages reuse Neos' sign-in stylesheets, logo and background (`Neos.Neos.userInterface.backendLoginForm`), so a project that changed those sees them here too. The templates are in `Resources/Private/Templates/Challenge`, `Setup` and `Module`, and can be replaced with Flow's `Views.yaml`.
- **User management:** the section on a user's page comes from replacing only the page frame (`BackendSubModule` layout) of Neos' user management with `Resources/Private/Layouts/NeosUsersModule`, never Neos' templates, see [Configuration/Views.yaml](Configuration/Views.yaml). Neos uses one frame per action, so the Calmfox packages share one: each registers its section under `Calmfox.UserManagement.sections` (here `Calmfox\TwoFactor\UserManagement\TwoFactorSection`), and the frame of either package renders all of them, so [calmfox/neos-admin-invitation-package](https://github.com/calmfoxpl/neos-admin-invitation-package) and this package can be installed together. A project that replaces the same layout renders the sections with `<cf2fa:userSections user="{user}" />`.
- **CSS:** everything the package adds is prefixed `cf2fa-`.
- **Texts:** `Resources/Private/Translations/{en,pl}/Main.xlf`.

## What this does not cover

- **Trusted devices.** Every sign-in is challenged. There is no "remember this browser for 30 days".
- **The content editor's own re-login.** When a session expires inside the content editor, Neos offers to sign in again in a dialog, which has no room for a second factor. After typing the password there, reload the page: the verification page follows.
- **Frontend logins.** The package guards the backend. Frontend users signing in through their own provider are not asked, unless that provider is added to `authenticationProviders`.

## Development

The unit tests cover the framework-free core (the one-time passwords against the RFC's own test vectors, the base32 they travel in, the policy decisions, the passkey bookkeeping, the sealed secret and the page addresses) plus the wiring that only meets inside Neos: every translation id in both languages, a privilege for every controller action, the modules in the settings. They need neither Neos nor a database:

```bash
phpunit -c phpunit.xml.dist
```

Any PHPUnit 10 or newer works; the package needs no `composer install` of its own.

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

## License

[MIT](LICENSE)
