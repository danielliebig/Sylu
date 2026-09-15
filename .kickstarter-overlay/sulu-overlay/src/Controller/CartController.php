<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CartManager;
use App\Service\SyliusShopClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Cart routes for headless Sylius operation (see FIXES.md No. 23 and 26).
 *
 * Same reasoning as CatalogController: an ordinary Symfony controller
 * with its own routes, not a Sulu page - a shopping cart is stateful,
 * per-visitor data, not editorial content.
 *
 * Every state-changing action follows POST-redirect-GET: the browser
 * never sees the Sylius cart token directly (it lives only in Sulu's own
 * session via CartManager), and reloading the cart page after a POST
 * never re-submits the form.
 */
final class CartController
{
    private const CHANNEL = 'germany';
    private const CSRF_TOKEN_ID = 'cart_action';

    public function __construct(
        private readonly CartManager $cartManager,
        private readonly SyliusShopClient $shopClient,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/warenkorb/', name: 'cart_index', methods: ['GET'])]
    public function index(): Response
    {
        $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);

        return new Response($this->twig->render('cart/index.html.twig', [
            'cart' => $cart,
        ]));
    }

    #[Route('/warenkorb/hinzufuegen', name: 'cart_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        if (!$this->isCsrfValid($request)) {
            return new RedirectResponse('/warenkorb/');
        }

        $productCode = (string) $request->request->get('productCode', '');
        $quantity = max(1, (int) $request->request->get('quantity', 1));

        if ('' !== $productCode) {
            $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);
            $token = $cart['tokenValue'] ?? null;

            if (is_string($token)) {
                // Variant code convention from our own fixture (product
                // code + "_variant") - see RockbandProductsFixture.php,
                // not a guess about Sylius' own behavior.
                $this->shopClient->addCartItem($token, $productCode . '_variant', $quantity, self::CHANNEL);
            }
        }

        return new RedirectResponse('/warenkorb/');
    }

    #[Route('/warenkorb/{itemId}/menge', name: 'cart_update', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function update(int $itemId, Request $request): Response
    {
        if (!$this->isCsrfValid($request)) {
            return new RedirectResponse('/warenkorb/');
        }

        $token = $this->cartManager->getToken();
        $quantity = max(1, (int) $request->request->get('quantity', 1));

        if (null !== $token) {
            $this->shopClient->updateCartItemQuantity($token, $itemId, $quantity, self::CHANNEL);
        }

        return new RedirectResponse('/warenkorb/');
    }

    #[Route('/warenkorb/{itemId}/entfernen', name: 'cart_remove', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function remove(int $itemId, Request $request): Response
    {
        if (!$this->isCsrfValid($request)) {
            return new RedirectResponse('/warenkorb/');
        }

        $token = $this->cartManager->getToken();

        if (null !== $token) {
            $this->shopClient->removeCartItem($token, $itemId, self::CHANNEL);
        }

        return new RedirectResponse('/warenkorb/');
    }

    private function isCsrfValid(Request $request): bool
    {
        $submitted = (string) $request->request->get('_token', '');

        return $this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted));
    }
}
