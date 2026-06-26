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

      pkgsFor =
        system:
        import nixpkgs {
          inherit system;
          overlays = [ composition-c4.overlays.default ];
        };
    in
    {
      devShells = forAllSystems (
        system:
        let
          pkgs = pkgsFor system;
        in
        {
          # ---------------------------------------------------------------- #
          # Development shell: devenv-managed environment                    #
          # ---------------------------------------------------------------- #
          default = devenv.lib.mkShell {
            inherit pkgs inputs;
            modules = [
              (
                {
                  pkgs,
                  lib,
                  config,
                  ...
                }:
                {
                  packages = with pkgs; [
                    wp-cli
                    php83.packages.composer
                  ];

                  languages.php = {
                    enable = true;
                    package = pkgs.php83;
                    extensions = [
                      "mysqli"
                      "pdo_mysql"
                      "gd"
                      "intl"
                      "zip"
                      "openssl"
                    ];
                    fpm.pools.php = {
                      settings = {
                        "pm" = "dynamic";
                        "pm.max_children" = 5;
                        "pm.start_servers" = 2;
                        "pm.min_spare_servers" = 1;
                        "pm.max_spare_servers" = 3;
                      };
                    };
                  };

                  services.mysql = {
                    enable = true;
                    package = pkgs.mariadb;
                    initialDatabases = [
                      { name = "wordpress_dev"; }
                    ];
                  };

                  services.caddy = {
                    enable = true;
                    config = ''
                      http://localhost:8002 {
                        root * ./vendor/johnpbloch/wordpress-core
                        php_fastcgi unix/${config.languages.php.fpm.pools.php.socket}
                        file_server
                      }
                    '';
                  };

                  scripts = {
                    setup.exec = ''
                        echo "🚀 Setting up WordPress development environment..."

                      # Install composer deps — places WordPress core at ./wordpress/
                      composer install --no-interaction

                      # Configure WordPress
                      if [ ! -f vendor/johnpbloch/wordpress-core/wp-config.php ]; then
                        echo "📝 Creating wp-config.php..."
                        wp config create \
                          --path=vendor/johnpbloch/wordpress-core \
                          --dbname=wordpress_dev \
                          --dbuser=root \
                          --dbhost=localhost \
                          --extra-php <<PHP
                          define( 'WP_HOME', 'http://localhost:8002' );
                          define( 'WP_SITEURL', 'http://localhost:8002' );
                          PHP
                      fi

                      # Symlink plugin into WordPress
                      mkdir -p vendor/johnpbloch/wordpress-core/wp-content/plugins
                      echo "🔗 Symlinking WP Cloud Files plugin..."
                      ln -sfn "$(pwd)" vendor/johnpbloch/wordpress-core/wp-content/plugins/wp-cloud-files

                      echo "✅ Setup complete! Run 'devenv up' to start services."
                    '';
                    upgrade-deps.exec = ''
                      echo "🔄 Upgrading composer dependencies..."
                      composer update --no-interaction
                      echo "✅ Dependencies upgraded."
                      echo "🔄 Upgrading npm dependencies..."
                      npm run upgrade
                      echo "✅ npm dependencies upgraded."
                      echo "🔄 Upgrading nix dependencies..."
                      nix flake update
                      echo "✅ nix dependencies upgraded."
                    '';
                  };

                  env.WP_CLI = "${pkgs.wp-cli}/bin/wp";
                }
              )
            ];
          };
        }
      );

      packages = forAllSystems (
        system:
        let
          pkgs = pkgsFor system;
          php = pkgs.php83;
          inherit (pkgs) lib stdenvNoCC;
          composerData = builtins.fromJSON (builtins.readFile ./composer.json);

          pname = "wp-cloud-files";
          version = composerData.version;
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

              # Stamp the WordPress plugin header version from composer.json, which is the
              # single source of truth (Release Please bumps it). WordPress and the update
              # checker read this header to detect new versions.
              sed -i -E "s|^([[:space:]]*\* Version:[[:space:]]*).*|\1${version}|" "$pluginDir/index.php"

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
    };
}
