<?php

declare(strict_types=1);

namespace SilverShop\TimedAvailability\Tests;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\TimedAvailability\TimedAvailabilityExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class TimedAvailabilityExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        // Freeze "now" so the windows are deterministic (1 December, Sinterklaas week).
        DBDatetime::set_mock_now('2026-12-01 12:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makeProduct(array $data = []): Product
    {
        $product = Product::create();
        $product->Title = 'Chocolate letter';
        $product->BasePrice = 9.95;
        $product->AllowPurchase = true;
        $product->update($data);
        $product->write();
        $product->publishSingle();

        return $product;
    }

    public function testNoWindowIsAvailableAndPurchasable(): void
    {
        $product = $this->makeProduct();
        $this->assertTrue($product->IsAvailableNow());
        $this->assertTrue($product->canPurchase());
    }

    public function testFutureStartIsUnavailable(): void
    {
        $product = $this->makeProduct(['AvailableFrom' => '2026-12-05 00:00:00']);
        $this->assertFalse($product->IsAvailableNow());
        $this->assertFalse($product->canPurchase());
    }

    public function testPastEndIsUnavailable(): void
    {
        $product = $this->makeProduct(['AvailableUntil' => '2026-11-30 23:59:59']);
        $this->assertFalse($product->IsAvailableNow());
        $this->assertFalse($product->canPurchase());
    }

    public function testWithinWindowIsAvailable(): void
    {
        $product = $this->makeProduct([
            'AvailableFrom' => '2026-11-15 00:00:00',
            'AvailableUntil' => '2026-12-06 23:59:59',
        ]);
        $this->assertTrue($product->IsAvailableNow());
        $this->assertTrue($product->canPurchase());
    }

    public function testForceOffBeatsWindow(): void
    {
        $product = $this->makeProduct([
            'AvailabilityMode' => 'ForceOff',
            'AvailableFrom' => '2026-11-15 00:00:00',
            'AvailableUntil' => '2026-12-06 23:59:59',
        ]);
        $this->assertFalse($product->IsAvailableNow());
        $this->assertFalse($product->canPurchase());
    }

    public function testForceOnBeatsWindow(): void
    {
        $product = $this->makeProduct([
            'AvailabilityMode' => 'ForceOn',
            'AvailableUntil' => '2026-11-01 00:00:00',
        ]);
        $this->assertTrue($product->IsAvailableNow());
        $this->assertTrue($product->canPurchase());
    }

    public function testBlankProductInheritsParentCategoryWindow(): void
    {
        $category = ProductCategory::create();
        $category->Title = 'Sinterklaas';
        $category->AvailableUntil = '2026-11-30 23:59:59'; // closed as of mock-now (1 December)
        $category->write();
        $category->publishSingle();

        $product = $this->makeProduct(['ParentID' => $category->ID]); // no window of its own → inherits
        $this->assertFalse($product->IsAvailableNow(), 'a blank product under a closed category is unavailable');
        $this->assertFalse($product->canPurchase());

        // The product's own ForceOn overrides the inherited (closed) window.
        $product->AvailabilityMode = 'ForceOn';
        $this->assertTrue($product->IsAvailableNow());
    }

    public function testProductsShowableExcludesOutOfSeason(): void
    {
        $category = ProductCategory::create();
        $category->Title = 'Shop';
        $category->write();
        $category->publishSingle();

        $this->makeProduct([
            'Title' => 'Kerstkransje',
            'ParentID' => $category->ID,
            'AvailableUntil' => '2026-12-31 23:59:59',
        ]);
        $this->makeProduct([
            'Title' => 'Paasei',
            'ParentID' => $category->ID,
            'AvailableUntil' => '2026-04-30 23:59:59',
        ]);
        $this->makeProduct([
            'Title' => 'Verborgen',
            'ParentID' => $category->ID,
            'AvailabilityMode' => 'ForceOff',
        ]);

        $showable = $category->ProductsShowable()->column('Title');
        $this->assertContains('Kerstkransje', $showable);
        $this->assertNotContains('Paasei', $showable);
        $this->assertNotContains('Verborgen', $showable);
    }

    public function testOverrideProductsShowableIsFiltered(): void
    {
        $category = ProductCategory::create();
        $category->Title = 'Shop';
        $category->write();
        $category->publishSingle();

        $this->makeProduct(['Title' => 'Kerstkransje', 'AvailableUntil' => '2026-12-31 23:59:59']);
        $this->makeProduct(['Title' => 'Verborgen', 'AvailabilityMode' => 'ForceOff']);

        // Simulate a category-index module supplying the whole list up front (the early override path that
        // short-circuits ProductsShowable before the updateProductsShowable hook).
        $override = Product::get();
        $recursive = true;
        $category->extend('overrideProductsShowable', $override, $recursive);

        $titles = $override->column('Title');
        $this->assertContains('Kerstkransje', $titles);
        $this->assertNotContains('Verborgen', $titles);
    }

    public function testHiddenModeDeniesPublicButNotCmsUsers(): void
    {
        $product = $this->makeProduct([
            'AvailabilityMode' => 'ForceOff',
            'OutOfSeasonVisibility' => 'Hidden',
        ]);

        $this->logOut();
        $this->assertFalse($product->canView(), 'hidden out-of-season product 404s for the public');

        $admin = $this->logInWithPermission('ADMIN');
        $this->assertNotFalse($product->canView($admin), 'CMS users keep access year-round');
    }

    public function testShownModeDoesNotHidePage(): void
    {
        $product = $this->makeProduct([
            'AvailabilityMode' => 'ForceOff',
            'OutOfSeasonVisibility' => 'Shown',
        ]);
        $this->logOut();
        $this->assertTrue($product->canView(), 'shown out-of-season product stays publicly viewable');
    }

    public function testNoindexModeSetsRobotsMeta(): void
    {
        $product = $this->makeProduct([
            'AvailabilityMode' => 'ForceOff',
            'OutOfSeasonVisibility' => 'Noindex',
        ]);
        $tags = [];
        $product->extend('updateMetaComponents', $tags);

        // silvershop-seo is absent in the clean-room, so our own key is used.
        $this->assertArrayHasKey('taRobots', $tags);
        $this->assertStringContainsString('noindex', $tags['taRobots']['attributes']['content']);

        // An in-season product is never forced to noindex.
        $inSeason = $this->makeProduct(['OutOfSeasonVisibility' => 'Noindex']);
        $tags2 = [];
        $inSeason->extend('updateMetaComponents', $tags2);
        $this->assertArrayNotHasKey('taRobots', $tags2);
    }

    public function testSitemapExclusion(): void
    {
        $hidden = $this->makeProduct(['AvailabilityMode' => 'ForceOff', 'OutOfSeasonVisibility' => 'Hidden']);
        $noindex = $this->makeProduct(['AvailabilityMode' => 'ForceOff', 'OutOfSeasonVisibility' => 'Noindex']);
        $shown = $this->makeProduct(['AvailabilityMode' => 'ForceOff', 'OutOfSeasonVisibility' => 'Shown']);
        $inSeason = $this->makeProduct(['OutOfSeasonVisibility' => 'Hidden']);

        $this->assertFalse($hidden->alterCanIncludeInGoogleSitemap(true));
        $this->assertFalse($noindex->alterCanIncludeInGoogleSitemap(true));
        $this->assertTrue($shown->alterCanIncludeInGoogleSitemap(true));
        $this->assertTrue($inSeason->alterCanIncludeInGoogleSitemap(true));
    }

    public function testGlobalConfigDefaultDrivesVisibility(): void
    {
        Config::modify()->set(TimedAvailabilityExtension::class, 'out_of_season', 'hidden');
        // OutOfSeasonVisibility left on its 'Default' → resolves to the global config.
        $product = $this->makeProduct(['AvailabilityMode' => 'ForceOff']);
        $this->assertSame('hidden', $product->effectiveOutOfSeasonMode());

        $this->logOut();
        $this->assertFalse($product->canView(), 'global default of hidden applies to a record on Default');
    }
}
