# SnapCache

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/staticweb-io/snapcache)

Memcached object cache for WordPress.

## Installation

Download a zip file from the
[releases page](https://github.com/staticweb-io/snapcache/releases).
and install it in your WordPress site.

**Advanced:** If you have
[Nix](https://docs.determinate.systems/determinate-nix/#getting-started)
installed, you can build from source via
`nix build github:staticweb-io/snapcache#plugin`.
This will create a zip file at `result/snapcache.zip`
which you can then install in your WordPress site.

## Support

Please
[open an issue](https://github.com/staticweb-io/snapcache/issues/new)
for new support questions.

## Documentation

A
[DeepWiki](https://deepwiki.com/staticweb-io/snapcache)
is available.

## Development

Development requires installing
[Nix](https://docs.determinate.systems/determinate-nix/#getting-started).
You can enter a development shell via `nix develop ./dev`,
or automatically using [direnv](https://direnv.net/).

After checking out this repository and making changes,
you can build the plugin with your changes by running
`just build`.
This will create a zip file at `result/snapcache.zip`.

There is a slightly different build for the WordPress.org repo.
This has a few differences like performing direct file access.
You can build it with `just build-wp-org`.

If you make changes to composer.json, or composer.lock,
you will need to update the vendorHashes in flake.nix
by running
`just update-hashes`.

You can run the development environment via
`just dev`.
This starts MySQL, PHP-FPM, and Nginx running WordPress
with this plugin installed.
The WordPress site is available at
`http://localhost:8889`
with credentials "user" and "pass".

The `release` dir contains the `constants.php` files that are used for each build.
[Rector](https://github.com/rectorphp/rector) rewrites the code for each build based on the defined constants.

### Testing

To run the tests, run `just test` from
within this repository.

Test results are available on the
[actions page](https://github.com/staticweb-io/snapcache/actions).
