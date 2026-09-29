# P2Flux Agent Paywall

WordPress plugin: AI agents pay in USDC (x402) to read what the site owner chooses. No WooCommerce.
All x402 logic lives in the P2Flux API (`POST /x402/paywall/challenge`, `POST /x402/paywall/redeem`);
the plugin only decides what is paid and asks the API.

```
includes/   rules (price), detector (agent), settings, client, gate (hooks), log, metabox, discovery
tests/      unit.php - offline, no WordPress
dev/        local-wp.sh (local site on :8082), router.php, mu-plugins/ (fake API, dev only),
            tests/integration.mjs (real HTTP, fake API), e2e/agent.mjs (Base Sepolia, official x402 agent),
            release-check.sh, build-zip.sh
```

```bash
php tests/unit.php
dev/local-wp.sh &                       # PHP_CLI_SERVER_WORKERS=4 for the concurrency check
node dev/tests/integration.mjs
cd dev/e2e && npm install && node agent.mjs    # needs SELLER_WALLET, P2FLUX_E2E_WALLET_PRIVATE_KEY
dev/build-zip.sh
```
