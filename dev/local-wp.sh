#!/usr/bin/env bash
# A local WordPress for this plugin only: ~/projects/p2flux_wp_paywall, database p2flux_paywall on the
# development MySQL (127.0.0.1:3307), served by `php -S` on http://localhost:8082.
#
#   dev/local-wp.sh          install if missing, then serve (in the foreground)
#   dev/local-wp.sh install  install only
#
# The plugin is symlinked, so edits are live. The fake API mu-plugin is linked too; it is inert until
# the option p2flux_ap_fake exists (the integration test sets it, the Sepolia end-to-end clears it).
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
site="$HOME/projects/p2flux_wp_paywall"
port=8082
wp() { command wp --path="$site" "$@"; }

if [ ! -f "$site/wp-config.php" ]; then
  mkdir -p "$site"
  wp core download --version=7.1.1 --skip-content >/dev/null
  mkdir -p "$site/wp-content/plugins" "$site/wp-content/mu-plugins" "$site/wp-content/themes"
  wp config create --dbname=p2flux_paywall --dbuser=root --dbpass= --dbhost=127.0.0.1:3307 --skip-check >/dev/null
  wp config set WP_DEBUG true --raw >/dev/null
  wp config set WP_DEBUG_LOG true --raw >/dev/null
  wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
  wp db create >/dev/null 2>&1 || true
  cp -r "$HOME/projects/p2flux_wp_fresh/wp-content/themes/"twentytwenty* "$site/wp-content/themes/" 2>/dev/null || true
  wp core install --url="http://localhost:$port" --title="Paywall test site" --admin_user=admin --admin_password=admin --admin_email=dev@example.test --skip-email >/dev/null
  wp rewrite structure '/%postname%/' >/dev/null
fi
ln -sfn "$here" "$site/wp-content/plugins/p2flux-agent-paywall"
ln -sfn "$here/dev/mu-plugins/p2flux-ap-fake-api.php" "$site/wp-content/mu-plugins/p2flux-ap-fake-api.php"
wp plugin activate p2flux-agent-paywall >/dev/null

[ "${1:-}" = "install" ] && exit 0
cd "$site" && exec php -S "localhost:$port" -t "$site" "$here/dev/router.php"
