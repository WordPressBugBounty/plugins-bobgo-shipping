=== Bob Go Smart Shipping for WooCommerce ===

Contributors: bobgroup
Tags: shipping, woocommerce, courier, delivery, tracking
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 4.0.58
WC requires at least: 9.0
WC tested up to: 11.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart shipping and order management solution in South Africa

== Description ==

#__Smart shipping solution__
Orders are automatically synchronised from your WooCommerce store, making it easy to generate electronic shipping labels with one click. Receive quotes and ship with multiple delivery partners, whilst managing everything - including billing - within a single interface.

Once products are shipped, customers will automatically be kept up to date with Bob Go customer tracking notification emails.

Delivery partners include The Courier Guy, Internet Express, SkyNet, RAM, Fastway, Pargo and Bob Box, Bob Group's very own locker and counter pickup solution.

#__Streamline your order processes__
Easily integrate Bob Go with your ecommerce stores, sync with your sales channels and automatically pull orders from your store. Use features like the address book and rule engine to customise your shipping processes to best suit your requirements. Bulk fulfillments, manifests and downloadable packing slips are also available for your convenience.

#__Other features include:__
- Multiple user roles, including a shipping agent user role linked to shipping classes or vendors (Dokan plugin)
- Creating manual orders and/or shipments
- Rates at checkout
- Rule engine automations
- VAT-inclusive shipping rates
- Custom-branded tracking notification emails (from Starter plan)
- Promotions in tracking notifications  (Advanced and Premium plans only)
- Zebra sticker waybill integrations (Advanced and Premium plans only)
- Open API

#__Manage the shipping rates you charge your customers at checkout__
__Rates at checkout__ is a Bob Go service that allows accounts to set up shipping rates that will be charged to their buyers at checkout. These rates are configured based on certain criteria and can be set up for different shipping methods with other specific requirements. As an additional option, you can allow customers to select a locker or counter which will be presented when a pickup location is available within their address radius.

== Screenshots ==

1. Bob Go workspace dashboard
2. Bob Go order management
3. Bob Go shipment details
4. Bob Go address book
5. Bob Go sales channel setup
6. Bob Go shipping settings

== Installation ==

You need an active Bob Go account before installing the plugin. Click [here](https://www.bobgo.co.za) to sign up.

1. Install the plugin from the WordPress.org plugin directory, or upload the `bobgo-shipping` folder to `/wp-content/plugins/` and activate it.
2. In your WordPress admin, navigate to __WooCommerce → Bob Go__.
3. Open the __Connection__ tab.
4. In a separate browser tab, log into your Bob Go account and create (or open) a __WooCommerce sales channel__ at __Settings → Sales Channels__. Bob Go will display an __API key__ and a __webhook secret__ for that channel.
5. Copy the API key into the __API Key__ field on the plugin's Connection tab.
6. Copy the webhook secret into the __Webhook Secret__ field. This secret is used to authenticate inbound webhooks from Bob Go; without it, all webhook deliveries are rejected.
7. Click __Save Connection__. The plugin verifies the credentials, registers the webhook subscriptions, and schedules a reconciliation job.
8. (Optional) Use the __Test Connection__ button to confirm your store can reach the Bob Go rates API.
9. (Optional) Under the __Tracking__ tab, choose or create a page that will host the customer-facing tracking widget — either via the `[bobgo]` shortcode or the Bob Go Gutenberg block.

After saving the connection, new WooCommerce orders that match a syncable status (processing, on-hold, completed, shipped, partially-shipped) are sent to Bob Go automatically. Updates from Bob Go (fulfilments, tracking, status changes) flow back over webhooks.

##Support
[Book your seat at our next webinar](https://www.bobgo.co.za/webinar)
[Helpdesk](https://help.bobgo.co.za/portal/en/home)
[Contact us](https://www.bobgo.co.za/contact)

== External services ==

This plugin connects to external third-party services to provide shipping rate calculations and order tracking functionality.

= Bob Go Rates API =

* Service: [Bob Go](https://www.bobgo.co.za/) shipping rate calculation
* Endpoint: `api.bobgo.co.za/v2/rates-at-checkout`
* Data sent: Store identifier (domain), cart items (names, quantities, prices, weights, dimensions), the cart total after discounts, and origin/destination addresses
* When: Every time the checkout page calculates shipping rates (when Rates at Checkout is enabled)
* [Terms & Conditions](https://www.bobgo.co.za/terms-conditions)
* [Privacy Policy](https://www.bob.co.za/privacy-policy)

= Bob Go Tracking API =

* Service: [Bob Go](https://www.bobgo.co.za/) shipment tracking
* Endpoint: `api.bobgo.co.za/v2/tracking`
* Data sent: Store domain and order or tracking reference number
* When: When a customer searches for tracking information on the tracking page
* [Terms & Conditions](https://www.bobgo.co.za/terms-conditions)
* [Privacy Policy](https://www.bob.co.za/privacy-policy)

= Bob Go Order Sync API =

* Service: [Bob Go](https://www.bobgo.co.za/) order management
* Endpoints: `api.bobgo.co.za/v2/orders` (POST/PATCH/GET)
* Data sent: Store identifier, WooCommerce order ID and number, customer name, email, phone, billing and shipping addresses, line items (name, quantity, price, SKU, dimensions), totals, taxes, discounts, payment status, currency, and any merchant-configured custom order field mappings. When a tracking webhook arrives for an order without a stored Bob Go reference, the shipment tracking reference or order number is sent to look up the matching Bob Go order.
* When: When a WooCommerce order reaches a syncable status (processing, on-hold, completed, shipped, partially-shipped) — and again on subsequent edits, status changes, and the hourly reconciliation job. Authenticated with the merchant's Bob Go API key.
* [Terms & Conditions](https://www.bobgo.co.za/terms-conditions)
* [Privacy Policy](https://www.bob.co.za/privacy-policy)

= Bob Go Webhook Subscriptions API =

* Service: [Bob Go](https://www.bobgo.co.za/) webhook subscription management
* Endpoints: `api.bobgo.co.za/v2/webhooks` (GET/POST/DELETE)
* Data sent: Store identifier and the WordPress REST URL where Bob Go should deliver tracking, fulfilment, and order-update webhooks for this site.
* When: When the merchant saves the connection (registers subscriptions), disconnects (deregisters them), or the settings page refreshes the subscription status. Additionally, a daily health check (run from the hourly reconciliation job) verifies the subscriptions are still active and re-registers them if Bob Go disabled them.
* [Terms & Conditions](https://www.bobgo.co.za/terms-conditions)
* [Privacy Policy](https://www.bob.co.za/privacy-policy)

= Bob Go Accounts API =

* Service: [Bob Go](https://www.bobgo.co.za/) account verification
* Endpoint: `api.bobgo.co.za/v2/accounts` (GET)
* Data sent: Store identifier only. The response confirms the API key works; when the connection is saved, and on the daily check, it also records which Bob Go account the key belongs to.
* When: When the merchant saves or tests the connection, and once a day from the hourly reconciliation job.
* [Terms & Conditions](https://www.bobgo.co.za/terms-conditions)
* [Privacy Policy](https://www.bob.co.za/privacy-policy)

= Bob Go Fulfilments API =

* Service: [Bob Go](https://www.bobgo.co.za/) fulfilment data
* Endpoint: `api.bobgo.co.za/v2/order-fulfillments`
* Data sent: Store identifier and the Bob Go order ID being looked up.
* When: When the admin uses the per-order metabox "Re-sync" button, when reconciliation detects a stale shipment, or as part of the per-order admin view to display tracking and shipment state.
* [Terms & Conditions](https://www.bobgo.co.za/terms-conditions)
* [Privacy Policy](https://www.bob.co.za/privacy-policy)

= Google Maps =

* Service: [Google Maps](https://maps.google.com/) for pickup point directions
* Data sent: Latitude and longitude coordinates of the pickup point location
* When: When a customer clicks the "Get directions" link on a pickup point in the tracking results
* [Google Terms of Service](https://policies.google.com/terms)
* [Google Privacy Policy](https://policies.google.com/privacy)

== Development ==

The JavaScript and CSS assets shipped in `assets/build/` and `block/build/` are compiled from the human-readable source files included in every release of this plugin:

* `block/src/index.js` (and its imports) compiles to `block/build/index.js` — the Gutenberg tracking block.
* `assets/src/bobgo-shipping.js`, `assets/src/checkout-suburb.js`, and `assets/src/custom-shipping-description.js` compile to the matching files in `assets/build/`.
* `assets/src/bobgo-shipping.scss` compiles to `assets/build/bobgo-shipping.css`.

The compiled JavaScript is built with [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts) (webpack + Babel) and the CSS with [Dart Sass](https://www.npmjs.com/package/sass). The exact tool versions are pinned in the bundled `package.json` and `package-lock.json`.

To regenerate the compiled assets from source:

1. `npm install`
2. `npm run build`

Individual builds are available via `npm run block:build` (block), `npm run assets:build` (frontend JS), and `npm run build:css` (SCSS).

== Changelog ==

= 4.0.58 =
* Order updates no longer send the order's item list to Bob Go when nothing about the items has changed. Bob Go treats the list it receives as the complete set, so an item it holds that the update leaves out is deleted — and when that item has already been shipped, Bob Go refuses the whole update, taking the address or status change sent with it. One store spent a day with an order failing to update every hour for this reason. The list is still sent in full when an order is created, and whenever the items genuinely change.
* An item change made to an order that was linked to Bob Go before this update now reaches Bob Go the first time. Whether an update needs to carry the item list is decided against a record of the list Bob Go last accepted, and an order linked by an earlier version, or by anything other than the normal create path (an incoming update from Bob Go, the check that repairs a wrong link, or being adopted because Bob Go already held the order under the same number), had no such record. The list was left out and the items as they stood at that moment were recorded as though Bob Go had them, so a first item edit was lost and only a second would have arrived. Every order already linked to Bob Go is now given that record in the background shortly after the plugin updates, and an order linked by one of those other paths records its list at the point of linking.
* An update Bob Go has already refused is no longer sent again unchanged. Every edit and every hourly check from Bob Go queued the same rejected request afresh, which filled the sync log without ever succeeding. The order is still marked as failed and still shown in the failed-sync notice, and the moment anything about it changes the update is sent again immediately. A request that failed to reach Bob Go, or that Bob Go answered with a server error, is still retried as before.
* Completing an order that already has a shipment now reaches Bob Go. The status update carried the order's item list with it, so Bob Go refused the whole request once an item had shipped, and the status change was lost with it. Cancelling is sent the same way and is no longer held up for that reason — but Bob Go does not cancel an order whose items have already shipped, so such an order cancelled in WooCommerce stays open on Bob Go.
* An order taken back out of Cancelled in WooCommerce is no longer left cancelled on Bob Go. A merchant who cancelled an order, then received payment and set it back to Processing, saw the payment land on Bob Go while the order itself stayed cancelled there: the plugin sent the status only when an order was cancelled or completed, and Bob Go changes nothing it is not told about. Processing is now sent as well, so the order reopens. Because Bob Go keeps the status it is given, moving a completed order back to Processing in WooCommerce now reopens it on Bob Go too. Orders awaiting payment (Pending, On hold) and refunded orders are unchanged: those reach Bob Go as a payment status, which is how it records them.
* The suburb a buyer types at checkout now reaches Bob Go on the classic (shortcode) checkout. On a store set to "Default to customer shipping address" under WooCommerce → Settings → Shipping, a buyer who unticked "Ship to a different address" had their suburb dropped from the shipping quote: the plugin read the hidden shipping suburb field rather than the billing one they had actually filled in. Which address the buyer chooses now decides which field is used, instead of the store's default setting.
* Orders now reach Bob Go with a suburb even where WooCommerce did not copy it onto the shipping address. WooCommerce normally mirrors the billing suburb onto the shipping address when "Ship to a different address" is off, but custom checkout templates, checkout field editors and replacement checkout plugins can interrupt that, leaving an order with a complete shipping address and no suburb. Where the order ships to the billing address, the billing suburb is now used. An order genuinely going to a different address is left alone, so a suburb from another city is never attached to it.
* The Bob Go shipping line on an order now reads in plain words. The service code — an identifier such as "bobgo_286837_15_0", which tells Bob Go which service the buyer chose at checkout — is no longer shown on the order, and the rate detail below the shipping method is labelled "Description", "Delivery from" and "Delivery by" rather than by its raw field names. This changes the display only: the service code is still recorded against the order and still sent to Bob Go exactly as before.
* Tested against WordPress 7.1 and WooCommerce 11.1.

= 4.0.50 =
* The Overview tab now reports what the plugin has observed rather than what it has been configured with. "Connected" previously meant only that an API key was stored, and the "Last Sync" tile showed when the hourly background check last ran under a label that reads like order sync — so a store whose orders were never reaching Bob Go could show nothing but green ticks. The tab now shows whether Bob Go has ever confirmed the API key and which account it belongs to, when the last order was actually sent and which one it was, the exact error Bob Go returned if the most recent attempt failed, and whether updates from Bob Go are arriving.
* Added a Background Jobs card. Every order sent to Bob Go, every status update and the hourly reconciliation are queued through WooCommerce's Action Scheduler, so a queue that is not being processed stops orders reaching Bob Go while every setting on the page stays valid. The card shows how many orders are queued and waiting to be sent, whether WordPress is running its own scheduled events at all, whether the recurring check is scheduled, and when it last completed.
* When something is stopping orders from reaching Bob Go, the Overview now says so at the top of the page in one sentence, names the most likely cause, and lists the steps to clear it — including what to ask your host for, when the cause is a site or hosting setting rather than a Bob Go one. Each individual warning carries the same steps under a "How to fix this" link, so most of these situations can now be resolved without contacting support.
* The Overview no longer reports "none yet" for order activity it cannot actually see. Where the plugin's own sync log could not be created — some hosts do not allow it — the affected tiles now read "Unknown" and explain why, instead of looking like a store that has never sent an order.
* The channel identifier sent with every request is now shown on the Overview, next to a link to your Bob Go sales channels. When it does not match a sales channel — for example www against non-www after a domain change — Bob Go answers with a 404 that previously left the connection looking healthy.
* A store left on the Sandbox or Development environment is now flagged on the Overview, since orders sync to that account and production API keys will not work against it.
* Test Connection is now available on the Overview tab, and the Overview separates configuration from health so a settings choice no longer reads as a fault.
* Test Connection now records what it proved: a test that Bob Go answers definitively marks the API key verified (or rejected) on the Overview, instead of leaving it "Not verified" after a successful test. When Rates at Checkout is disabled or no API key is stored yet, the button now says so — and where to go next — rather than doing nothing.
* Fulfillment webhooks now count as updates from Bob Go on the Overview. They were logged under the same name as the order panel's own fulfillment fetch, so a store whose only inbound traffic was fulfillment events read "No webhook has reached this store" while webhooks were arriving and processing fine.
* An order held back because Bob Go could not confirm whether it already exists is now reported as held, rather than as a transport failure that "never got a response". A failed status update is described as a failed update, not as orders failing to arrive.
* Times on the Order Sync card are now shown as relative times ("2 hours ago") like the rest of the page, instead of raw UTC timestamps.
* Pressing Test Connection on the Overview now updates the status cards above it straight away. The test records what it proved, but the cards were rendered before it ran, so the page could report "Not verified" directly above a successful test result until it was reloaded.
* A rejected request is no longer reported as a bad API key. Bob Go answers with the same 401 when the channel identifier does not match a sales channel, and when rates at checkout is switched off for that channel — both on a perfectly valid key — so the Overview now names all the causes and puts the checks that cost nothing before generating a new key.
* Webhook registration failures are now explained in the plugin's own words. A rejected key produced "Failed to register webhooks: You're not logged in. Please log in and try again.", which sent merchants looking for a login screen; it now names the API key and channel identifier, which is what the plugin can actually tell you about.
* Test Connection results now link to the tab you need, rather than naming it and leaving you to find it.
* Tested against WooCommerce 11.

= 4.0.43 =
* The connection check now verifies your API key against your Bob Go account. The Advanced tab shows your Bob Go account code.
* Re-syncing an order now sends the order to Bob Go rather than only fetching its shipment details back. Orders edited after their first sync are brought up to date, orders whose stored Bob Go reference no longer exists are re-resolved instead of failing against it forever, and the per-order "Re-sync" button reports a failure instead of reporting success without having sent anything.
* Fixed the "orders failed to sync" warning on the orders page reporting failures that "Re-sync all failed orders" then could not find. Both now work from the same list, and orders in a status that can never sync are no longer counted.
* Disconnecting now also removes the link between your WooCommerce orders and their Bob Go orders, so reconnecting to a different Bob Go account no longer leaves orders pointing at the old one. The links are removed in the background, the orders are not re-sent, and nothing is deleted on Bob Go itself.
* Every request to Bob Go now identifies the plugin version it came from, so support can diagnose a store without having to ask which version is installed.

= 4.0.36 =
* Fixed rates at checkout ignoring shipping-class, SKU and vendor based rate rules. The product SKU, shipping class and (on multi-vendor stores) vendor are sent with each cart item again, so rules configured on those in your Bob Go account are applied to the rates your buyers see.
* Fixed the milestone icons on the tracking page rendering unstyled — the wrong size, colour or spacing — because the widget's HTML sanitiser discarded the styling Bob Go sends with each tracking step. Styling supplied by Bob Go is now applied as intended, while anything that would load an external resource is still removed.
* Fixed the tracking page showing nothing when a second order number was looked up without reloading the page. The same fault also left a second error message blank.
* Links out to the Bob Go workspace — from the Resources sidebar, the order panel and the rates at checkout connection notices — now follow the environment the plugin is connected to, instead of always opening the production workspace.
* Update the plugin directory listing

= 4.0.31 =
* Removed bundled translation files from the plugin package. Translations are delivered automatically through translate.wordpress.org.
* The legacy [uafrica] shortcode and uafrica/shipping block from the pre-rebrand plugin are no longer registered. Existing pages that still use them are rewritten automatically to the [bobgo] shortcode and bobgo/shipping block on upgrade, so tracking pages keep working unchanged.

= 4.0.22 =
* Fixed a critical order-matching bug on stores using WooCommerce's classic (legacy) order storage: Bob Go notifications for orders with no WooCommerce counterpart — such as CSV imports or orders created manually on Bob Go — could attach themselves to an unrelated recent shop order. An affected order displayed a stranger's tracking information, was treated as already synced (so it was never sent to Bob Go), could be marked completed by the stranger's delivery, and could have its delivery address overwritten. Every lookup now either matches the exact order or matches nothing.
* Added a one-time, automatic link audit that verifies every stored Bob Go order link against the Bob Go API and silently repairs existing damage: wrongly-linked orders have the foreign link and tracking data removed, active orders are re-sent to Bob Go, and orders whose true Bob Go counterpart is found are relinked without creating duplicates. Completed orders are only unlinked and flagged internally, since merchants may have already shipped them manually.
* An order's stored Bob Go link can no longer be overwritten by a notification claiming a different Bob Go order.
* Notifications whose order reference matches no shop order are now acknowledged instead of rejected, so stores receiving many of them (for example during a CSV import) can no longer have their Bob Go notifications disabled by the failed-delivery window.
* Fixed hourly reconciliation on stores using classic order storage: the batch could fill with orders that were never sent to Bob Go, starving the linked orders it was meant to refresh.

= 4.0.20 =
* The plugin now checks once a day that its Bob Go webhook subscriptions are still active, and re-registers them automatically if they were disabled. Bob Go disables a subscription after a sustained period of failed deliveries — for example when a store was unreachable for a few days — and only notifies the merchant by email, after which tracking updates would silently stop arriving. With this check, webhooks recover on their own within an hour of the store coming back online. Stores that were deliberately disconnected are never re-registered, and each automatic recovery is recorded in the sync log.

= 4.0.19 =
* Bob Go notifications for shipments that have no WooCommerce counterpart (for example manual shipments created directly on Bob Go) are now acknowledged and ignored instead of rejected as errors. Notifications are delivered account-wide, so stores that also ship outside their WooCommerce channel produced a constant stream of rejections — and a long enough stretch where those were the only deliveries could get the store's Bob Go notifications disabled entirely, silently stopping all tracking updates. Ignoring these events keeps the notification channel healthy.

= 4.0.17 =
* Tracking updates now also reach orders whose Bob Go notifications carry no order reference. Tracking webhooks from Bob Go don't include the Bob Go order ID, so orders that this plugin didn't originally send (for example historical orders from the previous integration, most of which are already fulfilled and only ever emit tracking updates) could still not link themselves. The plugin now resolves the link with a single Bob Go order lookup — by the shipment's tracking reference, falling back to the order number — stores it, and proceeds as normal. Ambiguous or mismatched lookups are refused and logged rather than guessed.

= 4.0.15 =
* Orders that exist on Bob Go but were not sent by this plugin (for example historical orders ingested by the previous integration) now link themselves automatically: shipment and tracking webhooks from Bob Go backfill the stored Bob Go order ID from the notification itself, so tracking information starts landing on those orders without any manual migration.

= 4.0.14 =
* Subscription renewal orders now sync to Bob Go. Previously, orders created by WooCommerce Subscriptions renewals were silently skipped, so recurring orders never appeared on Bob Go for merchants using the plugin as their only sales channel. Renewals now sync as soon as payment moves them into a syncable status. Bob Go sync metadata is also excluded when Subscriptions copies data onto a new renewal order, so a renewal can never be mistaken for an already-synced order.

= 4.0.13 =
* Fixed an order-lookup mix-up in the fulfilment refresh: when an order had no stored Bob Go order ID yet, the plugin queried the Bob Go fulfilments API with the WooCommerce order reference instead. This caused "order does not exist" errors and, in rare cases, could have matched a different order on the Bob Go account. The refresh now only runs once the order is properly linked; reconciliation picks up anything missed.

= 4.0.12 =
* The "Shipped" / "Partially shipped" customer notification email is now opt-in and off by default. Enable it under WooCommerce → Bob Go → Notifications; only then does it register with WooCommerce and appear under WooCommerce → Settings → Emails. Existing stores are unaffected until they opt in.
* Product options sent with order items are now additionally sanitised against invalid text encoding, so malformed option data from third-party plugins can never disrupt the order sync request.

= 4.0.10 =
* Hardened protection against order-update sync loops. WooCommerce webhooks belonging to the previous plugin generation (Bob Go v3 / uAfrica) are now blocked from delivering at all — even if a legacy sales channel re-creates them — and are removed on an ongoing basis: at activation, at deactivation, and during the hourly reconciliation sweep, instead of only once at install. In addition, inbound delivery-address updates that show loop corruption (the same address segment repeated over and over, or oversized fields) are rejected and logged instead of being written to the order, so a runaway update cycle can no longer mangle customer addresses.

= 4.0.9 =
* New customer notification email for the "Shipped" and "Partially shipped" order statuses. When those statuses are enabled, WooCommerce sends the customer an email — with the courier, tracking link and the items that shipped — as each fulfilment is created. It appears under WooCommerce → Settings → Emails, where you can customise the subject and heading or switch it off. Only forward moves notify, so a shipment cancelled on Bob Go never emails the customer that their order un-shipped.
* Order items now sync to Bob Go with the customer's selected options — product add-on choices (flavours, gift messages, personalisation), variation attributes and similar fields captured at checkout — so they are visible on the Bob Go order for fulfilment. Only customer-facing options are sent; internal plugin data is excluded. A new Product Options section on the Advanced settings tab lets you switch this off entirely or untick individual option keys (for example a terms-and-conditions acceptance) so they are never sent.

= 4.0.8 =
* Maintenance release for the WordPress.org plugin directory. The admin order-status badge styling now loads through the standard WordPress style queue, and the plugin's asset build source and build steps are bundled and documented. No changes to store-facing behaviour.

= 4.0.7 =
* Free shipping coupons are now honoured at checkout. When a customer applies a coupon that grants free shipping, checkout shows a single free Bob Go shipping option instead of the usual rates. No courier is pre-selected, so you choose the service to ship with once the order reaches Bob Go.

= 4.0.6 =
* Shipping rates at checkout now account for applied coupons. The order total sent to Bob Go reflects any discount (including tax), so value-based rate rules — such as free shipping over a set amount — are calculated against what the customer actually pays.

= 4.0.3 =
* Cart page shipping rates are now cached for up to 2 hours, which greatly reduces repeated rate requests to Bob Go while customers browse the cart. Checkout is unaffected — rates there are always recalculated against the customer's full delivery address, so the price charged is never stale.

= 4.0.2 =
* Fixed orders staying on "Shipped" after their shipment is cancelled on Bob Go. The order now mirrors Bob Go by moving back to "Processing" (or "Partially shipped" when other shipments remain), and the Bob Go order panel lists the affected items as unfulfilled again.

= 4.0.0 =
A complete rewrite of the plugin, rebuilt from the ground up for Bob Go.

* Two-way order sync — orders flow from WooCommerce to Bob Go automatically, and fulfilments, tracking, status changes and cancellations flow back over secure webhooks.
* Rates at Checkout — show the shipping rates you have configured in Bob Go directly at your customers' checkout, including locker and counter pickup options.
* Customer order tracking — a branded tracking page via the `[bobgo]` shortcode, the Bob Go Gutenberg block, or the Elementor widget.
* Hourly reconciliation safety net so missed webhooks self-heal, with an honest connection status on the settings page.
* Dedicated "Shipped" and "Partially shipped" order statuses, plus a Bob Go tracking column on the Orders list.
* Consolidated settings under WooCommerce → Bob Go, with tabs for Connection, Rates, Tracking, Checkout Fields and more.
* Full support for High-Performance Order Storage (HPOS) and for both classic and block-based checkout.
* Multi-vendor support, including Dokan sub-order syncing.
* Hardened security — encrypted API credentials, verified webhook signatures, and input validation and output escaping throughout.
* Existing settings are migrated automatically when upgrading from the previous uAfrica Shipping plugin.

== Upgrade Notice ==

= 4.0.0 =
A complete rewrite of the plugin for Bob Go, adding two-way order sync, webhook-based tracking and fulfilment updates, and a consolidated settings page. Existing uAfrica Shipping settings are migrated automatically. After upgrading, open WooCommerce → Bob Go and save your connection.
