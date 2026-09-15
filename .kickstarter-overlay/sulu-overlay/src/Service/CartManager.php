<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Owns the Sylius cart token inside Sulu's own session - the core piece
 * of the headless architecture decision (option A, see FIXES.md No. 23):
 * the browser never sees a Sylius URL or a Sylius cart token directly,
 * only Sulu's session cookie.
 *
 * Deliberately a separate service from SyliusShopClient: the client only
 * knows the Sylius API, nothing about Symfony sessions. This class is the
 * only place that reads/writes the session, so the controller and the
 * Twig cart-badge function (ShopExtension) share one source of truth
 * instead of duplicating the "get or create a token" logic.
 */
final class CartManager
{
    private const SESSION_KEY = 'sylius_cart_token';

    public function __construct(
        private readonly SyliusShopClient $shopClient,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Returns the current cart, creating a new one if there's no token in
     * the session yet or the stored token is no longer valid (expired,
     * or the order was completed through another path).
     *
     * @return array<string, mixed>
     */
    public function getOrCreateCart(string $channelCode = 'germany'): array
    {
        $session = $this->requestStack->getSession();
        $token = $session->get(self::SESSION_KEY);

        if (is_string($token) && '' !== $token) {
            $cart = $this->shopClient->fetchCart($token, $channelCode);
            if (null !== $cart && 'cart' === ($cart['state'] ?? null)) {
                return $cart;
            }
            // Token invalid, expired, or the order moved past "cart" state
            // (e.g. completed) - fall through and start a fresh cart.
        }

        $cart = $this->shopClient->createCart($channelCode);
        if (null === $cart) {
            return $this->emptyCartFallback();
        }

        $session->set(self::SESSION_KEY, $cart['tokenValue']);

        return $cart;
    }

    /**
     * Reads the token without creating a new cart - used where a missing
     * cart simply means "nothing to do" (e.g. removing an item).
     */
    public function getToken(): ?string
    {
        $token = $this->requestStack->getSession()->get(self::SESSION_KEY);

        return is_string($token) && '' !== $token ? $token : null;
    }

    /**
     * Detaches the cart token from the session - called after a completed
     * checkout, so the next visit starts a fresh cart instead of trying
     * to keep adding items to an order that's already "completed" on the
     * Sylius side.
     */
    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * A small summary for the navigation badge - deliberately not the
     * full cart, to keep this cheap enough to call on every page.
     *
     * @return array{count: int, total: int}
     */
    public function getSummary(string $channelCode = 'germany'): array
    {
        $token = $this->getToken();
        if (null === $token) {
            return ['count' => 0, 'total' => 0];
        }

        $cart = $this->shopClient->fetchCart($token, $channelCode);
        if (null === $cart) {
            return ['count' => 0, 'total' => 0];
        }

        // Defensive reads rather than blind casts: fetchCart() returns
        // whatever the Sylius API sent, typed only as array<string, mixed>,
        // so nothing here can be assumed to have the expected shape (see
        // FIXES.md No. 41).
        $count = 0;
        $items = $cart['items'] ?? [];
        if (is_iterable($items)) {
            foreach ($items as $item) {
                if (is_array($item) && isset($item['quantity']) && is_numeric($item['quantity'])) {
                    $count += (int) $item['quantity'];
                }
            }
        }

        $total = $cart['total'] ?? 0;

        return ['count' => $count, 'total' => is_numeric($total) ? (int) $total : 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCartFallback(): array
    {
        return [
            'tokenValue' => null,
            'items' => [],
            'itemsTotal' => 0,
            'total' => 0,
        ];
    }
}
