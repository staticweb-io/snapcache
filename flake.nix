{
  description = "SnapCache plugin for WordPress";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-25.05";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs =
    {
      self,
      nixpkgs,
      flake-utils,
      ...
    }:
    flake-utils.lib.eachDefaultSystem (
      system:
      with import nixpkgs { inherit system; };
      with pkgs;
      let
        name = "snapcache";
        version = "0.1.0";
        composerSrc = pkgs.lib.cleanSourceWith {
          src = self;
          filter =
            path: type:
            let
              rel = baseNameOf path;
            in
            rel == "composer.json" || rel == "composer.lock";
        };
        composerVendor = php.mkComposerVendor (finalAttrs: {
          composerNoDev = false;
          pname = "${name}-composer-deps-dev";
          version = "1.0.0";
          src = composerSrc;
          vendorHash = "sha256-fgxhTbsl8wPCx30AebLIZx3GNWySo761vrLbthWnWDs=";
        });
        snapCacheSrc = pkgs.lib.cleanSourceWith {
          src = self;
          filter =
            path: type:
            let
              base = baseNameOf path;
            in
            type == "directory" && base == "src"
            || pkgs.lib.hasInfix "/src/" path
            || type == "directory" && base == "tests"
            || pkgs.lib.hasInfix "/tests/" path
            || type == "directory" && base == "util"
            || pkgs.lib.hasInfix "/util/" path
            || type == "directory" && base == "views"
            || pkgs.lib.hasInfix "/views/" path
            || type == "regular" && pkgs.lib.hasSuffix ".php" base
            || base == "composer.json"
            || base == "composer.lock"
            || base == "justfile"
            || base == "phpcs.xml"
            || base == "phpunit.xml"
            || base == "readme.txt";
        };
        releaseExtras = pkgs.lib.cleanSourceWith {
          src = self;
          filter =
            path: type:
            let
              base = baseNameOf path;
            in
            type == "directory" && base == "release" || pkgs.lib.hasInfix "/release/" path;
        };
        buildsnapCacheSrc =
          constantsFile:
          runCommand "snapcache-source"
            {
              nativeBuildInputs = [
                just
                php
                phpPackages.composer
              ];
            }
            ''
              export PLUGIN_DIR="$TMPDIR/${name}"
              mkdir -p "$PLUGIN_DIR"
              cp -r --no-preserve=mode "${composerVendor}/vendor" .
              cp -r --no-preserve=mode "${snapCacheSrc}"/* .

              # Lock certain constants and run rector to remove dead code
              cp ${constantsFile} constants.php
              just rector

              composer install --no-cache --no-dev --optimize-autoloader

              mkdir -p "$out"
              cp -r composer.json plugin.php readme.txt src uninstall.php vendor "$out"
            '';
        snapCacheWpOrgSrc = buildsnapCacheSrc "${releaseExtras}/release/wp-org/constants.php";
        snapCacheGitHubSrc = buildsnapCacheSrc "${releaseExtras}/release/github/constants.php";
        pluginZip =
          source:
          runCommand name { } ''
            mkdir "$TMPDIR/${name}"
            cd "$TMPDIR/${name}"
            ln -s "${source}" "${name}"
            mkdir -p "$out"
            ${zip}/bin/zip -r -9 "$out"/"${name}.zip" "${name}"
          '';
        snapCache = pluginZip snapCacheGitHubSrc;
        snapCacheWpOrg = pluginZip snapCacheWpOrgSrc;
        snapCacheCheck = stdenv.mkDerivation {
          pname = "snapcache-check";
          version = version;

          src = snapCacheSrc;

          nativeBuildInputs = [
            bash
            php
          ];
          nativeCheckInputs = [
            jq
            just
            phpPackages.composer
          ];

          doCheck = true;
          dontUseJustInstall = true;

          buildPhase = ''
            mkdir -p $out
          '';

          checkPhase = ''
            export PLUGIN_DIR="$TMPDIR/${name}"
            mkdir -p "$PLUGIN_DIR"
            cd "$PLUGIN_DIR"
            cp -a "${composerVendor}/vendor" .
            cp -r --no-preserve=mode "$src"/* .
            just _check_no_test
          '';
        };
      in
      {
        checks = { inherit snapCacheCheck; };
        lib = { inherit snapCacheSrc; };
        packages = {
          inherit composerVendor snapCache;
          plugin = snapCache;
          pluginGitHubSrc = snapCacheGitHubSrc;
          pluginWpOrg = snapCacheWpOrg;
          pluginWpOrgSrc = snapCacheWpOrgSrc;
        };
      }
    );
}
