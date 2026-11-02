# Changelog

## 0.1.1

Security hardening following a complete package source audit. The audit found no
confirmed exploitable vulnerability; these changes add defensive controls.

- Bound lockfile and outdated-report reads, require local regular files, limit
  JSON nesting and validate input shapes before sending inventory.
- Reject website URLs containing credentials, query parameters or fragments.
- Keep TLS verification enabled when host HTTP defaults disable it, while
  preserving configured custom CA-bundle paths.
- Bound and deduplicate update targets; stop excessive dependency collection early.
- Restrict CI token permissions and add Composer advisory checks.
- Clarify the inventory privacy description and document input limits.

## 0.1.0

Initial Laravel 12 connector with installed Composer/runtime inventory, optional
outdated reports, website-bound key authentication, dry run and scheduled pushes.
