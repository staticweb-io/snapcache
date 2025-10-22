repo_root := `pwd`
wordpress_dir := "./dev/data/wordpress1"

alias b := build
alias fmt := format
alias t := test
alias u := update-deps
alias w := watch-dev

[private]
list:
    @# First command in the file is invoked by default
    @just --list

# Build plugin zip for GitHub release
build:
    nix build .#plugin

# Build plugin zip for wordpress.org release
build-wp-org:
    nix build .#pluginWpOrg

# Run development server
[working-directory('dev')]
dev CLEAN="false":
    {{ if CLEAN == "true" { "rm -rf data" } else { "" } }}
    nix run

# Format source and then check for unfixable issues
format: && _format-php
    just --fmt --unstable
    fd --glob "*.nix" -x nixfmt

_format-php:
    just _phpcbf || true && just _phpcs

_phpcbf:
    php ./vendor/bin/phpcbf -d memory_limit=512M --standard=./phpcs.xml --extensions=php src *.php

_phpcs:
    php ./vendor/bin/phpcs -d memory_limit=512M -s --standard=./phpcs.xml --extensions=php src *.php

# Run rector code transformations
rector: && _format-php
    # We sometimes get errors running without --debug
    php ./vendor/bin/rector --debug

# Validate and run tests in a sandbox
test: _validate _phpcs _test-integration

# Run integration tests in a sandbox
_test-integration:
    nix flake check ./dev

# Run integration tests against a live dev server
_test-integration-live:
    WORDPRESS_DIR="$(realpath {{ wordpress_dir }})" php ./vendor/bin/phpunit --testsuite Integration

# Run tests against a live dev server
test-live: _test-integration-live

_update-composer-deps: && update-hashes
    composer update

# Upgrade dependencies
update-deps: _update-flakes _update-composer-deps

_update-flakes:
    nix flake update
    fd flake.nix -j 4 -x bash -c 'echo "Updating flake inputs in {//}"; cd "{//}" && nix flake update --inputs-from "$0"' "{{ repo_root }}"

# Update the vendorHash after a composer.json change
update-hashes:
    ./bin/update-hashes

# Update the update.json file with current values
update-json:
    ./bin/update-json

_validate:
    composer validate --strict

_watch-dev-cmd: format _validate _test-integration

# Run formatters and dev server tests when files change
watch-dev:
    @watchexec --exts json,nix,php --on-busy-update=queue --stdin-quit -- just _watch-dev-cmd
