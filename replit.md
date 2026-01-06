# WC Backorder Confirmation - WordPress Plugin

## Overview
This repository contains a **WordPress/WooCommerce plugin** that enables backorder confirmation functionality. It is NOT a standalone application - it must be installed on a WordPress site with WooCommerce.

The Replit environment serves a documentation page explaining the plugin and how to install it.

## Project Type
- **Type**: WordPress Plugin (PHP)
- **Replit Purpose**: Documentation/Landing page served via static file server
- **Runtime**: Node.js with `serve` package

## Structure
```
/
├── index.html                           # Documentation landing page
├── wc-backorder-confirmation.php        # Main plugin file
├── includes/
│   ├── class-wc-email-encomenda.php     # Custom email class
│   └── class-wc-integration-tiny-webhook.php  # Tiny ERP integration
├── templates/emails/                    # Email templates
├── languages/                           # Translation files (pt_BR)
└── assets/                              # CSS/JS assets
```

## Workflow
- **Documentation Site**: `npx serve -l 5000 .` - Serves the documentation page on port 5000

## Plugin Requirements (for WordPress installation)
- WordPress 6.0+
- WooCommerce 4.0+
- PHP 7.4+

## Key Features
- Smart backorder detection with confirmation checkbox
- Custom email templates for backorder orders
- Tiny (Olist) ERP integration for marker synchronization
- Support for both simple and variable products
- Server-side validation
- Portuguese (Brazil) translations included
