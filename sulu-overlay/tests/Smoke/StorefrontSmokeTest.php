<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Front-end smoke tests: do the pages a customer actually visits come
 * back, and do they contain what they are supposed to contain?
 *
 * DELIBERATELY ROUTED THROUGH CADDY (http://router) rather than hitting
 * the Sulu container directly. That exercises the whole chain including
 * the routing rules - which is a real gap otherwise: the Caddy config
 * has caused several genuine bugs in this project (FIXES.md No. 15, 16,
 * 28, 30, 33) and nothing else verifies it automatically.
 *
 * WHAT THESE CANNOT DO, stated plainly: this is curl, not a browser.
 * The Adyen Drop-in widget renders itself with JavaScript, so a test
 * here only ever sees the empty <div class="dropin-container"> it
 * mounts into - never the actual payment form. Same for the Sulu admin,
 * which is a JavaScript SPA: a 200 is all that can be asserted. Testing
 * either properly needs a real browser (Panther or Playwright), which
 * would be substantially more infrastructure than this project has
 * today.
 *
 * Run with:
 *     make test-smoke
 *
 * Needs running containers with fixtures loaded, and the Sulu homepage
 * published (the one manual step after make setup). Tests skip
 * themselves with an explanatory message rather than failing when the
 * stack isn't up.
 */
#[Group('smoke')]
final class StorefrontSmokeTest extends TestCase
{
    private const BASE = 'http://router';
    private const ADMIN_BASE = 'http://router:8082';

    private HttpClientInterface $http;

    protected function setUp(): void
    {
        $this->http = HttpClient::create([
            // The public hostname, as a browser would send it - Sylius
            // selects its channel from this (FIXES.md No. 15).
            'headers' => ['Host' => 'localhost'],
            'timeout' => 15,
        ]);

        $this->skipUnlessStackIsUp();
    }

    private function skipUnlessStackIsUp(): void
    {
        try {
            $status = $this->http->request('GET', self::BASE . '/produkte/')->getStatusCode();
        } catch (\Throwable $e) {
            self::markTestSkipped('Stack not reachable through Caddy: ' . $e->getMessage());
        }

        if (200 !== $status) {
            self::markTestSkipped(sprintf(
                'The catalog returned %d instead of 200 - are the containers up and the fixtures loaded? Try: make setup',
                $status,
            ));
        }
    }

    /**
     * @return array{status: int, body: string, location: string|null}
     */
    private function get(string $path, bool $followRedirects = true): array
    {
        $response = $this->http->request('GET', self::BASE . $path, [
            'max_redirects' => $followRedirects ? 3 : 0,
        ]);

        $status = $response->getStatusCode();

        return [
            'status' => $status,
            'body' => $status < 300 ? $response->getContent(false) : '',
            'location' => $response->getHeaders(false)['location'][0] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    //  Catalog pages
    // -----------------------------------------------------------------

    public function testTheCategoryOverviewListsAllFiveCategories(): void
    {
        $page = $this->get('/produkte/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('taxon-card', $page['body'], 'category cards must render');

        foreach (['Gitarren', 'Verst', 'Schlagzeug', 'Mikrofon', 'Effekt'] as $label) {
            self::assertStringContainsString($label, $page['body'], "category '$label' missing from the overview");
        }
    }

    public function testACategoryPageShowsItsProductsWithGermanPrices(): void
    {
        $page = $this->get('/produkte/guitars');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Fender Stratocaster', $page['body']);
        // German formatting: 1.299,00 € - not 1,299.00
        self::assertMatchesRegularExpression(
            '/\d{1,3}\.\d{3},\d{2}\s*€/u',
            $page['body'],
            'prices must render in German format',
        );
    }

    public function testAProductPageShowsPriceAndAnAddToCartForm(): void
    {
        $page = $this->get('/produkte/guitars/fender_stratocaster');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('product-detail__price', $page['body']);
        self::assertStringContainsString('product-detail__add-form', $page['body'], 'the add-to-cart form must be present');
        self::assertStringContainsString('_token', $page['body'], 'the form must carry a CSRF token');
    }

    public function testAnUnknownProductYieldsA404RatherThanAnError(): void
    {
        self::assertSame(404, $this->get('/produkte/guitars/does_not_exist')['status']);
    }

    // -----------------------------------------------------------------
    //  Shared layout
    // -----------------------------------------------------------------

    public function testEveryPageCarriesTheNavigationAndCartBadge(): void
    {
        foreach (['/produkte/', '/produkte/guitars', '/warenkorb/'] as $path) {
            $body = $this->get($path)['body'];

            self::assertStringContainsString('site-nav', $body, "navigation missing on $path");
            self::assertStringContainsString('site-nav__cart', $body, "cart link missing on $path");
        }
    }

    public function testPagesDeclareGermanAsTheirLanguage(): void
    {
        self::assertStringContainsString('lang="de"', $this->get('/produkte/')['body']);
    }

    // -----------------------------------------------------------------
    //  Cart and checkout guards
    // -----------------------------------------------------------------

    public function testTheCartPageIsReachable(): void
    {
        $page = $this->get('/warenkorb/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('cart', $page['body']);
    }

    public function testCheckoutStepsRedirectWhenTheirPrerequisitesAreMissing(): void
    {
        // Linear checkout guards (FIXES.md No. 27): with an empty cart,
        // the later steps must send the visitor back rather than
        // rendering a broken form.
        foreach (['/checkout/versand', '/checkout/zahlung', '/checkout/uebersicht'] as $path) {
            $page = $this->get($path, followRedirects: false);

            self::assertGreaterThanOrEqual(300, $page['status'], "$path should redirect on an empty cart");
            self::assertLessThan(400, $page['status'], "$path should redirect, not error");
        }
    }

    public function testTheAddressStepIsReachableDirectly(): void
    {
        // The first checkout step has no prerequisite beyond a cart.
        self::assertSame(200, $this->get('/checkout/adresse')['status']);
    }

    // -----------------------------------------------------------------
    //  Caddy routing (FIXES.md No. 16, 28, 33)
    // -----------------------------------------------------------------

    public function testTheDisabledSyliusStorefrontRedirectsToTheSuluCatalog(): void
    {
        $page = $this->get('/shop/', followRedirects: false);

        self::assertGreaterThanOrEqual(300, $page['status']);
        self::assertLessThan(400, $page['status']);
        self::assertStringContainsString('/produkte/', (string) $page['location']);
    }

    public function testTheOldAdminPathRedirectsToTheDedicatedPort(): void
    {
        // Sylius admin moved to its own port because Sulu and Sylius
        // both build admin assets under /build/admin/ (No. 28). The old
        // path stays as a redirect for anyone with it bookmarked.
        $page = $this->get('/shop/admin', followRedirects: false);

        self::assertGreaterThanOrEqual(300, $page['status']);
        self::assertLessThan(400, $page['status']);
        self::assertStringContainsString(':8082', (string) $page['location'], 'must point at the dedicated admin port');
    }

    public function testTheSyliusAdminAnswersOnItsOwnPortAndIsProtected(): void
    {
        // A separate client: Sylius builds its redirect URLs from the
        // Host header, so this one has to carry the admin port. With
        // plain "localhost" the login redirect points at port 80 and
        // leads nowhere.
        //
        // Redirects are deliberately NOT followed: the redirect target
        // (localhost:8082) resolves to this container from in here, not
        // to the host machine, so following it could never work. What
        // matters is checked directly instead - the admin answers, and
        // it sends an anonymous visitor to the login page.
        $client = HttpClient::create([
            'headers' => ['Host' => 'localhost:8082'],
            'timeout' => 15,
            'max_redirects' => 0,
        ]);

        $dashboard = $client->request('GET', self::ADMIN_BASE . '/admin/');
        self::assertSame(302, $dashboard->getStatusCode(), 'the admin must answer on :8082');
        self::assertStringContainsString(
            '/admin/login',
            $dashboard->getHeaders(false)['location'][0] ?? '',
            'an anonymous visitor must be sent to the login page',
        );

        // Note for anyone reading FIXES.md No. 28: it states that
        // /admin/login "does not exist as a standalone route" in this
        // Sylius version, based on a 404 seen during that
        // investigation. That conclusion was too broad - the route does
        // exist and answers 200. The 404 back then came from the
        // request context used at the time (straight at the sylius
        // container with a plain "localhost" Host header), not from the
        // route being absent.
        $login = $client->request('GET', self::ADMIN_BASE . '/admin/login');
        self::assertSame(200, $login->getStatusCode(), 'the login page itself must render');
    }

    public function testTheSuluAdminAnswers(): void
    {
        // A JavaScript SPA - a 200 is genuinely all that can be checked
        // without a browser. Still worth having: it caught nothing here,
        // but a broken Sulu install shows up as a 500.
        self::assertSame(200, $this->get('/admin/')['status']);
    }

    public function testMailpitIsReachableThroughTheRouter(): void
    {
        self::assertSame(200, $this->get('/mail/')['status']);
    }

    // -----------------------------------------------------------------
    //  A whole journey, in one go
    // -----------------------------------------------------------------

    public function testAVisitorCanGoFromTheCatalogToTheCheckoutAddressStep(): void
    {
        // Not a replacement for clicking through it by hand, but it
        // proves the pages hang together: each step's link target is
        // actually served.
        $catalog = $this->get('/produkte/');
        self::assertSame(200, $catalog['status']);
        self::assertStringContainsString('/produkte/guitars', $catalog['body'], 'the overview must link to a category');

        $category = $this->get('/produkte/guitars');
        self::assertStringContainsString('fender_stratocaster', $category['body'], 'the category must link to a product');

        $product = $this->get('/produkte/guitars/fender_stratocaster');
        self::assertStringContainsString('/warenkorb/', $product['body'], 'the product page must post to the cart');

        self::assertSame(200, $this->get('/warenkorb/')['status']);
        self::assertSame(200, $this->get('/checkout/adresse')['status']);
    }
}
