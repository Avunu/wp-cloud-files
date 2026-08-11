{
  description = "WP Cloud Files WordPress plugin";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    composition-c4.url = "github:fossar/composition-c4";
    devenv.url = "github:cachix/devenv";
  };

  outputs =
    {
      self,
      nixpkgs,
      composition-c4,
      devenv,
      ...
    }@inputs:
    let
      systems = [
        "x86_64-linux"
        "aarch64-linux"
        "x86_64-darwin"
        "aarch64-darwin"
      ];
      forAllSystems = nixpkgs.lib.genAttrs systems;

      # ------------------------------------------------------------------ #
      # Plugin metadata, read once and shared by packages/checks.           #
      # composer.json owns the version (Release Please bumps it); the       #
      # index.php plugin header owns the WordPress compatibility range.     #
      # ------------------------------------------------------------------ #
      composerData = builtins.fromJSON (builtins.readFile ./composer.json);
      version = composerData.version;

      # Read a plugin header field out of index.php. That file is the single
      # source of truth for WordPress compatibility because plugin-update-checker
      # fetches index.php *from the git tag* and its headers override everything
      # else (Puc/v5p7/Vcs/PluginUpdateChecker.php:78-85). Duplicating the value
      # into composer.json would create a second copy that PUC never reads.
      pluginHeader =
        field:
        let
          lines = nixpkgs.lib.splitString "\n" (builtins.readFile ./index.php);
          pattern = "[[:space:]]*\\*[[:space:]]*${field}:[[:space:]]*([^[:space:]]+)[[:space:]]*";
          hit = nixpkgs.lib.findFirst (l: builtins.match pattern l != null) null lines;
        in
        if hit == null then
          throw "index.php is missing the plugin header line ' * ${field}: <value>'"
        else
          builtins.head (builtins.match pattern hit);

      # plugin-update-checker's fixSupportedWordpressVersion() silently discards
      # anything that is not bare major.minor, and WordPress then reports
      # "Compatibility: Unknown" (Puc/v5p7/UpdateChecker.php:463).
      requireMajorMinor =
        field: value:
        if builtins.match "[0-9]+\\.[0-9]+" value == null then
          throw "index.php '${field}: ${value}' must be bare major.minor (e.g. 6.9)"
        else
          value;

      wpTested = requireMajorMinor "Tested up to" (pluginHeader "Tested up to");
      wpRequires = requireMajorMinor "Requires at least" (pluginHeader "Requires at least");

      pkgsFor =
        system:
        import nixpkgs {
          inherit system;
          overlays = [ composition-c4.overlays.default ];
        };

      # nixpkgs marks MinIO insecure (upstream abandoned it) and suggests Garage.
      # We keep MinIO for the test S3 server anyway, and confine the exception to
      # the development/CI shells -- packages.* and checks.* still use the strict
      # pkgsFor above, so nothing insecure can reach a released artifact.
      #
      # Justification: the server binds loopback only, holds throwaway
      # credentials, and its bucket is deliberately world-readable so the suite
      # can prove a rewritten public URL actually resolves. Every listed CVE is an
      # auth-bypass or DoS against data we are publishing on purpose and delete
      # between tests.
      #
      # Migration path if nixpkgs drops it: devenv also ships services.garage.
      # Garage needs website-mode plus a resolvable bucket subdomain for
      # anonymous reads, so that assertion has to be reworked at the same time.
      pkgsForShell =
        system:
        import nixpkgs {
          inherit system;
          overlays = [ composition-c4.overlays.default ];
          config.allowInsecurePredicate = pkg: nixpkgs.lib.getName pkg == "minio";
        };
    in
    {
      devShells = forAllSystems (
        system:
        let
          pkgs = pkgsForShell system;

          mkEnv =
            { forCI }:
            devenv.lib.mkShell {
              inherit pkgs inputs;
              modules = [ (import ./nix/devenv-module.nix { inherit forCI; }) ];
            };
        in
        {
          # Interactive development: adds caddy + php-fpm on top of the CI services.
          default = mkEnv { forCI = false; };

          # Same MariaDB and MinIO, no web server, and an enterTest that runs the
          # whole suite. Driven in CI by `devenv-flake-test` -- NOT `devenv test`,
          # which re-execs `nix develop .#default` and would run the wrong shell.
          ci = mkEnv { forCI = true; };
        }
      );

      packages = forAllSystems (
        system:
        let
          pkgs = pkgsFor system;
          php = pkgs.php83;
          inherit (pkgs) lib stdenvNoCC;

          pname = "wp-cloud-files";
          src = self;

          # -------------------------------------------------------------- #
          # PHP / Composer vendor dependencies                               #
          # c4.fetchComposerDeps reads composer.lock per-package via        #
          # builtins.fetchGit — no hash needed.                             #
          # -------------------------------------------------------------- #
          composerDeps = pkgs.c4.fetchComposerDeps {
            inherit src;
          };

          # ---------------------------------------------------------------- #
          # Final plugin assembly                                            #
          # ---------------------------------------------------------------- #
          pluginPackage = stdenvNoCC.mkDerivation {
            inherit
              pname
              version
              src
              composerDeps
              ;

            nativeBuildInputs = [
              php
              php.packages.composer
              pkgs.c4.composerSetupHook
            ];

            buildPhase = ''
              runHook preBuild
              composer --no-ansi install --no-dev --no-interaction --optimize-autoloader
              runHook postBuild
            '';

            installPhase = ''
              runHook preInstall

              pluginDir="$out/share/wordpress/plugins/wp-cloud-files"
              mkdir -p "$pluginDir"

              cp index.php README.md LICENSE "$pluginDir/"
              # -L dereferences: composition-c4 installs vendor/ as symlinks into the
              # Nix store; the distributable plugin must contain real, self-contained files.
              # assets/ holds the browser JS (plain, type-checked but not compiled).
              cp -rL src vendor assets "$pluginDir/"

              # A `sed` substitution that matches nothing still exits 0, so guard every
              # header we stamp -- otherwise a renamed or deleted line ships silently wrong.
              for header in 'Version' 'Tested up to' 'Requires at least'; do
                grep -qE "^[[:space:]]*\* $header:" "$pluginDir/index.php" \
                  || { echo "index.php is missing the '$header:' plugin header line" >&2; exit 1; }
              done

              # Stamp the WordPress plugin header version from composer.json, which is the
              # single source of truth (Release Please bumps it). WordPress and the update
              # checker read this header to detect new versions.
              sed -i -E "s|^([[:space:]]*\* Version:[[:space:]]*).*|\1${version}|" "$pluginDir/index.php"

              # Re-stamp the compatibility range from the values Nix parsed out of the
              # committed index.php. These are already correct in the source file -- this
              # only guarantees the zip can never drift from what the flake evaluated,
              # and it fails the build if the header stops being bare major.minor.
              sed -i -E "s|^([[:space:]]*\* Tested up to:[[:space:]]*).*|\1${wpTested}|" "$pluginDir/index.php"
              sed -i -E "s|^([[:space:]]*\* Requires at least:[[:space:]]*).*|\1${wpRequires}|" "$pluginDir/index.php"

              runHook postInstall
            '';

            meta = {
              description = composerData.description;
              license = lib.licenses.gpl3;
              platforms = lib.platforms.all;
            };
          };
        in
        {
          default = pluginPackage;

          # ---------------------------------------------------------------- #
          # Deterministic, ready-to-install zip (top-level wp-cloud-files/). #
          # nix build .#zip -> result/wp-cloud-files.zip                     #
          # ---------------------------------------------------------------- #
          zip = stdenvNoCC.mkDerivation {
            name = "wp-cloud-files-zip-${version}";
            nativeBuildInputs = [ pkgs.zip ];
            buildCommand = ''
              mkdir -p tmp/wp-cloud-files
              cp -r ${pluginPackage}/share/wordpress/plugins/wp-cloud-files/. tmp/wp-cloud-files/
              chmod -R u+w tmp
              mkdir -p "$out"
              (cd tmp && zip -r -X "$out/wp-cloud-files.zip" wp-cloud-files)
            '';
          };
        }
      );

      # -------------------------------------------------------------------- #
      # Checks that need no services, so they can run in a pure derivation.   #
      #                                                                       #
      # `nix flake check` does NOT work here: it evaluates devShells in pure  #
      # mode, and devenv reads PWD via builtins.getEnv. Build the attributes  #
      # directly instead:                                                     #
      #                                                                       #
      #   nix build .#checks.x86_64-linux.unit -L                             #
      #                                                                       #
      # The WordPress integration suite deliberately lives outside this set:  #
      # it needs a live MariaDB and MinIO, which devenv already manages.      #
      # -------------------------------------------------------------------- #
      checks = forAllSystems (
        system:
        let
          pkgs = pkgsFor system;
          inherit (pkgs) stdenvNoCC;

          php = pkgs.php83.buildEnv {
            extensions = { all, enabled }: enabled ++ [ all.imagick ];
            extraConfig = ''
              memory_limit = 512M
              error_reporting = E_ALL
            '';
          };

          pluginPackage = self.packages.${system}.default;
          pluginDir = "${pluginPackage}/share/wordpress/plugins/wp-cloud-files";

          # The test runners live in their own Composer project so they never
          # become build inputs of `nix build .#zip` -- fetchComposerDeps clones
          # lock.packages ++ lock.packages-dev with allRefs=true at eval time.
          testTools = stdenvNoCC.mkDerivation {
            pname = "wp-cloud-files-test-tools";
            inherit version;
            src = ./tests/tools;

            composerDeps = pkgs.c4.fetchComposerDeps {
              lockFile = ./tests/tools/composer.lock;
            };

            nativeBuildInputs = [
              php
              php.packages.composer
              pkgs.c4.composerSetupHook
            ];

            buildPhase = ''
              runHook preBuild
              composer --no-ansi install --no-interaction
              runHook postBuild
            '';

            installPhase = ''
              runHook preInstall
              mkdir -p "$out"
              # -L for the same reason as the plugin build: c4 installs vendor as
              # symlinks into the store, and phpunit resolves paths through them.
              cp -rL vendor "$out/"
              runHook postInstall
            '';
          };

          # Run the pure PHPUnit suites once per configuration profile. The
          # plugin reads its settings from immutable global constants, so
          # "with S3_ROOT" and "without" cannot share a process.
          mkPhpunitCheck =
            {
              name,
              testsuite,
              profiles,
              extraInputs ? [ ],
              preRun ? "",
            }:
            pkgs.runCommand "check-${name}"
              {
                nativeBuildInputs = [ php ] ++ extraInputs;
                src = self;
              }
              ''
                set -euo pipefail

                cp -r "$src" ./work
                chmod -R u+w ./work
                cd ./work

                # The plugin's production dependencies come from the already-built
                # package rather than a second composer install.
                export WPCF_VENDOR=${pluginDir}/vendor
                ln -s ${testTools}/vendor tests/tools/vendor

                export HOME="$TMPDIR"
                export TMPDIR="$TMPDIR"
                ${preRun}

                for profile in ${builtins.concatStringsSep " " profiles}; do
                  echo "==> ${testsuite} suite, profile=$profile"
                  # Invoke through the interpreter rather than the shebang:
                  # vendor/bin/phpunit ships "#!/usr/bin/env php", and /usr/bin/env
                  # does not exist inside the build sandbox.
                  WPCF_TEST_PROFILE="$profile" \
                    php ${testTools}/vendor/bin/phpunit \
                      -c tests/phpunit-unit.xml \
                      --testsuite=${testsuite} \
                      --do-not-cache-result
                done

                touch "$out"
              '';
        in
        {
          # Fails the build when the committed plugin header drifts from
          # composer.json. This matters because plugin-update-checker reads
          # index.php from the git tag, so the committed value is what users
          # actually see -- the build-time stamp cannot rescue a wrong commit.
          plugin-header = pkgs.runCommand "check-plugin-header" { src = self; } ''
            set -euo pipefail

            get() {
              sed -n -E "s|^[[:space:]]*\* $1:[[:space:]]*([^[:space:]]+)[[:space:]]*$|\1|p" "$src/index.php"
            }

            fail=0
            check() {
              if [ "$2" != "$3" ]; then
                echo "index.php '$1: $2' does not match $4 ($3)" >&2
                fail=1
              fi
            }

            check 'Version'           "$(get 'Version')"           '${version}'    'composer.json version'
            check 'Tested up to'      "$(get 'Tested up to')"      '${wpTested}'   'the value Nix parsed'
            check 'Requires at least' "$(get 'Requires at least')" '${wpRequires}' 'the value Nix parsed'

            if [ "$fail" -ne 0 ]; then
              echo "" >&2
              echo "plugin-update-checker fetches index.php from the GitHub tag, not from" >&2
              echo "the built zip, so the committed header must already be correct." >&2
              exit 1
            fi

            echo "index.php header is consistent (version ${version}, tested up to ${wpTested})"
            touch "$out"
          '';

          unit = mkPhpunitCheck {
            name = "unit";
            testsuite = "unit";
            profiles = [
              "default"
              "root"
              "maxsize"
            ];
          };

          thumbnails = mkPhpunitCheck {
            name = "thumbnails";
            testsuite = "thumbnails";
            profiles = [ "default" ];
            # ImageMagick's pdf/ps delegates shell out to `gs`, and dompdf/PhpWord
            # need a resolvable font set inside the sandbox.
            extraInputs = [
              pkgs.ghostscript
              pkgs.fontconfig
            ];
            preRun = ''
              export FONTCONFIG_FILE=${
                pkgs.makeFontsConf {
                  fontDirectories = [
                    pkgs.dejavu_fonts
                    pkgs.liberation_ttf
                  ];
                }
              }
              export MAGICK_TEMPORARY_PATH="$TMPDIR"

              php -r 'exit(extension_loaded("imagick") ? 0 : 1);' \
                || { echo "imagick extension is not loaded" >&2; exit 1; }
            '';
          };
        }
      );
    };
}
