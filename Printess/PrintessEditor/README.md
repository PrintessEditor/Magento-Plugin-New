# Printess_PrintessEditor — Magento 2 Module

Magento 2 integration for the [Printess](https://printess.com) web-to-print personalisation editor.

---

## Features

### Core Printess Integration
- Embeds the Printess Panel UI or Slim UI editor on product pages
- Supports configurable product variant sync (attribute → Printess form field)
- Supports custom option sync (dropdown/radio → Printess form field)
- Per-page pricing support
- Theme, print settings, and merge template configuration per product
- Add to basket flow with save token and thumbnail passed to cart

### Customer Login Gate
- "Personalise" button on product page checks login state via Magento's customer-data JS sections
- If not logged in, triggers the Magento authentication popup modal
- After login, automatically resumes the editor session

### My Projects (Customer Account Area)
- Saved projects are accessible at `/printess/project/index` within the customer account
- Listed under "My Projects" in the account navigation
- Projects display: name, product name, last edited date, expiry date
- Inline rename (click to reveal input, save or cancel)
- Delete with confirmation dialog
- Continue Editing reopens the Printess editor with the saved design
- Save & Quit saves and redirects back to My Designs; Save-only stays in the editor
- Magento's 'Reorder' link now keeps the Printess design (save token and thumbnail) of each item. If a design has expired at Printess, the item is not added and the customer is asked to use 'Edit & Reorder' instead. Whether a design is still available is checked with Printess's save-token info call (`/shop/template/info`); save tokens cannot be kept forever, but their lifetime depends on your Printess account settings.

### Email Notifications
- **Order Reminder**: cron job emails customers who have saved a design but not ordered
- **Removal Reminder**: cron job emails customers warning that their project is about to be deleted
- Email templates configurable in Admin → Stores → Configuration → Printess Designer → Projects
- Cron schedule and retention days configurable per store

### Project Lifecycle
- Projects have an expiry date based on a configurable retention period
- Expired projects are flagged in the UI
- Cron job cleans up expired projects automatically
- Ordered projects are preserved and exempt from expiry deletion

### Production
- Every Printess item in an order is queued for production after checkout and sent to Printess by cron (every 5 minutes); checkout is never slowed down
- The ordered quantity is sent to Printess as the number of copies
- All production settings are read per store view, so each store can use its own Printess account, print settings and dropshipping setup
- Optional **Hold Until Paid**: items wait until they are invoiced
- Optional **Approve Before Production**: items wait until an admin approves them on the order page
- Results arrive either by **callback** (Printess calls the shop as soon as something is done) or by **polling** (cron asks Printess). Callbacks are the default and use the store's own URL; see [Production status updates](#production-status-updates-callbacks-or-polling)
- Optional **dropshipping**: Printess renders the item and hands it to a dropshipper who produces and ships it, with tracking synced back into Magento shipments; see [Dropshipping](#dropshipping)
- Dropship items of an order are approved, sent and re-sent together as one dropship order; a failed dropship order can be sent again under the order number with a suffix (`-R1`, `-R2` …) or reproduced as a free copy of the order

---

## Installation

This module is installed as a standalone module in `app/code/Printess/PrintessEditor`.

```bash
php bin/magento module:enable Printess_PrintessEditor
php bin/magento setup:upgrade
php bin/magento cache:flush
```

Magento cron must be running: production, dropship status updates and the project reminders are all driven by cron.

### Upgrading from a version without dropshipping

```bash
php bin/magento setup:upgrade
php bin/magento setup:di:compile      # production mode only
php bin/magento cache:flush
```

`setup:upgrade` adds the new columns to `printess_pdf_jobs`, the two dropship product attributes and the five Printess order statuses. Nothing else has to be migrated.

What changes for existing shops:

- **Jobs are now queued on `sales_model_service_quote_submit_success`** instead of `sales_order_place_after`. The old event fires before the order is saved, so older versions could store jobs with order id `0` and order item id `0`; those jobs can never be matched to an order. The new event fires after the order is saved, for storefront and admin orders. Old rows with order id `0` are not repaired automatically: produce those items manually and delete the rows if you have any.
- **Settings are read per store view.** Previously cron always used the default store's values, whatever store the order came from. If you have more than one store view, check that the service token, print settings and production settings are set at the right scope (Default Config, Website or Store View) before upgrading.
- **Copies are sent.** An order line with quantity 3 now produces 3 copies (previously 1).
- **Per-product print settings are used in production.** `printess_print_settings` on the product now wins over the store setting, as it already did in the editor.
- Without any new configuration, production behaves as before: no dropshipping, no payment hold, no approval, polling every 5 minutes.

### Upgrading to the release with dropship groups and order copies

```bash
php bin/magento setup:upgrade
php bin/magento setup:di:compile                  # production mode only
php bin/magento setup:static-content:deploy       # production mode only (admin JS and templates changed)
php bin/magento cache:flush
```

`setup:upgrade` adds new columns to `printess_pdf_jobs` (send count, dropship order number, tracking list, callback type), to `sales_order` (links between an order and its copies) and to `quote`, and changes the default of the `printess_dropship_mode` attribute. The five Printess order statuses are unchanged. No data has to be migrated.

What changes for existing shops:

- **Dropshipping is configured per product only.** The store-level "Dropshipping" and "Dropship Product" settings are gone. Choose the dropshipper and product on each product (or on the configurable parent). The upgrade deletes the old store values and clears "Use store setting" from products, so those products show "No dropshipping" until you set them.
- **Dropship items are always sent as one group** per order, with a fresh address each time. See [How orders flow](#how-orders-flow).
- **Shipping updates arrive per item** (callback type 3 at `printess/dropship/callback?order=…`) when callbacks are used. **Jobs sent before the upgrade keep using the old callback** (`?n=<nonce>`) until they are finished or sent again; nothing has to be done for them.
- **"Restart production" replaces "Retry" for dropship items**, and "Approve" for dropship items now opens a confirmation page. Print items keep their retry options.
- **New setting "Reproducing Dropship Orders"**, default "Send again with a suffix", i.e. no copy is ever created unless you switch it to "Ask each time".

### Upgrading to the release with callbacks by default

No database changes; run `setup:di:compile` (production mode only) and `cache:flush`.

- **Callbacks are now on by default using the shop's own URL; previously they were only used when a Callback URL was set.** Shops that can't be reached from the internet fall back to polling automatically, or set **Production status updates** to **Polling**.
- The former "Production Callback URL" field is now **Callback URL (override, for debugging)**. A value you entered before is kept and still used; on a live shop you can clear it, since the store's own URL is used anyway.
- Orders already in production keep the mode they started with. See [Production status updates](#production-status-updates-callbacks-or-polling).

---

## Configuration

**Admin → Stores → Configuration → Printess → Printess Editor**

| Section | Setting | Description |
|---|---|---|
| API & Token | Shop Token | Your Printess shop token |
| Editor | Default Theme | Editor UI theme name |
| Editor | Editor Version | Which build of the Printess editor to load (e.g. "nightly" for the current staging build). The "/v/" prefix is added automatically if omitted. Blank = current release version. Store-level. |
| Editor | Print Settings | Default print settings name |
| API Settings | Service Token | Server-side token for production and dropshipping. Must be owner-level for dropshipping. |
| API Settings | API URL | Printess API address. Leave blank (`https://api.printess.com`) unless Printess support says otherwise. |
| Production | Print Settings, Hold Until Paid, Approve Before Production, Production Status Updates, Callback URL (override, for debugging), Send Shipment Email, Reproducing Dropship Orders | See [Dropshipping → Store settings](#store-settings) |
| Projects | Order Reminder Template | Email template for order reminder |
| Projects | Removal Reminder Template | Email template for removal warning |
| Projects | Retention Days | Days before a project expires |
| Projects | Cron Schedule | Cron expression for reminder/cleanup jobs |

---

## Product Attributes

| Attribute | Description |
|---|---|
| `printess_template` | Printess template name (required to enable editor) |
| `printess_slim_ui` | Enable Slim UI instead of Panel UI |
| `printess_slim_ui_full_editor` | Adds a "Switch to Full Editor" button on Slim UI products, letting the customer continue in the fullscreen Panel editor. No effect unless Slim UI is enabled. Default off. |
| `printess_product_btn_label` | Custom label for the Personalise button |
| `printess_theme` | Per-product theme override |
| `printess_print_settings` | Per-product print settings override |
| `printess_merge_template` | Merge template name |
| `printess_magic_photobook_theme` | Magic photobook theme |
| `printess_page_pricing` | JSON array for per-page pricing rules |
| `printess_dropship_mode` | Dropshipping mode for this product; default empty ("No dropshipping"). See [Dropshipping → Product attributes](#product-attributes) |
| `printess_dropship_product_definition` | Dropship product definition (dropshipper and product) used when the mode is "Use a dropship product definition" |

---

## Dropshipping

### What it is

With dropshipping, you do not print or ship Printess items yourself:

1. Printess renders the customer's design.
2. Printess sends the print file and the shipping address to a **dropshipper** (a print partner connected to your Printess account, e.g. via HP Siteflow, email, FTP or Dropbox).
3. The **dropshipper** produces the item and ships it to your customer.
4. The dropshipper reports shipment and tracking back to Printess, and Printess passes them on to Magento.

**Printess never ships anything.** Production, packaging, shipping, and whether several items of one order arrive in one parcel are the dropshipper's responsibility. Magento only needs to know which *product definition* to use; there is no partner-specific code in the module.

Items without dropshipping keep working as before: Printess renders a print PDF, and you produce and ship the item.

### Prerequisites

Set these up before switching dropshipping on:

| What | Where |
|---|---|
| **Owner-level service token.** Saving the shipping address (`/dropshipData/save`) only works with the token of the account owner. A token of a regular team member fails. | Printess account → API-Token; enter it in Magento under API Settings → Service Token |
| **Dropshippers and product definitions.** Each product definition ties a physical product to a dropshipper. Magento loads the list from your account; it does not create them. | Printess account |
| **Shipping method mapping.** The module sends Magento's shipping method code as `shippingCode` (e.g. `flatrate_flatrate`, `tablerate_bestway`) and the shipping description shown in the order (e.g. "Flat Rate - Fixed") as `shippingTitle`. Map each Magento code to the dropshipper's shipping method in the Printess portal. `shippingTitle` is only a fallback. | Printess account portal |
| **Template-based dropshipping (optional).** If you use "From template" mode, the Printess template itself must carry the dropship configuration. | Printess template settings |
| **Running Magento cron.** | Server |

The dropship product lists in the admin are loaded from Printess and cached for about 10 minutes. If a new product definition does not show up, wait or flush the cache.

### Store settings

**Admin → Stores → Configuration → Printess → Printess Editor → Production**

All fields can be set per Default Config, Website and Store View. The order's store view decides which values are used.

| Field | Default | What it does |
|---|---|---|
| Print Settings | — | Named Printess print settings used for production. A product's own print settings take precedence. |
| Hold Until Paid | No | Items wait (job status `awaiting_payment`) until the order item is fully invoiced. Applies to all Printess items, dropship or not. Dropship items of one order are released together, only when all of them are invoiced. |
| Approve Before Production | No | Items wait (`awaiting_approval`) until an admin clicks **Approve** on the order's Printess Production tab. Nothing is produced or shipped before that. Combined with Hold Until Paid, payment comes first, then approval. |
| Production Status Updates | Callbacks (recommended) | **Callbacks**: Printess calls this store's own secure base URL when rendering finishes and when the dropshipper ships. If that URL is not a public https domain (e.g. a local or `.test` shop), the shop falls back to polling automatically. **Polling (check every few minutes)**: cron asks Printess instead; no callbacks at all, even if a Callback URL is set. See [Production status updates](#production-status-updates-callbacks-or-polling). |
| Callback URL (override, for debugging) | empty | Only shown with Callbacks. Leave empty on live shops — Printess then calls this store's own URL. Set it only when Printess can't reach that URL, e.g. a local shop behind an HTTP tunnel (`https://abc.ngrok-free.app/`). Must be https with a public domain name. |
| Send Shipment Email | Yes | Send Magento's standard shipment email with tracking when a dropshipper reports an item as shipped. When an item that was sent again ships, the new tracking is added to its shipment and the customer gets a shipment update email. |
| Reproducing Dropship Orders | Send again with a suffix | What **Restart production** does for dropship items. **Send again under the order number with a suffix** (`-R1`, `-R2` …) or **Ask each time**: the restart page then also offers to duplicate the order and dropship the copy, for dropshippers that refuse any order number they have seen before. See [Sending dropship items again](#sending-dropship-items-again). |

### Product attributes

Whether and how an item is dropshipped is set **per product only** — there is no store-level default. Two attributes in the **Printess** group of the product form, both store-view scoped:

| Attribute | Options |
|---|---|
| **Dropshipping** (`printess_dropship_mode`) | **No dropshipping** (default, empty value: not set; the item is printed, a configurable child inherits from its parent), **Never dropship (overrides the parent product)** (explicit opt-out), **Use a dropship product definition**, **Use the dropshipping set up on the Printess template** |
| **Dropship Product Definition** (`printess_dropship_product_definition`) | Dropshipper and product from your Printess account, grouped by dropshipper ("Dropshipper › Definition"). Only enabled with "Use a dropship product definition". Save the service token first, otherwise the list is empty. |

**Precedence.** For each order item the first product level with a setting (anything but the empty "No dropshipping") decides:

1. the simple child product (for configurable products, i.e. the chosen variant),
2. the parent product.

If no level has a setting, the item is **not** dropshipped. A child without a setting follows its parent; "Never dropship" on a child stops a dropshipping parent.

So you can dropship one variant of a configurable product with one dropshipper, another variant with a different one, and print a third.

**What is sent to Printess:**

| Resolved mode | `productDefinitionId` |
|---|---|
| No dropshipping | (no dropship call; normal production) |
| Use a dropship product definition | the selected definition id |
| Use a dropship product definition, but none selected | treated as no dropshipping |
| Use the dropshipping set up on the Printess template | `0` (Printess takes the definition from the template) |

The decision is made once, when the order is placed, and stored with the job. Later product changes do not affect orders already placed.

### How orders flow

All dropship items of an order form **one dropship order** (a Magento order has one shipping address). They are always approved, sent and re-sent together.

1. **Order placed.** One production job per Printess item is queued.
2. **Waiting (optional).** Hold Until Paid and/or Approve Before Production keep the items waiting.
3. **Submit (cron, every 5 minutes, or right away after Approve).** All dropship items of the order are checked first (design, address, product definition). If one of them is not valid, **nothing is sent** and the whole group is marked failed. Otherwise the shipping address (billing address if there is none) is saved at Printess, fresh for every send, and every item is sent to `/dropship/produce` with copies = ordered quantity, the shipping code and title, and the order number. The customer's order comment, if any, goes to the dropshipper as a dispatch note.
4. **Rendering.** Printess renders the items and passes them to the dropshipper as one order.
5. **Production and shipping.** The dropshipper produces and ships. With [callbacks](#production-status-updates-callbacks-or-polling) (the default), Printess reports every shipment right away; with polling, the dropship status sync cron checks every 30 minutes.
6. **Shipped.** Magento creates a real shipment with a tracking entry (carrier "Custom", title = tracking type or dropshipper, number = tracking id) for the items reported. Items can ship in several parcels: each report creates its own shipment, and the order shows **Printess: Partially shipped** until all dropship items have shipped. Once everything is invoiced and shipped, Magento sets the order to **Complete** on its own.

**Order number at the dropshipper.** The dropshipper receives the Magento order number, e.g. `000000123`. When the items are sent again, a suffix is added: `000000123-R1`, then `-R2` and so on, because many dropshippers refuse an order number they have already received. The Printess Production tab shows the number and how often the items were sent.

#### Order statuses

The module adds five order statuses. They are assigned to the Magento states shown, never as the default, and can be renamed under **Stores → Settings → Order Status**. The module only changes the order *status*, never the order *state*: invoicing, shipping and completing work exactly as in standard Magento.

| Status (code) | Label | States | Set when |
|---|---|---|---|
| `printess_rendering` | Printess: Rendering | new, processing | At least one dropship item has been submitted and is being rendered |
| `printess_dropship_submitted` | Printess: Sent to dropshipper | new, processing | All unshipped dropship items are rendered and handed to the dropshipper |
| `printess_dropship_in_production` | Printess: In production | new, processing | The dropshipper reports the items as received or print-ready (partner state 10 or 11) |
| `printess_dropship_partially_shipped` | Printess: Partially shipped | processing | Some, but not all, dropship items have shipped |
| `printess_dropship_error` | Printess: Production error | new, processing | Any dropship item failed (render error, rejected by Printess, cancelled or failed at the dropshipper) |

Rules:

- The order status follows the **least advanced** dropship item. An error on any item takes precedence.
- Items without dropshipping do not affect the status. Ship them yourself the normal way.
- When all dropship items have shipped, the Printess status is removed and the order returns to Magento's normal status for its state.
- Every change adds an entry to the order's comment history (not sent to the customer).

#### Errors never block the order

A failed item only sets **Printess: Production error** and adds a comment naming the item and the reason. The order is never put on hold or cancelled. Other items keep being submitted, shipped items still get shipments, and you can still invoice. After a successful retry the status goes back to the correct stage.

#### Orders with several dropship items

All dropship items of one order are submitted together, with a shared link id and the number of items in this send, even if they go to different dropshippers. Printess waits until it has all of them and then sends one combined order to each dropshipper, so the customer can receive them together. For this reason, with Hold Until Paid, dropship items of an order are only released when the whole order is invoiced, and a group with one invalid item is not sent at all (otherwise Printess would wait for the missing item forever).

Repeated updates never create a second shipment for the same tracking number. In the 30-minute sync, Printess may report the tracking number of a partly shipped combined order as `partial`; the module never stores `partial` as a tracking number and adds the real number when it arrives.

### Sending dropship items again

Use **Restart production** on the Printess Production tab when a dropship order failed, was cancelled or got lost (see [Printess Production tab](#printess-production-tab-admin-order-view)). What happens depends on the store setting **Reproducing Dropship Orders**:

| Setting | What Restart production does |
|---|---|
| **Send again under the order number with a suffix** (default) | All dropship items of the order are sent again right away as a new dropship order, `000000123-R1`, `-R2` …, with a freshly saved address, so an address you corrected in the Magento order is used. Tracking of earlier sends is kept; when the items ship again, the new tracking number is added to their shipment and the customer gets a shipment update email (if Send Shipment Email is on). |
| **Ask each time** | The restart page lets you choose: send again with the suffix as above, or **duplicate the order and dropship the copy**. |

#### The order copy

For dropshippers that refuse any number they have seen before, even with a suffix, the copy gets a completely new Magento order number. On the restart page you choose:

- which Printess items to copy (all Printess items of the order are offered; the dropship items sent together are preselected),
- the message to the customer (default: "Due to dropship issues we needed to recreate the order."; at most 1000 characters),
- whether the copy should **reduce stock** (off: the stock taken by the copy is given back).

The copy:

| | |
|---|---|
| Customer, store, currency, addresses | Same as the original (guest orders keep the guest e-mail). If the store no longer allows the original's currency (or has no rate for it), the copy is refused; allow the currency again to create it. |
| Items | The chosen Printess items with the same designs, at their **original prices** (later price changes do not matter) |
| Discount | One discount line, titled with your message (the first 255 characters), that brings the total to **0** |
| Shipping | Free, titled like the original shipping method; the dropshipper gets the original shipping code |
| Payment | "No Payment Information Required" (Zero Subtotal Checkout); the copy is **invoiced and paid** right away |
| Emails | **No order confirmation email.** Your message is added as an order comment the customer can see in their account. Shipment emails work as usual. |
| Link | Both orders show each other: in the admin on the Printess Production tab ("Reproduction of order" / "Reproduced as order") and in the customer account ("Reproduction of order #…"). Guests see "Reproduction of order #…" (number only) under Orders and Returns. |
| Production | The copy's items go **straight to production**, ignoring Hold Until Paid and Approve Before Production; dropship items are sent under the copy's order number. |

If your tax setup (e.g. fixed product taxes or an extension that adds fees) keeps the discount from bringing the total exactly to 0, the copy is created with all items at **price 0** instead. The order is still free; your message and the original prices are kept as an order comment.

The original order is not changed apart from the link; refund or cancel it yourself if needed.

Only one copy of an order can be created at a time ("A copy of this order is already being created."), and the same items cannot be copied again within 60 seconds, so a double click does not create two copies.

**Stock with "Reduce stock" off.** The copy leaves the **salable quantity** unchanged, over the copy's whole life:

- When the copy is placed, its reservations are compensated (MSI: a reservation of type `printess_reproduction`; without MSI the stock is simply not decremented).
- If the copy is **cancelled**, or refunded with **Return to Stock**, Magento gives the quantity back as for any order. Since the copy never took it, the module takes the same quantity away again (MSI: a negative reservation of type `printess_reproduction_cancel` / `printess_reproduction_refund`; without MSI the legacy stock item is corrected and the correction is logged).
- **Shipping** the copy still deducts the quantity from the **source** (physical stock in the warehouse), as for every shipment: the parcel really leaves. Only the salable quantity stays as it was before the copy.

Copies created by an earlier module version do not record whether their stock was reduced and are not corrected on cancel or refund.

### Production status updates (callbacks or polling)

The store setting **Production Status Updates** decides how the shop learns that rendering finished and that dropship items shipped.

**Callbacks (default).** Printess **calls the shop** as soon as something happens, which is faster and makes fewer API calls. Printess calls the store view's own secure base URL (**Stores → Configuration → General → Web → Base URLs (Secure)**), so every store view gets its own callbacks without further setup. The module appends:

| Endpoint | Called when |
|---|---|
| `printess/production/callback` | Rendering of an item (print or dropship) has finished or failed |
| `printess/dropship/callback?order=<order id>` | A dropship item shipped (Printess callback type 3). One call can list several items, each with its own nonce, tracking number and tracking link. |
| `printess/dropship/callback?n=<nonce>` | Old callback, only for dropship items sent before the upgrade to dropship groups |

**Automatic fallback to polling.** Printess only accepts https callback URLs with a public domain name. If the store's URL is not https, is an IP address, or is `localhost`, `*.localhost`, `*.test` or `*.local`, the shop does not send a callback URL (Printess would reject the order) and polls instead; a warning is written to `var/log/system.log` ("production callbacks are not possible").

**Callback URL (override, for debugging).** Leave empty on live shops. Set it only when Printess can't reach the store's own URL, e.g. a local shop behind an HTTP tunnel (ngrok): enter the tunnel's base URL, e.g. `https://abc.ngrok-free.app/`. It must be https with a real domain name; IP addresses and `localhost` are rejected on save.

**Polling.** The production cron checks rendering every 5 minutes, and a dropship sync cron checks dropship orders every 30 minutes. This works everywhere, including on servers Printess cannot reach. Choosing **Polling** switches callbacks off completely, even if a Callback URL is set.

**Shipping updates with a callback URL.** Printess reports each shipment per item. Every report creates one Magento shipment with tracking for the items it lists, so an order that ships in several parcels gets several shipments and shows **Printess: Partially shipped** in between. If an item that already has a shipment ships again (because it was sent again), the new tracking number is added to its existing shipment and the customer gets a shipment update email, if Send Shipment Email is on. Printess repeats callbacks; a tracking number that is already stored is ignored.

With polling, the 30-minute dropship status sync picks up the same shipments.

Requirements for callbacks:

- The store's secure base URL (or the override) must be **https** with a real public domain name; otherwise the shop polls (see above).
- These paths must be reachable from the internet without HTTP auth, IP allowlisting or maintenance mode. If they are not, set Production Status Updates to **Polling**; otherwise only the 2-hour safety net (below) brings updates in.
- In a multi-store setup each store view uses its own base URL; an override must reach the right store view.

**Verification.** Each job gets a random 256-bit nonce when the order is placed, and dropship items get a new one every time they are sent. The nonce is sent to Printess only in the produce request: as the `X-Printess-Nonce` header for the rendering callback, and in the callback payload of the dropship request. A rendering callback is accepted only if its nonce matches a job *and* the payload matches that job (Printess job id). A dropship callback is accepted for the items whose nonce matches the **current** nonce of a dropship item of that order. Unknown orders or nonces get 404, a dropship callback in which no item matches gets 403; neither changes anything. After items were sent again, callbacks for the earlier send therefore get 403: this is expected. The endpoints do not use Magento form keys because the caller is Printess.

**Changing the setting** only affects orders placed afterwards. Orders already in production keep the mode they started with; items still waiting to be sent in callback mode switch to polling if no usable callback URL is left when they are sent. As a safety net, items in callback mode that have not heard back within 2 hours are polled anyway.

### Printess Production tab (admin order view)

Orders with Printess items get a **Printess Production** tab (ACL resource `Printess_PrintessEditor::production`). For each Printess item it shows the type (print or dropship), dropshipper and product definition, job status, partner state, the order number sent to the dropshipper (with the send count), all tracking numbers and links, result files, and the last error code and message. For an order copy it also links the original order, and the original links its copies. The tab lists every shipment of each item with the dropship order number it was sent under (e.g. 000000123-R1), and on an original order also the production status and tracking of its reproduction copies.

**Dropship items**

| Action | What it does |
|---|---|
| **Approve for production** | Opens a page listing all dropship items of the order that are sent together (thumbnail, name, quantity) and the order number the dropshipper will receive. Confirming approves them all and sends them to Printess right away. |
| **Restart production** | Opens the same page for sending the items again: it lists the items and the next order number (e.g. `000000123-R1`). Confirming sends all dropship items of the order again as a new dropship order with a fresh address. In "Ask each time" mode the page also offers to duplicate the order instead. See [Sending dropship items again](#sending-dropship-items-again). Available once the items have been sent (not while they wait for payment, approval or the next cron run). |
| **Re-render without dropshipping** | Renders the item's files again under a new Printess order without dropshipping (`/order/retry`); you fulfil the item yourself and it is no longer tracked as a dropship item. |
| **Check status** | Asks Printess right away for the rendering result and the dropship order state, instead of waiting for cron or a callback. |

**Print items** (unchanged)

| Action | What it does |
|---|---|
| **Approve for production** | Releases the item; it is submitted by the next cron run. |
| **Retry production** | Re-runs an item that already has a Printess order (`/order/retry`) under a new Printess job and order id. *Retry with payment reload* is offered after a payment-related rejection. |
| **Resubmit** | For items that never got a Printess order (e.g. rejected before an order was created, or an `outcome_unknown` submission): sends a fresh produce request with a new nonce. |
| **Check status** | As above. |

**Before you restart, retry or resubmit:**

- **The item may be produced and shipped twice.** If the earlier submission already reached the dropshipper, both will be produced. Check the order in the Printess portal (and with the dropshipper) first, and cancel it there if needed. You are asked to confirm.
- **Retry and Re-render without dropshipping are not available for orders older than 30 days**: Printess no longer has the original variable data. Orders created in the Printess editor cannot be retried either. Restart production sends the saved designs again and has no such limit, as long as the designs (save tokens) have not expired at Printess.

### Troubleshooting

Error codes are shown on the Printess Production tab and in the order comments. Details are logged to `var/log/system.log` (search for "Printess").

| Error / symptom | Meaning | What to do |
|---|---|---|
| `outcome_unknown` | The shop was interrupted while sending the item (timeout, PHP crash, deployment) and does not know whether Printess received it. It is never resent automatically, to avoid a duplicate shipment. | Look for the order in the Printess portal (search for the order number). If it is there, do **not** resubmit: the item is already in production. Follow it in the Printess portal and create the Magento shipment manually when it ships. If it is not there, use **Resubmit** (print items) or **Restart production** (dropship items). |
| `printess_202` | The customer's save token (design) has expired at Printess. | The design cannot be produced. Contact the customer to recreate it. To prevent this, use Hold Until Paid only with payment methods that are paid quickly, and set a long enough save-token expiry in your Printess account. |
| `printess_<code>` (other) | Printess rejected the request (e.g. `601`–`603` not found, often a service token from a different account). | Check the service token at the order's store scope and the product definition. Fix, then **Resubmit** (print items) or **Restart production** (dropship items). |
| `no_save_token`, `no_product_definition`, `no_address`, `item_missing`, `group_invalid` — "The dropship group was not sent (nothing reached Printess)" | The dropship items were checked before sending and at least one is not valid (no design, no product definition, no address, item deleted; `group_invalid` = several problems). Because the items are sent together, **none** of them was sent. The message names the item. | Fix the item (e.g. set the product definition, add an address to the order), then **Restart production**. If one item cannot be fixed, use **Re-render without dropshipping** or ship it yourself, and refund or handle it separately. |
| `partner_99` | The dropshipper reported an error (message shown). Often a bad address: addresses are not validated when saved, only by the dropshipper. | Fix the cause (e.g. correct the address in the Magento order) and **Restart production**: the fresh address is sent. |
| `partner_98` | The dropshipper cancelled the order. | Clarify with the dropshipper. **Restart production** if it should still be produced, otherwise refund the item. |
| Dropshipper refuses the order number, also with `-R1` | Some dropshippers never accept a number they have seen before. | Set **Reproducing Dropship Orders** to "Ask each time" and duplicate the order on the restart page. See [The order copy](#the-order-copy). |
| `http_402` | Payment problem with the Printess account. | Fix billing in Printess, then **Retry with payment reload** (print items) or **Restart production** (dropship items). |
| Dropship callback answered with 403 in the log or in Printess | Usually a callback for an earlier send: after **Restart production** the items have new nonces, so updates for the old dropship order are rejected. | Expected; nothing to do. If it happens for items that were never sent again, check that the callback reaches the right store view. |
| Item shipped (per Printess / dropshipper) but no Magento shipment | The callback did not reach the shop, or the shipment could not be created (e.g. order on hold, item already shipped manually, missing invoice rules). | Click **Check status**. If there is still no shipment, create it manually in Magento (Ship → add tracking number). |
| Dropship Product list is empty | No service token saved for this scope, or no product definitions in the Printess account. | Save the service token first, then reload. Lists are cached for about 10 minutes. |
| Nothing happens after checkout | Cron is not running, or the order's store has no service token (jobs of that store are skipped with a warning in the log). | Check `bin/magento cron:run` and the token at the right scope. |
| Order stays at "Printess: Production error" | One dropship item failed; other items may be fine. | Handle the failed item (restart production, re-render without dropshipping, or ship manually). The status updates once no item is in error. |

---

## URL Endpoints

| URL | Method | Description |
|---|---|---|
| `/printess/project/index` | GET | My Projects account page |
| `/printess/project/save` | POST | Save a project (called by SDK callback) |
| `/printess/project/open` | POST | Open a saved project for editing |
| `/printess/project/rename` | POST | Rename a project |
| `/printess/project/delete` | POST | Delete a project |
| `/printess/production/callback` | POST | Printess "rendering finished" callback (only used in callback mode) |
| `/printess/dropship/callback?order=<order id>` | POST | Printess dropship tracking callback, one nonce per item (callback type 3; only used in callback mode) |
| `/printess/dropship/callback?n=<nonce>` | POST | Old dropship callback, only for items sent before the upgrade to dropship groups |

---

## Architecture

```
Printess/PrintessEditor/
├── Block/
│   └── Projects.php              # My Projects account page block
├── Controller/Project/
│   ├── Delete.php
│   ├── Index.php                 # My Projects listing
│   ├── Open.php                  # Reopen saved project in editor
│   ├── Rename.php
│   └── Save.php                  # Unified save (SDK callback + form POST)
├── Cron/
│   └── SendProjectReminders.php  # Order + removal reminder emails
├── Helper/
│   └── Config.php                # Store config helper
├── Model/
│   ├── Project.php               # Project model
│   ├── ProjectConfig.php         # Project-specific config
│   ├── ProjectMailer.php         # Email sending logic
│   └── ProjectManager.php        # Business logic, ownership enforcement
├── Observer/
│   └── AddSaveTokenToQuote.php   # Attaches save token to quote item on add-to-cart
├── Setup/
│   └── InstallSchema.php         # printess_project DB table
├── ViewModel/Product/
│   └── PrintessData.php          # Product page view model (locale)
└── view/frontend/
    ├── layout/
    │   ├── catalog_product_view.xml        # Injects addtocart.phtml on product page
    │   ├── customer_account.xml            # Adds My Designs nav link
    │   └── printess_project_index.xml      # My Projects page layout
    ├── templates/
    │   ├── product/view/addtocart.phtml    # Product page editor embed
    │   └── projects/list.phtml             # My Projects listing template
    └── web/
        ├── css/
        │   └── printess-projects.css       # My Projects page styles
        └── js/
            ├── printess-integration.js     # Core Printess SDK integration
            ├── printess-login-gate.js      # Login gate + product page save flow
            ├── project-edit.js             # Continue Editing flow from My Projects
            └── projects.js                 # My Projects page interactions
```

---

## Security

- All project endpoints enforce session authentication (`CustomerSession::isLoggedIn()`)
- `ProjectManager::getOwnedProject()` enforces `customer_id` ownership on every mutation — customers cannot access or modify each other's projects
- CSRF is intentionally disabled on `/printess/project/save` — the Printess SDK cannot include Magento form keys in its callbacks; session auth is the security control
- All other endpoints (rename, delete, open) use standard Magento form key validation
- The production and dropship callbacks have no form key either (the caller is Printess); each request must carry the current per-job nonce (and, for the rendering callback, match that job's ids), see [Production status updates](#production-status-updates-callbacks-or-polling)
- The product page template contains no customer-specific server-side rendering — safe for full-page cache

---

## SDK Callback Reference

The Printess SDK `saveTemplateCallback` signature is:

```js
saveTemplateCallback(saveToken, type, thumbnailUrl)
// type: "save" | "close"
// "save"  → user pressed Save, stay in editor
// "close" → user pressed Save & Quit, redirect to My Projects
```
