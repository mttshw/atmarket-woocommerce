=== AT Protocol for WooCommerce ===
Contributors: atmarket
Tags: woocommerce, atproto, bluesky, directory, catalogue
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your products to your own AT Protocol account, so open directories like Atmarket can list them. Buyers still check out in your shop.

== Description ==

The AT Protocol is the open network behind Bluesky. This plugin publishes your shop's catalogue to your own account on it, as standard `shop.lgtm.commerce` records. Any app or directory that reads the network can then list your products and link back to your shop. No marketplace, no fees, and nothing to buy through anyone else.

* **Your shop stays in charge.** Products are copied out, never edited elsewhere. Change a price here and the record updates within a minute.
* **Only what customers can already see.** Published products only: name, description, images, brand, tags, category, price and whether each option is in stock. Drafts, hidden and password-protected products, stock counts, costs and customer data never leave your shop. Unpublish or delete a product and its record is removed.
* **Variable products** publish one record per variation, with their options (size, colour…) and prices.
* **Verified shop.** One click makes your shop's own domain your account's handle (for example @yourshop.com). Directories treat that as proof the shop is yours.
* **Light on your site.** Syncing runs in the background through WooCommerce's Action Scheduler. Only records that changed are sent, and each image is uploaded once.

You need an AT Protocol account for the shop. A free Bluesky account works.

== Installation ==

1. Install and activate the plugin.
2. Go to WooCommerce → Settings → AT Protocol.
3. Enter your shop's account handle and press Connect. You'll sign in on your account's own server and come back here.
4. Tick "Publish products" and save. The first sync runs within a minute.

Your site address must use https. Pretty permalinks (any setting other than "Plain") are needed for sign-in.

== Frequently Asked Questions ==

= Where do my products show up? =

In any directory or app that reads `shop.lgtm.commerce` records, such as Atmarket (atmarket.co). The plugin doesn't favour any of them.

= Does anyone else take payments for my products? =

No. Directories link to your product pages; buyers check out here as usual.

= What happens if I disconnect? =

Syncing stops. Records already published stay on your account until you delete them there.

= Can I keep some products out? =

Yes: hide them from the catalogue, or choose categories to leave out in the settings.

== Changelog ==

= 0.1.0 =
* First release.
