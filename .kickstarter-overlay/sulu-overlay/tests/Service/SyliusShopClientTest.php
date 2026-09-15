<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SyliusShopClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for the Sylius Shop API client, driven by simulated
 * responses (Symfony's MockHttpClient) - no running Sylius needed.
 *
 * WHY THESE EXIST: every API detail this class relies on was discovered
 * by hand against a live instance and written down in FIXES.md (No. 23,
 * 26, 27). None of it was repeatable - after a Sylius upgrade the whole
 * investigation would have to be redone from scratch. These tests don't
 * replace that (a mock can't tell you Sylius changed its response
 * shape), but they do pin down OUR side: given a response of the shape
 * we verified, the client must still extract the right values. If
 * someone refactors this class and quietly breaks the price path or the
 * collection unwrapping, that now fails loudly here instead of showing
 * up as missing prices in the storefront.
 */
final class SyliusShopClientTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function clientReturning(array $payload, int $status = 200): SyliusShopClient
    {
        $mock = new MockHttpClient(new MockResponse(
            json_encode($payload, \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/ld+json']],
        ));

        return new SyliusShopClient($mock, new NullLogger());
    }

    /**
     * A minimal product resource in the shape the real API returns -
     * price nested under defaultVariantData, in cents (FIXES.md No. 23).
     *
     * @return array<string, mixed>
     */
    private function productPayload(string $code = 'fender_stratocaster'): array
    {
        return [
            'code' => $code,
            'name' => 'Fender Stratocaster',
            'slug' => 'fender-stratocaster',
            'shortDescription' => 'Die Legende.',
            'mainTaxon' => '/api/v2/shop/taxons/guitars',
            'images' => [['path' => 'media/image/abc.jpg']],
            'defaultVariantData' => [
                'price' => 129900,
                'originalPrice' => 129900,
                'inStock' => true,
            ],
        ];
    }

    // -----------------------------------------------------------------
    //  Collection unwrapping
    // -----------------------------------------------------------------

    public function testReadsCollectionsFromHydraMember(): void
    {
        $client = $this->clientReturning(['hydra:member' => [$this->productPayload()]]);

        $products = $client->fetchFeaturedProducts();

        self::assertCount(1, $products);
        self::assertSame('fender_stratocaster', $products[0]['code']);
    }

    public function testAlsoReadsCollectionsFromPlainMember(): void
    {
        // API Platform serves collections under either key depending on
        // version - checking both was a deliberate decision, not a
        // guess (FIXES.md No. 23). This pins it down.
        $client = $this->clientReturning(['member' => [$this->productPayload()]]);

        self::assertCount(1, $client->fetchFeaturedProducts());
    }

    public function testUnexpectedCollectionShapeYieldsNoProductsRatherThanAnError(): void
    {
        $client = $this->clientReturning(['something-else' => 'unexpected']);

        self::assertSame([], $client->fetchFeaturedProducts());
    }

    public function testNonArrayEntriesInACollectionAreSkipped(): void
    {
        // extractCollection() filters these out deliberately: every
        // caller immediately does $item['code'] on them (FIXES.md
        // No. 41). A scalar slipping through would be a TypeError.
        $client = $this->clientReturning([
            'hydra:member' => ['a bare string', 42, null, $this->productPayload()],
        ]);

        $products = $client->fetchFeaturedProducts();

        self::assertCount(1, $products);
        self::assertSame('fender_stratocaster', $products[0]['code']);
    }

    // -----------------------------------------------------------------
    //  Product mapping
    // -----------------------------------------------------------------

    public function testPriceIsReadFromDefaultVariantDataInCents(): void
    {
        $client = $this->clientReturning(['hydra:member' => [$this->productPayload()]]);

        $product = $client->fetchFeaturedProducts()[0];

        self::assertSame(129900, $product['priceCents']);
        self::assertTrue($product['inStock']);
    }

    public function testMainTaxonIriIsReducedToItsCode(): void
    {
        $client = $this->clientReturning(['hydra:member' => [$this->productPayload()]]);

        self::assertSame('guitars', $client->fetchFeaturedProducts()[0]['mainTaxonCode']);
    }

    public function testProductsWithoutCodeOrNameAreSkipped(): void
    {
        $client = $this->clientReturning([
            'hydra:member' => [
                ['name' => 'No code here'],
                ['code' => 'no_name_here'],
                $this->productPayload(),
            ],
        ]);

        $products = $client->fetchFeaturedProducts();

        self::assertCount(1, $products);
    }

    public function testANonIntegerPriceBecomesNullInsteadOfBeingCoerced(): void
    {
        // Showing a wrong price is worse than showing none, so a
        // surprising type is discarded rather than cast.
        // Built in one piece rather than mutating a nested key, so
        // static analysis can see the shape (the payload helper returns
        // array<string, mixed>, which makes $payload['x']['y'] = ...
        // an offset access on mixed).
        $payload = $this->productPayload();
        $payload['defaultVariantData'] = ['price' => '129900', 'inStock' => true];

        $client = $this->clientReturning(['hydra:member' => [$payload]]);

        self::assertNull($client->fetchFeaturedProducts()[0]['priceCents']);
    }

    public function testMissingOptionalFieldsDoNotBreakMapping(): void
    {
        $client = $this->clientReturning([
            'hydra:member' => [['code' => 'minimal', 'name' => 'Minimal']],
        ]);

        $product = $client->fetchFeaturedProducts()[0];

        self::assertSame('minimal', $product['code']);
        self::assertNull($product['priceCents']);
        self::assertNull($product['imagePath']);
        self::assertNull($product['mainTaxonCode']);
    }

    public function testTheLimitIsRespected(): void
    {
        $client = $this->clientReturning([
            'hydra:member' => [
                $this->productPayload('a'),
                $this->productPayload('b'),
                $this->productPayload('c'),
            ],
        ]);

        self::assertCount(2, $client->fetchFeaturedProducts('germany', 2));
    }

    // -----------------------------------------------------------------
    //  Channel selection via the Host header (FIXES.md No. 15)
    // -----------------------------------------------------------------

    public function testChannelIsSelectedViaTheHostHeader(): void
    {
        // This is the mechanism a whole class of bugs came down to: the
        // client connects to the internal container name but sends the
        // channel's public hostname as Host, because that's how Sylius
        // decides which channel a request belongs to (FIXES.md No. 15).
        $seen = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
            // Normalised options carry headers as "Key: Value" strings;
            // encoding the whole lot keeps this robust either way.
            $seen[] = ['url' => $url, 'headers' => (string) json_encode($options['headers'] ?? [])];

            return new MockResponse(json_encode(['hydra:member' => []], \JSON_THROW_ON_ERROR));
        });

        $client = new SyliusShopClient($mock, new NullLogger());

        $client->fetchFeaturedProducts('switzerland');
        self::assertStringStartsWith('http://sylius', $seen[0]['url'], 'must connect to the internal container');
        self::assertStringContainsString('switzerland.localhost', $seen[0]['headers']);
        self::assertStringContainsString('de_CH', $seen[0]['headers']);

        $client->fetchFeaturedProducts('germany');
        self::assertStringContainsString('localhost', $seen[1]['headers']);
        self::assertStringNotContainsString('switzerland', $seen[1]['headers'], 'channels must not bleed into each other');
        self::assertStringContainsString('de_DE', $seen[1]['headers']);
    }

    public function testAnUnknownChannelFallsBackToGermanyRatherThanFailing(): void
    {
        $seen = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
            $seen[] = (string) json_encode($options['headers'] ?? []);

            return new MockResponse(json_encode(['hydra:member' => []], \JSON_THROW_ON_ERROR));
        });

        $client = new SyliusShopClient($mock, new NullLogger());
        $client->fetchFeaturedProducts('does_not_exist');

        self::assertStringContainsString('de_DE', $seen[0]);
    }

    // -----------------------------------------------------------------
    //  Failure behaviour
    // -----------------------------------------------------------------

    public function testAnUnreachableApiYieldsAnEmptyResultInsteadOfAnException(): void
    {
        // The storefront must stay up when Sylius is down - a category
        // page without products beats a 500 (FIXES.md No. 23).
        $mock = new MockHttpClient(static function (): ResponseInterface {
            throw new \RuntimeException('connection refused');
        });

        $client = new SyliusShopClient($mock, new NullLogger());

        self::assertSame([], $client->fetchFeaturedProducts());
        self::assertSame([], $client->fetchTaxons());
        self::assertNull($client->fetchProduct('anything'));
    }

    public function testMalformedJsonYieldsAnEmptyResultInsteadOfAnException(): void
    {
        $mock = new MockHttpClient(new MockResponse('this is not json'));
        $client = new SyliusShopClient($mock, new NullLogger());

        self::assertSame([], $client->fetchFeaturedProducts());
    }

    // -----------------------------------------------------------------
    //  Taxons
    // -----------------------------------------------------------------

    public function testTaxonsAreMappedWithCodeAndName(): void
    {
        $client = $this->clientReturning([
            'hydra:member' => [
                ['code' => 'guitars', 'name' => 'Gitarren', 'slug' => 'gitarren'],
                ['code' => 'drums', 'name' => 'Schlagzeug'],
            ],
        ]);

        $taxons = $client->fetchTaxons();

        self::assertCount(2, $taxons);
        self::assertSame('guitars', $taxons[0]['code']);
        self::assertSame('Gitarren', $taxons[0]['name']);
        self::assertNull($taxons[1]['slug']);
    }

    public function testTaxonsWithoutCodeOrNameAreSkipped(): void
    {
        $client = $this->clientReturning([
            'hydra:member' => [['name' => 'Nameless'], ['code' => 'ok', 'name' => 'Fine']],
        ]);

        self::assertCount(1, $client->fetchTaxons());
    }
}
