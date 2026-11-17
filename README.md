# SilverShop Timed Availability

Put a product or a whole category **on sale for a period only**, and switch it on or off at will.

Built for seasonal ranges: Sinterklaas, Pasen, Moederdag, Vaderdag, Kerst, Valentijn. Set the dates once per
season (you edit them each year by taste and planning), or flip a single toggle to force it on or off right now.

- **On-sale window** — `AvailableFrom` / `AvailableUntil` (either bound optional) on a `Product` **and** a
  `ProductCategory`.
- **Instant override** — `Always on sale` / `Never on sale` ignores the dates, for opening early, extending a
  strong season, or pulling something immediately.
- **Inheritance** — a product with no dates of its own inherits its parent category's window, so you set the
  dates once on the "Sinterklaas" category and leave the products blank.
- **Out of season** the item is **not purchasable** and is **dropped from category listings**. The CMS stays
  fully editable year-round — the gate is front-of-shop only.

No ModelAdmin, no scheduled job: availability is evaluated at request time, so nothing has to run to flip it.

## Requirements

- SilverStripe CMS ^6
- `silvershop/core` ^6

## Installation

```bash
composer require silvershop-suite/timed-availability
```

Then run a `dev/build?flush=all`.

## Usage

Open a product or category in the CMS → the **Availability** tab:

| Field | Meaning |
| --- | --- |
| **On sale** | `Automatic` (use the dates) · `Always on sale` (ignore dates) · `Never on sale` (ignore dates) |
| **On sale from** | Optional start. Empty = no start limit. |
| **On sale until** | Optional end. Empty = no end limit. A date with no time means `00:00` — the start of that day. |

Examples for a chocolate shop:

- **Sinterklaas category** — on the category set *from* `15 Nov` *until* `6 Dec 00:00`; leave the chocolate
  letters inside it blank. The whole range appears mid-November and closes on Sinterklaas.
- **A single Paasei** in a year-round "Bonbons" category — set the window on just that product.
- **Open a day early** — flip the category to `Always on sale` for the day, then back to `Automatic`.

### Behaviour

- `canPurchase()` returns false out of season, so the add-to-cart is refused even on a direct link.
- `ProductCategory::ProductsShowable()` excludes out-of-season products from listings.
- `$IsAvailableNow` (bool) and `$IsHiddenBySchedule` (its inverse) are available in templates. Guard a menu
  loop with `<% if not $IsHiddenBySchedule %>` to hide an out-of-season category — a page without this module
  is never hidden by it.
- With `silvershop-suite/card-cache`, the live availability is folded into the product-card cache fingerprint.

## Translations

Ships with English, Dutch, German, Spanish, French and Italian (`lang/*.yml`).

## License

BSD-3-Clause. Copyright (c) 2026 Remy Vaartjes.

## Credits

Developed by [Remy Vaartjes](https://github.com/RVXD) and the SilverShop-Suite Contributors.
