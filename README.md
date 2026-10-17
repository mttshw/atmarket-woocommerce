# AT Protocol for WooCommerce

A WordPress plugin that publishes a WooCommerce catalogue to the shop's own AT Protocol account as `shop.lgtm.commerce.*` records, so directories such as [Atmarket](https://atmarket.co) can list them. The user-facing description is in [readme.txt](readme.txt).

PHP 8.1+, no runtime dependencies: atproto OAuth (PAR, PKCE, DPoP, `private_key_jwt`) is implemented on `ext-openssl`, HTTP goes through the WordPress HTTP API, and scheduling uses WooCommerce's Action Scheduler.

## Layout

| | |
| --- | --- |
| `src/Crypto`, `src/OAuth` | P-256 keys, ES256 JWTs, DPoP, the OAuth client |
| `src/Repo` | XRPC calls to the account's PDS (`XrpcRepo`), and an in-memory `PreviewRepo` |
| `src/Records.php` | Pure mapping from catalogue data to records |
| `src/Sync.php` | Diff-and-reconcile: write only what changed, in atomic batches |
| `src/Wp` | WordPress glue: catalogue reader, storage, routes, settings tab, scheduler, WP-CLI |

## Development

```sh
composer install
vendor/bin/phpunit                  # unit tests, no WordPress needed
```

A local shop for manual testing (PHP 8.3 via Homebrew, WordPress on SQLite) lives in `dev/` (git-ignored):

```sh
bin/setup-dev                       # WordPress + WooCommerce on SQLite in dev/, admin/admin
(cd dev/wordpress && /opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:8090)
bin/wp atproto preview              # the records a sync would publish, as JSON
bin/wp atproto sync --full          # sync now (needs a connected account)
bin/wp atproto status
```

On `localhost` the plugin is an atproto loopback client (short sessions). On an https site it is a confidential client that serves `/atproto/client-metadata.json` and `/atproto/jwks.json`.

## Licence

GPL-2.0-or-later.
