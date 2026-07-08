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

---

## Installation

This module is installed as a standalone module in `app/code/Printess/PrintessEditor`.

```bash
php bin/magento module:enable Printess_PrintessEditor
php bin/magento setup:upgrade
php bin/magento cache:flush
```

---

## Configuration

**Admin → Stores → Configuration → Printess Designer**

| Section | Setting | Description |
|---|---|---|
| API & Token | Shop Token | Your Printess shop token |
| Editor | Default Theme | Editor UI theme name |
| Editor | Print Settings | Default print settings name |
| Production | Production Token | Token for production/fulfilment API calls |
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
| `printess_product_btn_label` | Custom label for the Personalise button |
| `printess_theme` | Per-product theme override |
| `printess_print_settings` | Per-product print settings override |
| `printess_merge_template` | Merge template name |
| `printess_magic_photobook_theme` | Magic photobook theme |
| `printess_page_pricing` | JSON array for per-page pricing rules |

---

## URL Endpoints

| URL | Method | Description |
|---|---|---|
| `/printess/project/index` | GET | My Projects account page |
| `/printess/project/save` | POST | Save a project (called by SDK callback) |
| `/printess/project/open` | POST | Open a saved project for editing |
| `/printess/project/rename` | POST | Rename a project |
| `/printess/project/delete` | POST | Delete a project |

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
