## Unreleased

# 1.2.1 (2026-09-17)

- Fix that delete_multiple returned results by internal cache key,
  not the caller's key.
- Fix that a set_multiple() followed by a get() to one of the same keys,
  during the same request, could return the input array to set_multiple
  instead of the proper value.

# 1.2.0 (2026-09-17)

- Use TCP no-delay by default. This greatly speeds up small requests.
- Show memcached stats on the plugin admin page.

# 1.1.1 (2026-07-31)

- Fix a packaging issue that added dev dependencies to the plugin zip in 1.1.0.

## 1.1.0 (2026-07-30)

- Verify support for WordPress 7.1.
- Fix missing link to Settings on plugins page.
- Remove code that disables some default WordPress actions.

## 1.0.1 (2026-04-01)

- Indicate support for WordPress 7.0.

## 1.0.0 (2025-11-21)

- Initial release.
