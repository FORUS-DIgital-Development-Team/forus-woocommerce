# FORUS merchandise demo — 18 November 2026

Deployed at https://shop.forus.digital/ on the existing `forus-merchant-demo` VM in `forus-prod-503017`, `africa-south1-a`. No DNS, identity, merchant portal or backend service was replaced. The user explicitly selected simulated eZAR and WOW checkout with no money movement.

## Storefront and catalogue

The `forus-store` 1.0.0 theme adds a FORUS-branded home, collection navigation, responsive product grids, cart and checkout. Six concept products are published: Everyday Tee (R249), Together Hoodie (R649), Daily Ritual Mug (R149), Community Cap (R199), Carry It Together Tote (R179), and On The Go Bottle (R299). The two clothing products each have S, M, L, XL and 2XL variations. These are sample prices and generated concept images, with no real merchandise sales or fulfilment.

Managed product IDs: 20, 27, 34, 36, 38, 40. Image IDs: 21, 28, 35, 37, 39, 41. The existing R1 test product remains intact and is hidden from the catalogue. Original order 16 remains cancelled in WooCommerce and unpaid; its unresolved Ecentric/backend evidence is retained.

## Demo checkout isolation

The separate `forus-demo-wallets` 1.0.0 plugin exposes `forus_demo_ezar` and `forus_demo_wow` only on the explicitly enabled dedicated staging site. It uses no provider credentials or endpoints. Orders receive custom status `forus-demo`, display “Demo complete (unpaid)”, and have no transaction ID or date paid. The theme, descriptions, checkout and confirmation disclose the simulation. No personal input is requested; a fictional shopper is used and transactional demo emails are suppressed.

The original gateway setting remains `enabled=no`. Cloud Run remains revision `forus-merchant-sandbox-00005-qgb` with environment `test`, provider API `false`, refunds `false`, and no client certificate/key bindings. The replacement Ecentric key prepared earlier remains staged separately, not activated. The provider email remains unsent.

## Verification

- All nine PHP files passed syntax validation in the actual WordPress CLI image before installation.
- Eighteen deployed checks passed: catalogue and variants, HPOS, available methods, both unpaid completion paths, retry reuse, no refunds/second payment, email suppression, original-order rejection, disabled-option and wrong-host guards, and zero provider HTTP or email attempts. Verification orders 45 and 46 were retained as demo evidence.
- Desktop browser: size M T-shirt added, quantity changed from one to two, total updated to R498, and eZAR simulation completed as order 47.
- Mobile browser at 390 × 844: mug product and WOW checkout completed as order 48 for R149. Confirmation states no money or tokens moved and no fulfilment. The viewport override was reset afterward.
- Mobile home, product and catalogue inspected; all six images loaded and page width equalled viewport width (390px), with no horizontal overflow. Desktop home, product, cart and confirmation were inspected.
- Home, shop, three category pages and demo FAQ returned HTTP 200 with the demo notice.

Release directory: `/opt/forus-merchant/releases/merch-20261118-v1/merch-demo`. The root-only pre-install backup is `backups/20261118T122631Z`. The installer records the prior theme/options/page content for targeted recovery and does not delete orders. See the adjacent README for disable and recovery instructions. Neither the simulation nor these tests establish provider payment/refund acceptance.
