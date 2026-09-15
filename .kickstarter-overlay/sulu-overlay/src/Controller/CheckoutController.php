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
 * Checkout for headless Sylius operation - the fourth build stage from
 * the roadmap in FIXES.md No. 23. See FIXES.md No. 27 for the full,
 * step-by-step verification against the running Shop API that this
 * controller is built on.
 *
 * Every step's request shape was tested against a real order before
 * writing this code, in this order:
 *
 *   PUT   /orders/{token}                  -> checkoutState "addressed"
 *   PATCH /orders/{token}/shipments/{id}   -> checkoutState "shipping_selected"
 *   PATCH /orders/{token}/payments/{id}    -> checkoutState "payment_selected"
 *   PATCH /orders/{token}/complete         -> checkoutState "completed"
 *
 * Including confirming that selecting a payment method OTHER than the
 * auto-assigned default (Klarna instead of the default PayPal preview)
 * actually sticks - the entire point of this feature, requested
 * explicitly so every configured payment method is genuinely testable,
 * not just the one Sylius pre-selects for pricing purposes.
 *
 * Guest checkout only, consistent with the rest of this project (no
 * customer accounts/login exist here) - matches the PUBLIC_ACCESS
 * security configuration already required for Sylius' own checkout (see
 * FIXES.md No. 5).
 *
 * Country hard-coded to "DE": the catalog and cart only support the
 * "germany" channel so far (see CatalogController, CartController) - a
 * country selector would be premature until Austria/Switzerland are
 * wired up too (blocked on the Caddy hostname-per-channel limitation
 * noted in base.html.twig).
 *
 * ADYEN PAYMENT (see FIXES.md No. 32): when the selected payment method
 * is Adyen, the summary page renders its Drop-in widget instead of our
 * usual "place order" button. The widget takes over the entire payment
 * flow itself - our own completeCheckout() is never called for Adyen
 * orders, since the widget POSTs directly to Sylius' own
 * sylius_adyen_shop_payments endpoint and completes the order on
 * Sylius' side.
 */
final class CheckoutController
{
    private const CHANNEL = 'germany';
    private const CSRF_TOKEN_ID = 'checkout_action';

    // Adyen's config-URL route (sylius_adyen_shop_config) needs a locale
    // segment - tied to CHANNEL above, same coupling as everywhere else
    // in this controller (only the "germany" channel is supported so far).
    private const ADYEN_LOCALE = 'de_DE';

    public function __construct(
        private readonly CartManager $cartManager,
        private readonly SyliusShopClient $shopClient,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/checkout/adresse', name: 'checkout_address', methods: ['GET'])]
    public function addressForm(): Response
    {
        $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);

        if ([] === ($cart['items'] ?? [])) {
            return new RedirectResponse('/warenkorb/');
        }

        return new Response($this->twig->render('checkout/address.html.twig', [
            'cart' => $cart,
        ]));
    }

    #[Route('/checkout/adresse', name: 'checkout_address_submit', methods: ['POST'])]
    public function addressSubmit(Request $request): Response
    {
        if (!$this->isCsrfValid($request)) {
            return new RedirectResponse('/checkout/adresse');
        }

        $token = $this->cartManager->getToken();
        if (null === $token) {
            return new RedirectResponse('/warenkorb/');
        }

        $address = [
            'firstName' => (string) $request->request->get('firstName', ''),
            'lastName' => (string) $request->request->get('lastName', ''),
            'street' => (string) $request->request->get('street', ''),
            'city' => (string) $request->request->get('city', ''),
            'postcode' => (string) $request->request->get('postcode', ''),
            'countryCode' => 'DE',
        ];
        $email = (string) $request->request->get('email', '');

        $result = $this->shopClient->setCheckoutAddress($token, $email, $address, $address, self::CHANNEL);

        if (null === $result) {
            // Validation failure or API error - back to the form. A real
            // flash-message system would explain why; out of scope for
            // this build stage (see README.md, "Not yet built").
            return new RedirectResponse('/checkout/adresse');
        }

        return new RedirectResponse('/checkout/versand');
    }

    #[Route('/checkout/versand', name: 'checkout_shipping', methods: ['GET'])]
    public function shippingForm(): Response
    {
        $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);

        if (!$this->hasReachedState($cart, 'addressed')) {
            return new RedirectResponse('/checkout/adresse');
        }

        $shipmentId = $this->firstSubResourceId($cart, 'shipments');
        $token = $this->cartToken($cart);
        $methods = (null !== $shipmentId && null !== $token)
            ? $this->shopClient->fetchShippingMethods($token, $shipmentId, self::CHANNEL)
            : [];

        return new Response($this->twig->render('checkout/shipping.html.twig', [
            'cart' => $cart,
            'methods' => $methods,
            'selectedCode' => $this->currentMethodCode($this->firstSubResourceMethodIri($cart, 'shipments')),
        ]));
    }

    #[Route('/checkout/versand', name: 'checkout_shipping_submit', methods: ['POST'])]
    public function shippingSubmit(Request $request): Response
    {
        if (!$this->isCsrfValid($request)) {
            return new RedirectResponse('/checkout/versand');
        }

        $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);
        $shipmentId = $this->firstSubResourceId($cart, 'shipments');
        $token = $this->cartToken($cart);
        $methodCode = (string) $request->request->get('methodCode', '');

        if (null !== $shipmentId && null !== $token && '' !== $methodCode) {
            $this->shopClient->selectShippingMethod($token, $shipmentId, $methodCode, self::CHANNEL);
        }

        return new RedirectResponse('/checkout/zahlung');
    }

    #[Route('/checkout/zahlung', name: 'checkout_payment', methods: ['GET'])]
    public function paymentForm(): Response
    {
        $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);

        if (!$this->hasReachedState($cart, 'shipping_selected')) {
            return new RedirectResponse('/checkout/versand');
        }

        $paymentId = $this->firstSubResourceId($cart, 'payments');
        $token = $this->cartToken($cart);
        $methods = (null !== $paymentId && null !== $token)
            ? $this->shopClient->fetchPaymentMethods($token, $paymentId, self::CHANNEL)
            : [];

        return new Response($this->twig->render('checkout/payment.html.twig', [
            'cart' => $cart,
            'methods' => $methods,
            'selectedCode' => $this->currentMethodCode($this->firstSubResourceMethodIri($cart, 'payments')),
        ]));
    }

    #[Route('/checkout/zahlung', name: 'checkout_payment_submit', methods: ['POST'])]
    public function paymentSubmit(Request $request): Response
    {
        if (!$this->isCsrfValid($request)) {
            return new RedirectResponse('/checkout/zahlung');
        }

        $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);
        $paymentId = $this->firstSubResourceId($cart, 'payments');
        $token = $this->cartToken($cart);
        $methodCode = (string) $request->request->get('methodCode', '');

        if (null !== $paymentId && null !== $token && '' !== $methodCode) {
            $this->shopClient->selectPaymentMethod($token, $paymentId, $methodCode, self::CHANNEL);
        }

        return new RedirectResponse('/checkout/uebersicht');
    }

    #[Route('/checkout/uebersicht', name: 'checkout_summary', methods: ['GET'])]
    public function summary(): Response
    {
        $cart = $this->cartManager->getOrCreateCart(self::CHANNEL);

        if (!$this->hasReachedState($cart, 'payment_selected')) {
            return new RedirectResponse('/checkout/zahlung');
        }

        $token = $this->cartToken($cart);
        $shipmentId = $this->firstSubResourceId($cart, 'shipments');
        $paymentId = $this->firstSubResourceId($cart, 'payments');
        $shippingMethodIri = $this->firstSubResourceMethodIri($cart, 'shipments');
        $paymentMethodIri = $this->firstSubResourceMethodIri($cart, 'payments');

        $isAdyen = 'adyen' === $this->currentMethodCode($paymentMethodIri);

        return new Response($this->twig->render('checkout/summary.html.twig', [
            'cart' => $cart,
            'shippingMethodName' => $this->resolveMethodName(
                $shippingMethodIri,
                (null !== $token && null !== $shipmentId)
                    ? $this->shopClient->fetchShippingMethods($token, $shipmentId, self::CHANNEL)
                    : [],
            ),
            'paymentMethodName' => $this->resolveMethodName(
                $paymentMethodIri,
                (null !== $token && null !== $paymentId)
                    ? $this->shopClient->fetchPaymentMethods($token, $paymentId, self::CHANNEL)
                    : [],
            ),
            // Adyen's own Drop-in widget replaces our usual "place order"
            // button entirely - see FIXES.md No. 32. The widget submits
            // and completes the order itself via Sylius' own payments
            // endpoint, never through our completeCheckout() call.
            'isAdyen' => $isAdyen,
            'adyenConfigUrl' => ($isAdyen && null !== $token)
                ? '/' . self::ADYEN_LOCALE . '/payment/adyen/adyen/' . $token
                : null,
        ]));
    }

    #[Route('/checkout/abschliessen', name: 'checkout_complete', methods: ['POST'])]
    public function complete(Request $request): Response
    {
        if (!$this->isCsrfValid($request)) {
            return new RedirectResponse('/checkout/uebersicht');
        }

        $token = $this->cartManager->getToken();
        if (null === $token) {
            return new RedirectResponse('/warenkorb/');
        }

        $result = $this->shopClient->completeCheckout($token, self::CHANNEL);

        if (null === $result) {
            return new RedirectResponse('/checkout/uebersicht');
        }

        // Detach the token now that this order is completed - the next
        // visit gets a fresh cart instead of continuing on a finished order.
        $this->cartManager->clear();

        $numberRaw = $result['number'] ?? null;
        $number = (is_string($numberRaw) || is_int($numberRaw)) ? (string) $numberRaw : '';

        return new RedirectResponse('/checkout/bestaetigung/' . rawurlencode($number));
    }

    #[Route('/checkout/bestaetigung/{number}', name: 'checkout_confirmation', methods: ['GET'])]
    public function confirmation(string $number): Response
    {
        return new Response($this->twig->render('checkout/confirmation.html.twig', [
            'orderNumber' => $number,
        ]));
    }

    /**
     * Every value read out of a cart array is `mixed` as far as static
     * analysis is concerned (the Shop API could return anything), and
     * this controller reads the same three shapes over and over. These
     * helpers narrow each one once, safely, instead of repeating
     * is_array()/is_int() checks at every call site (see FIXES.md
     * No. 41).
     *
     * @param array<string, mixed> $cart
     */
    private function cartToken(array $cart): ?string
    {
        $token = $cart['tokenValue'] ?? null;

        return is_string($token) && '' !== $token ? $token : null;
    }

    /**
     * Reads e.g. $cart['shipments'][0]['id'] safely.
     *
     * @param array<string, mixed> $cart
     */
    private function firstSubResourceId(array $cart, string $key): ?int
    {
        $list = $cart[$key] ?? null;
        if (!is_array($list) || !isset($list[0]) || !is_array($list[0])) {
            return null;
        }

        $id = $list[0]['id'] ?? null;

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    /**
     * Reads e.g. $cart['payments'][0]['method'] safely - an IRI string
     * like "/api/v2/shop/payment-methods/adyen".
     *
     * @param array<string, mixed> $cart
     */
    private function firstSubResourceMethodIri(array $cart, string $key): ?string
    {
        $list = $cart[$key] ?? null;
        if (!is_array($list) || !isset($list[0]) || !is_array($list[0])) {
            return null;
        }

        $method = $list[0]['method'] ?? null;

        return is_string($method) ? $method : null;
    }

    /**
     * @param array<string, mixed> $cart
     */
    private function hasReachedState(array $cart, string $minimumState): bool
    {
        // The checkout state machine is linear (see CLAUDE.md section 5):
        // cart -> addressed -> shipping_selected -> payment_selected -> completed.
        $order = ['cart', 'addressed', 'shipping_selected', 'payment_selected', 'completed'];
        $current = array_search($cart['checkoutState'] ?? 'cart', $order, true);
        $minimum = array_search($minimumState, $order, true);

        return false !== $current && false !== $minimum && $current >= $minimum;
    }

    private function currentMethodCode(?string $methodIri): ?string
    {
        return null !== $methodIri ? basename($methodIri) : null;
    }

    /**
     * @param array<int, array{code: string, name: string}> $methods
     */
    private function resolveMethodName(?string $methodIri, array $methods): ?string
    {
        $code = $this->currentMethodCode($methodIri);
        if (null === $code) {
            return null;
        }

        foreach ($methods as $method) {
            if ($method['code'] === $code) {
                return $method['name'];
            }
        }

        return $code; // fallback: better a code shown than nothing
    }

    private function isCsrfValid(Request $request): bool
    {
        $submitted = (string) $request->request->get('_token', '');

        return $this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted));
    }
}
