# Mirage – Conditional Payments for WooCommerce

Forces Authorize.Net when the cart contains any product in the `merch` category.

## Auto-updates
Mirage auto-updates from GitHub Releases. Run `./release.sh patch` to ship a new version.
The updater downloads assets named `mirage-vX.Y.Z.zip`.

## Configure
- Target categories: edit `MIRAGE_CAT_SLUGS` in `mirage.php`.
- Allowed gateways: edit `MIRAGE_AUTHNET_IDS`.

## Releasing
1. Commit your changes.
2. `./release.sh patch` (or `minor`, `major`)
3. WordPress will offer the update on sites running Mirage.
