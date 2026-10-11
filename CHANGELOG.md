# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-11

### Added

- Two-factor authentication for the Neos backend, with a choice of passkey (WebAuthn) or
  authenticator app (TOTP), for Neos 8.3 and 9.
- A setup page offering the passkey first and a QR code for the app; secrets are stored only
  after they have been proved to work, and the app secret is sealed with libsodium.
- A policy (required, optional, turned off) and an overview of every backend account under
  Administration, with reset and turn off per account.
- Reset and turn off on a user's page in Neos' own user management, for administrators.
  The section is registered in `Calmfox.UserManagement.sections`, shared with other Calmfox
  packages, so their sections and this one appear together.
- The user's own methods under User: add passkeys, pair or remove the app.
- `./flow twofactor:reset`, `twofactor:policy` and `twofactor:list`.
- English and Polish translations.

[Unreleased]: https://github.com/calmfoxpl/neos-two-factor-package/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/calmfoxpl/neos-two-factor-package/releases/tag/v1.0.0
