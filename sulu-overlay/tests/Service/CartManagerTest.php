<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CartManager;
use App\Service\SyliusShopClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for the cart-token-in-Sulu's-session logic - the core of
 * the headless architecture decision (FIXES.md No. 23, option A).
 *
 * TWO DELIBERATE CHOICES ABOUT TEST DOUBLES:
 *
 * 1. SyliusShopClient is NOT mocked - it is a `final` class, which
 *    PHPUnit cannot mock. Instead a real client is constructed on top
 *    of Symfony's MockHttpClient, simulating the API one layer lower,
 *    at the HTTP boundary. Arguably the better test anyway: it
 *    exercises the real request/response handling rather than a
 *    stubbed-out version of it.
 *
 * 2. The Session is real (MockArraySessionStorage), because reading and
 *    writing the session is precisely the behaviour under test - faking
 *    it would test nothing.
 *
 * Response order matters: getOrCreateCart() issues a GET (fetchCart)
 * only when a token is already in the session, then a POST (createCart)
 * if that cart turns out to be unusable. The sequences below mirror that.
 */
final class CartManagerTest extends TestCase
{
    private const SESSION_KEY = 'sylius_cart_token';

    private Session $session;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);

        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(
            json_encode($payload, \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/ld+json']],
        );
    }

    /**
     * @param iterable<ResponseInterface>|callable $responses
     */
    private function manager(iterable|callable $responses): CartManager
    {
        $client = new SyliusShopClient(new MockHttpClient($responses), new NullLogger());

        return new CartManager($client, $this->requestStack);
    }

    // -----------------------------------------------------------------
    //  getOrCreateCart
    // -----------------------------------------------------------------

    public function testCreatesANewCartWhenTheSessionHasNoToken(): void
    {
        // No token -> straight to POST /orders, no GET first.
        $manager = $this->manager([$this->json(['tokenValue' => 'NEW_TOKEN', 'state' => 'cart'])]);

        $cart = $manager->getOrCreateCart();

        self::assertSame('NEW_TOKEN', $cart['tokenValue']);
        self::assertSame('NEW_TOKEN', $this->session->get(self::SESSION_KEY), 'the new token must be persisted');
    }

    public function testReusesAnExistingCartWhenTheStoredTokenIsStillValid(): void
    {
        $this->session->set(self::SESSION_KEY, 'EXISTING');
        $manager = $this->manager([$this->json(['tokenValue' => 'EXISTING', 'state' => 'cart'])]);

        self::assertSame('EXISTING', $manager->getOrCreateCart()['tokenValue']);
    }

    public function testStartsAFreshCartWhenTheStoredOrderHasMovedPastCartState(): void
    {
        // After a completed checkout the order still resolves, so
        // fetchCart succeeds - but adding items to it would fail.
        // Anything other than state "cart" has to start over
        // (FIXES.md No. 27).
        $this->session->set(self::SESSION_KEY, 'COMPLETED');
        $manager = $this->manager([
            $this->json(['tokenValue' => 'COMPLETED', 'state' => 'fulfilled']),
            $this->json(['tokenValue' => 'FRESH', 'state' => 'cart']),
        ]);

        $cart = $manager->getOrCreateCart();

        self::assertSame('FRESH', $cart['tokenValue']);
        self::assertSame('FRESH', $this->session->get(self::SESSION_KEY));
    }

    public function testStartsAFreshCartWhenTheStoredTokenNoLongerResolves(): void
    {
        // 404 from Sylius -> fetchCart returns null -> create a new one.
        $this->session->set(self::SESSION_KEY, 'EXPIRED');
        $manager = $this->manager([
            new MockResponse('', ['http_code' => 404]),
            $this->json(['tokenValue' => 'FRESH', 'state' => 'cart']),
        ]);

        self::assertSame('FRESH', $manager->getOrCreateCart()['tokenValue']);
    }

    public function testReturnsAnEmptyCartWhenSyliusCannotCreateOne(): void
    {
        // Sylius unreachable: the page must still render (FIXES.md No. 23).
        $manager = $this->manager(static function (): ResponseInterface {
            throw new \RuntimeException('connection refused');
        });

        $cart = $manager->getOrCreateCart();

        self::assertNull($cart['tokenValue']);
        self::assertSame([], $cart['items']);
        self::assertSame(0, $cart['total']);
    }

    // -----------------------------------------------------------------
    //  getToken / clear
    // -----------------------------------------------------------------

    public function testGetTokenReturnsNullWithoutIssuingAnyRequest(): void
    {
        self::assertNull($this->manager([])->getToken());
    }

    public function testAnEmptyStoredTokenCountsAsNoToken(): void
    {
        $this->session->set(self::SESSION_KEY, '');

        self::assertNull($this->manager([])->getToken());
    }

    public function testClearRemovesTheTokenFromTheSession(): void
    {
        $this->session->set(self::SESSION_KEY, 'SOMETHING');

        $this->manager([])->clear();

        self::assertNull($this->session->get(self::SESSION_KEY));
    }

    // -----------------------------------------------------------------
    //  getSummary - the defensive reads from FIXES.md No. 41
    // -----------------------------------------------------------------

    public function testSummaryCountsItemQuantities(): void
    {
        $this->session->set(self::SESSION_KEY, 'T');
        $manager = $this->manager([
            $this->json(['items' => [['quantity' => 2], ['quantity' => 3]], 'total' => 45900]),
        ]);

        self::assertSame(['count' => 5, 'total' => 45900], $manager->getSummary());
    }

    public function testSummaryIsZeroWithoutATokenAndIssuesNoRequest(): void
    {
        self::assertSame(['count' => 0, 'total' => 0], $this->manager([])->getSummary());
    }

    public function testSummarySurvivesAnUnexpectedItemsShape(): void
    {
        // "items" arriving as something other than a list of arrays used
        // to be a blind foreach; now it degrades to zero instead of
        // erroring (FIXES.md No. 41).
        $this->session->set(self::SESSION_KEY, 'T');
        $manager = $this->manager([$this->json(['items' => 'not a list', 'total' => 100])]);

        self::assertSame(['count' => 0, 'total' => 100], $manager->getSummary());
    }

    public function testSummaryIgnoresItemsWithoutAUsableQuantity(): void
    {
        $this->session->set(self::SESSION_KEY, 'T');
        $manager = $this->manager([
            $this->json([
                'items' => [['quantity' => 2], ['quantity' => 'many'], ['no_quantity' => true], 'a string'],
                'total' => 0,
            ]),
        ]);

        self::assertSame(2, $manager->getSummary()['count']);
    }

    public function testSummaryFallsBackToZeroForANonNumericTotal(): void
    {
        $this->session->set(self::SESSION_KEY, 'T');
        $manager = $this->manager([$this->json(['items' => [], 'total' => ['unexpected']])]);

        self::assertSame(0, $manager->getSummary()['total']);
    }
}
