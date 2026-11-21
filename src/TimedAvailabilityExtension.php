<?php

declare(strict_types=1);

namespace SilverShop\TimedAvailability;

use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Timed availability: an on-sale window (AvailableFrom / AvailableUntil) plus a manual override, applied to a
 * {@link \SilverShop\Page\Product} and a {@link ProductCategory}. Outside the window the item is not purchasable
 * ({@see self::canPurchase()}) and is dropped from category listings ({@see self::updateProductsShowable()}); the
 * window simply recurs by editing the dates each season. The CMS stays fully editable year-round — the gate is
 * front-of-shop only.
 *
 * Three modes:
 *  - `Auto` (default): availability follows the [AvailableFrom, AvailableUntil] window (either bound optional).
 *  - `ForceOn`: always available, ignoring the dates (open early / extend a strong season).
 *  - `ForceOff`: never available, ignoring the dates (pull something immediately).
 *
 * A Product on `Auto` with no window of its own inherits its immediate parent category's window, so you can set the
 * dates once on the "Christmas" category and leave the products blank. Both dates empty + `Auto` with no seasonal
 * parent = always available, so the extension is a no-op on an ordinary product.
 *
 * @extends Extension<\SilverShop\Page\Product|ProductCategory>
 */
class TimedAvailabilityExtension extends Extension
{
    private static array $db = [
        'AvailableFrom' => 'Datetime',
        'AvailableUntil' => 'Datetime',
        'AvailabilityMode' => "Enum('Auto,ForceOn,ForceOff','Auto')",
        // How an out-of-season item behaves for SEO/visibility. `Default` defers to the global `out_of_season` config.
        'OutOfSeasonVisibility' => "Enum('Default,Shown,Noindex,Hidden','Default')",
    ];

    /**
     * Site-wide default for what an out-of-season item does, used when a record is on `Default`:
     *  - `shown`  : still viewable (shows "Sold out"), stays indexable (today's behaviour).
     *  - `noindex`: still viewable, but `noindex` robots meta + excluded from the Google sitemap.
     *  - `hidden` : canView() returns false for the public → 404, and the sitemap/search drop it (they follow canView).
     * The gate only ever applies out of season; in-season items are untouched. CMS users always keep access.
     */
    private static string $out_of_season = 'shown';

    /**
     * Is this product/category on sale right now? `ForceOn`/`ForceOff` win; otherwise the optional
     * [AvailableFrom, AvailableUntil] window is checked against the current time. A Product on `Auto` with no window
     * of its own defers to its immediate parent category's window.
     */
    public function IsAvailableNow(): bool
    {
        $mode = (string) $this->owner->AvailabilityMode;
        if ($mode === 'ForceOn') {
            return true;
        }
        if ($mode === 'ForceOff') {
            return false;
        }

        $from = (string) $this->owner->AvailableFrom;
        $until = (string) $this->owner->AvailableUntil;

        // Auto with no own window → inherit the immediate parent category's window (one level), when present.
        if ($from === '' && $until === '') {
            $parent = $this->inheritedWindowOwner();
            return $parent instanceof ProductCategory ? $parent->IsAvailableNow() : true;
        }

        $now = DBDatetime::now()->getTimestamp();
        if ($from !== '' && $now < strtotime($from)) {
            return false;
        }
        if ($until !== '' && $now > strtotime($until)) {
            return false;
        }
        return true;
    }

    /**
     * Inverse of {@see self::IsAvailableNow()} — a template-safe flag for hiding an out-of-season page in navigation.
     * Guard menu loops with `<% if not $IsHiddenBySchedule %>`: a page without this extension returns an undefined
     * (falsey) value, so it is shown; only a seasonal page that is out of its window is hidden.
     */
    public function IsHiddenBySchedule(): bool
    {
        return !$this->IsAvailableNow();
    }

    /**
     * The resolved out-of-season SEO/visibility mode for this record: `shown`, `noindex` or `hidden`. A record on
     * `Default` falls back to the global {@see self::$out_of_season} config.
     */
    public function effectiveOutOfSeasonMode(): string
    {
        $mode = strtolower((string) $this->owner->OutOfSeasonVisibility);
        if ($mode === '' || $mode === 'default') {
            $mode = strtolower((string) Config::inst()->get(self::class, 'out_of_season'));
        }

        return in_array($mode, ['shown', 'noindex', 'hidden'], true) ? $mode : 'shown';
    }

    /**
     * In `hidden` mode an out-of-season page is invisible to the public: returning false here gives a 404, and the
     * Google sitemap and internal search drop it automatically because they consult canView(). CMS users keep access
     * (so the page stays editable year-round), and every other case returns null — no opinion — leaving core's own
     * canView untouched. `extendedCan()` takes the lowest non-null result, so this only ever removes access.
     */
    public function canView($member = null): ?bool
    {
        if (
            !$this->IsAvailableNow()
            && $this->effectiveOutOfSeasonMode() === 'hidden'
            && !Permission::check('CMS_ACCESS', 'any', $member)
        ) {
            return false;
        }

        return null;
    }

    /**
     * In `hidden` mode an out-of-season page must read as genuinely absent: a clean 404 for the public, not the
     * login redirect a bare canView() denial produces. This hook fires on the page before the controller's
     * permission check ({@see \SilverStripe\CMS\Controllers\ContentController::init()}), so the 404 wins. CMS users
     * pass through (they have CMS access and canView() allows them). canView() still returns false here, so the
     * Google sitemap and internal search drop the page too.
     *
     */
    public function contentcontrollerInit(\SilverStripe\CMS\Controllers\ContentController $controller): void
    {
        if (
            !$this->IsAvailableNow()
            && $this->effectiveOutOfSeasonMode() === 'hidden'
            && !Permission::check('CMS_ACCESS')
        ) {
            $controller->httpError(404);
        }
    }

    /**
     * Force `noindex` while out of season in `noindex` mode. Overrides silvershop-seo's robots descriptor when that
     * module is present (same key, so there is never a duplicate robots meta), otherwise emits our own. This
     * extension is ordered after silvershop-seo (see _config) so the override wins. In-season pages are untouched,
     * so a merchant's own per-page robots setting still applies the rest of the year.
     *
     * @param array<string, mixed> $tags
     */
    public function updateMetaComponents(array &$tags): void
    {
        if ($this->IsAvailableNow() || $this->effectiveOutOfSeasonMode() !== 'noindex') {
            return;
        }

        $key = $this->owner->hasMethod('EffectiveRobots') ? 'ssseoRobots' : 'taRobots';
        $tags[$key] = ['attributes' => ['name' => 'robots', 'content' => 'noindex,follow']];
    }

    /**
     * Keep an out-of-season item out of the Google sitemap in `noindex` and `hidden` modes (wilr/googlesitemaps
     * reduces this hook with min(), so returning false excludes it). `hidden` is already covered via canView(); this
     * also handles `noindex`, where the page stays viewable but must not be advertised in the sitemap.
     */
    public function alterCanIncludeInGoogleSitemap(mixed $can): bool
    {
        if (!$this->IsAvailableNow() && in_array($this->effectiveOutOfSeasonMode(), ['noindex', 'hidden'], true)) {
            return false;
        }

        return (bool) $can;
    }

    /**
     * The parent whose window a blank Product inherits: its immediate parent category, when that is a seasonal
     * category. Only products inherit — a category never inherits from its own parent (so there is no recursion
     * beyond one Product → ProductCategory hop).
     */
    private function inheritedWindowOwner(): ?ProductCategory
    {
        if ($this->owner instanceof ProductCategory) {
            return null;
        }
        $parent = $this->owner->Parent();
        if ($parent instanceof ProductCategory && $parent->hasExtension(self::class)) {
            return $parent;
        }
        return null;
    }

    /**
     * Veto a purchase when the product is out of season. Core merges extension `canPurchase` results with `min()`, so
     * returning `false` blocks the buy; `true` leaves the decision to core and the other decorators (price, stock, …).
     * A category never reaches core's purchase path, so it simply never vetoes.
     */
    public function canPurchase(?Member $member = null, int $quantity = 1): bool
    {
        if ($this->owner instanceof ProductCategory) {
            return true;
        }
        return $this->IsAvailableNow();
    }

    /**
     * Drop out-of-season products from a category's product list on the normal (tree-walked) path.
     * {@see self::applyAvailabilityFilter()} does the work; {@see self::overrideProductsShowable()} covers the
     * early-override path a category-index module takes.
     */
    public function updateProductsShowable(DataList &$products): void
    {
        $this->applyAvailabilityFilter($products);
    }

    /**
     * Also filter the list when another module supplies it up front via `overrideProductsShowable` (e.g.
     * silvershop-suite/category-index's indexed membership list), because core returns that list immediately and
     * never reaches {@see self::updateProductsShowable()}. This extension is ordered after category-index (see
     * _config) so the override is already populated when we filter it. No-op unless a DataList was supplied.
     *
     * @param mixed $override
     */
    public function overrideProductsShowable(&$override, $recursive): void
    {
        if ($override instanceof DataList) {
            $this->applyAvailabilityFilter($override);
        }
    }

    /**
     * Exclude out-of-season products from a product DataList, as SQL (kept lazy for pagination). Mirrors
     * {@see self::IsAvailableNow()}: `ForceOn` always in; `ForceOff` always out; anything else (`Auto`, or an
     * unset/empty mode) follows the window (either bound optional). Treating "not ForceOff" as the window branch
     * keeps it correct even when the Enum default was never written to the row.
     */
    private function applyAvailabilityFilter(DataList &$products): void
    {
        $now = DBDatetime::now()->getValue();
        $products = $products->where([
            '('
            . '"SilverShop_Product"."AvailabilityMode" = ?'
            . ' OR ('
            . '("SilverShop_Product"."AvailabilityMode" IS NULL OR "SilverShop_Product"."AvailabilityMode" != ?)'
            . ' AND ("SilverShop_Product"."AvailableFrom" IS NULL OR "SilverShop_Product"."AvailableFrom" <= ?)'
            . ' AND ("SilverShop_Product"."AvailableUntil" IS NULL OR "SilverShop_Product"."AvailableUntil" >= ?)'
            . ')'
            . ')' => ['ForceOn', 'ForceOff', $now, $now],
        ]);
    }

    /**
     * Fold the live availability into the product-card cache fingerprint (silvershop-suite/card-cache), so a cached
     * card is re-rendered when the window opens or closes instead of showing a stale out-of-season card. No-op when
     * card-cache is not installed (the hook is simply never called).
     *
     * @param array<int, string> $parts
     */
    public function updateProductCardFingerprint(array &$parts): void
    {
        $parts[] = 'ta:' . ($this->IsAvailableNow() ? '1' : '0');
    }

    public function updateCMSFields(FieldList $fields): void
    {
        // Core scaffolds extension $db into the main tab; remove those before re-placing configured versions on a
        // dedicated Availability tab, or the FieldList reports the fields twice.
        $fields->removeByName(['AvailabilityMode', 'AvailableFrom', 'AvailableUntil', 'OutOfSeasonVisibility']);

        $self = self::class;
        $fields->findOrMakeTab('Root.Availability', _t($self . '.TAB', 'Availability'));
        $fields->addFieldsToTab('Root.Availability', [
            DropdownField::create('AvailabilityMode', _t($self . '.MODE', 'On sale'), [
                'Auto' => _t($self . '.MODE_AUTO', 'Automatic — use the dates below'),
                'ForceOn' => _t($self . '.MODE_ON', 'Always on sale — ignore the dates'),
                'ForceOff' => _t($self . '.MODE_OFF', 'Never on sale — ignore the dates'),
            ])->setDescription(_t(
                $self . '.MODE_DESC',
                'Automatic follows the window below. Use Always/Never to force it on or off instantly without '
                . 'touching the dates.'
            )),
            DatetimeField::create('AvailableFrom', _t($self . '.FROM', 'On sale from'))
                ->setDescription(_t(
                    $self . '.FROM_DESC',
                    'Leave empty for no start limit. The exact moment it goes on sale.'
                )),
            DatetimeField::create('AvailableUntil', _t($self . '.UNTIL', 'On sale until'))
                ->setDescription(_t(
                    $self . '.UNTIL_DESC',
                    'Leave empty for no end limit. The exact moment it leaves sale — a date with no time means '
                    . '00:00, the start of that day. On a category, a product with no dates of its own inherits '
                    . 'this window.'
                )),
            DropdownField::create('OutOfSeasonVisibility', _t($self . '.SEO', 'When out of season'), [
                'Default' => _t($self . '.SEO_DEFAULT', 'Use the site default'),
                'Shown' => _t($self . '.SEO_SHOWN', 'Shown — page stays visible ("Sold out"), still in Google'),
                'Noindex' => _t($self . '.SEO_NOINDEX', 'No-index — page stays visible, hidden from Google + sitemap'),
                'Hidden' => _t($self . '.SEO_HIDDEN', 'Hidden — page returns 404, invisible to visitors + Google'),
            ])->setDescription(_t(
                $self . '.SEO_DESC',
                'Only applies while out of season. "Hidden" entirely removes the page from the shop and search '
                . 'engines (the sitemap and search follow it automatically). The CMS stays editable year-round.'
            )),
        ]);
    }
}
