<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\SyliusShopClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Integration tests against the REAL, running Sylius Shop API.
 *
 * WHY THESE EXIST ALONGSIDE THE UNIT TESTS: the unit tests in
 * tests/Service simulate API responses, so they verify our own handling
 * but can never notice that Sylius itself started answering
 * differently - a mock happily keeps returning last year's shape
 * forever. These tests close exactly that gap. Every assertion here
 * corresponds to something discovered by hand against a live instance
 * and written into FIXES.md (No. 23, 26, 27); if a Sylius upgrade
 * changes any of it, this suite says so instead of the storefront
 * quietly losing prices or filtering nothing.
 *
 * REQUIREMENTS: running containers with fixtures loaded. Run them with
 *
 *     make test-integration
 *
 * They are excluded from the default `make test` run on purpose: a
 * command that fails whenever Docker happens to be down stops being
 * run at all. Each test also skips itself with a clear message rather
 * than failing when the API isn't reachable, so an accidental run in
 * the wrong context is harmless.
 *
 * NOTE ON MUTATION: the cart tests create real orders in the database.
 * That is deliberate and harmless - Sylius carts without a completed
 * checkout are ordinary abandoned carts, and this is demo data anyway.
 * Nothing here completes a checkout or touches existing orders.
 */
#[Group('integration')]
final class SyliusApiTest extends TestCase
{
    private SyliusShopClient $client;

    protected function setUp(): void
    {
        // A real HTTP client this time - the whole point is to talk to
        // the actual container. "http://sylius" is the service name on
        // the Docker network (see docker-compose.yaml).
        $this->client = new SyliusShopClient(HttpClient::create(), new NullLogger());

        $this->skipUnlessApiIsUsable();
    }

    private function skipUnlessApiIsUsable(): void
    {
        try {
            $taxons = $this->client->fetchTaxons();
        } catch (\Throwable $e) {
            self::markTestSkipped('Sylius API not reachable: ' . $e->getMessage());
        }

        if ([] === $taxons) {
            self::markTestSkipped(
                'Sylius API reachable but returned no taxons - are the fixtures loaded? Try: make fixtures',
            );
        }
    }

    // -----------------------------------------------------------------
    //  Catalog reads
    // -----------------------------------------------------------------

    public function testTaxonsComeBackWithCodeAndName(): void
    {
        $taxons = $this->client->fetchTaxons();
        $codes = array_column($taxons, 'code');

        // The five categories defined in dach_demo.yaml.
        self::assertContains('guitars', $codes);
        self::assertContains('amplifiers', $codes);
        self::assertContains('drums', $codes);
        self::assertContains('microphones', $codes);
        self::assertContains('effects', $codes);

        foreach ($taxons as $taxon) {
            self::assertNotSame('', $taxon['name'], 'every taxon needs a display name');
        }
    }

    public function testProductsCanBeFilteredByTaxon(): void
    {
        // THE important one: the filter parameter is
        // productTaxons.taxon.code, and Sylius SILENTLY IGNORES a wrong
        // one rather than erroring (FIXES.md No. 23). A regression here
        // wouldn't throw - every category page would just show every
        // product. Hence the negative assertion below.
        $guitars = $this->client->fetchProductsByTaxon('guitars');

        self::assertNotEmpty($guitars, 'the guitars category must not be empty with fixtures loaded');

        $codes = array_column($guitars, 'code');
        self::assertContains('fender_stratocaster', $codes);
        self::assertNotContains(
            'shure_sm58',
            $codes,
            'a microphone in the guitars category means the taxon filter is being ignored',
        );
    }

    public function testAnUnknownTaxonYieldsNoProductsRatherThanEverything(): void
    {
        self::assertSame([], $this->client->fetchProductsByTaxon('does_not_exist_' . uniqid()));
    }

    public function testProductsAreAddressableByCode(): void
    {
        // Verified in No. 23: the API resolves products by code, and a
        // slug returns 404 - the opposite of what the URL structure
        // suggests.
        $product = $this->client->fetchProduct('fender_stratocaster');

        self::assertNotNull($product, 'fetching by product code must work');
        self::assertSame('fender_stratocaster', $product['code']);
        self::assertNotSame('', $product['name']);
    }

    public function testFetchingAProductBySlugReturnsNothing(): void
    {
        // Pinning down the asymmetry itself: if a future Sylius made
        // slugs work too, that's worth knowing rather than silently
        // relying on code lookups forever.
        self::assertNull($this->client->fetchProduct('fender-stratocaster'));
    }

    public function testPriceAndStockArriveInsideDefaultVariantData(): void
    {
        // The embedded shape that saved a second API call per product
        // (No. 23). If Sylius stopped embedding it, prices would vanish
        // from the storefront without any error.
        $product = $this->client->fetchProduct('fender_stratocaster');
        self::assertNotNull($product, 'the product must exist before its fields can be checked');
        self::assertArrayHasKey('priceCents', $product, 'the mapped product must carry a price field');
        self::assertArrayHasKey('inStock', $product, 'the mapped product must carry a stock flag');

        self::assertIsInt($product['priceCents'], 'price must arrive as an integer in cents');
        self::assertGreaterThan(0, $product['priceCents']);
        self::assertIsBool($product['inStock']);
    }

    public function testFeaturedProductsRespectTheRequestedLimit(): void
    {
        self::assertCount(3, $this->client->fetchFeaturedProducts('germany', 3));
    }

    // -----------------------------------------------------------------
    //  Channels
    // -----------------------------------------------------------------

    public function testTheSwissChannelIsReachableAndPricedSeparately(): void
    {
        // Channel selection happens via the Host header (No. 15). CHF
        // prices are maintained separately rather than converted, so a
        // difference proves the header actually took effect - if channel
        // selection broke, both would return identical EUR prices.
        $german = $this->client->fetchProduct('fender_stratocaster', 'germany');
        $swiss = $this->client->fetchProduct('fender_stratocaster', 'switzerland');

        self::assertNotNull($german);
        self::assertNotNull($swiss, 'the switzerland channel must resolve');
        self::assertNotSame(
            $german['priceCents'],
            $swiss['priceCents'],
            'identical prices suggest the Host header is not selecting the channel',
        );
    }

    // -----------------------------------------------------------------
    //  Cart lifecycle (FIXES.md No. 26)
    // -----------------------------------------------------------------

    public function testTheFullCartLifecycleWorksAgainstTheRealApi(): void
    {
        // One test rather than four, because each step needs the
        // previous one's output - split up they would either duplicate
        // setup or depend on execution order.
        $cart = $this->client->createCart();
        self::assertNotNull($cart, 'creating a cart must succeed');
        self::assertArrayHasKey('tokenValue', $cart);

        $token = $cart['tokenValue'];
        self::assertIsString($token);
        self::assertNotSame('', $token);

        // Variant code convention from the product fixture.
        $withItem = $this->client->addCartItem($token, 'fender_stratocaster_variant', 2);
        self::assertNotNull($withItem, 'adding an item must succeed');

        $fetched = $this->client->fetchCart($token);
        self::assertNotNull($fetched);
        self::assertSame('cart', $fetched['state'] ?? null, 'a new cart must be in state "cart"');

        // Unpacked step by step rather than reaching straight into
        // $fetched['items'][0]['id']: the cart is array<string, mixed>,
        // so each level needs asserting anyway - and doing it explicitly
        // turns "undefined index" into a readable failure message.
        $items = $fetched['items'] ?? null;
        self::assertIsArray($items, 'the cart must carry an items list');
        self::assertNotEmpty($items, 'the added item must be in the cart');

        $firstItem = $items[0] ?? null;
        self::assertIsArray($firstItem);

        $itemId = $firstItem['id'] ?? null;
        self::assertIsInt($itemId);

        // Quantity changes need application/merge-patch+json, NOT
        // ld+json - different content types on the same resource
        // (No. 26). Sending the wrong one fails outright.
        $updated = $this->client->updateCartItemQuantity($token, $itemId, 5);
        self::assertNotNull($updated, 'changing the quantity must succeed (merge-patch+json)');

        $afterUpdate = $this->client->fetchCart($token);
        self::assertNotNull($afterUpdate);
        $updatedItems = $afterUpdate['items'] ?? null;
        self::assertIsArray($updatedItems);
        $updatedFirst = $updatedItems[0] ?? null;
        self::assertIsArray($updatedFirst);
        self::assertSame(5, $updatedFirst['quantity'] ?? null);

        self::assertTrue($this->client->removeCartItem($token, $itemId), 'removing the item must succeed');

        $afterRemoval = $this->client->fetchCart($token);
        self::assertSame([], $afterRemoval['items'] ?? null, 'the cart must be empty again');
    }

    public function testFetchingAnUnknownCartTokenReturnsNull(): void
    {
        self::assertNull($this->client->fetchCart('definitely-not-a-real-token-' . uniqid()));
    }
}
