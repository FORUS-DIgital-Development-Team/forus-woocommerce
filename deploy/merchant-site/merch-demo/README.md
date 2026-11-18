# FORUS merchandise demonstration

This is an optional, explicitly installed extension for the existing shop at `https://shop.forus.digital`. It adds six merchandise concepts, ten clothing size variations, a responsive classic WooCommerce theme and simulated eZAR/WOW checkout methods. It does not change the FORUS payment adapter or the merchant backend.

## Financial boundary

The two methods are **simulations**, not provider sandbox transactions. They never call a provider, connect a wallet, transfer tokens, call `payment_complete`, reduce stock or offer refunds. The final status is `forus-demo` (displayed as “Demo complete (unpaid)”). It is not a paid status. The original Ecentric order cannot use these gateways. Only marked catalogue products are accepted.

The plugin requires the explicit `forus_merch_demo_enabled=yes` option, WordPress environment `staging`, and both configured URLs exactly `https://shop.forus.digital`. It fails closed elsewhere. The real `forus` gateway stays disabled; the backend live/provider/refund flags are untouched. Guest checkout uses a fictional shopper, has no address or wallet fields, and suppresses transactional emails for marked demo orders. The front end, checkout, confirmation and FAQ disclose the simulation.

eZAR and WOW's existing Rail Connector adapter was inspected. It targets CloudGate and defaults to a production environment unless explicitly configured otherwise. This demo deliberately does not bind those credentials or call that adapter. Connecting actual provider sandbox wallets is a separate integration and acceptance task.

## Install and recovery

Transfer the `merch-demo` directory to a release directory on the dedicated VM and run `sudo bash install-merch.sh`. The installer checks the host and disabled real gateway, lints every PHP file, and creates a root-only database/file backup before changes. It installs only its own theme/plugin directories. The repeatable seed uses dedicated SKUs and attachment IDs and preserves unrelated products and orders. The original R1 test product remains published but hidden from the catalogue.

Original theme, page content and changed options are recorded in the `forus_merch_original_state` option. To stop the public simulation, set `forus_merch_demo_enabled=no`; this immediately removes both demo methods. Restore the recorded theme and cart/checkout content if reverting the storefront. Do not restore an old whole database over newly created orders. Retain demo and provider evidence.

Run the meaningful deployed checks through WP-CLI with this release directory mounted read-only:

```sh
docker compose --env-file .env --env-file images.env run --rm -T \
  -v /absolute/release/merch-demo:/merch-release:ro cli eval-file /merch-release/verify-merch.php
```

The verifier creates two clearly identified demo verification orders, leaves them as evidence, tests retry idempotency, rejects the original Ecentric order, checks disabled/incorrect-host guards, and intercepts HTTP/mail to detect accidental provider or notification calls. Browser checks must also cover clothing variation, cart quantity, both checkout methods and mobile layout.

## Assets

The existing FORUS logo is reused from the developer portal's approved brand assets. Product concept photos were generated with the built-in image generation tool, then converted to JPEG without composition changes for web delivery. The six final images are in `images/`; their prompts are recorded in `IMAGE-PROMPTS.txt`. These are concept goods with sample prices, not representations of real available stock.
