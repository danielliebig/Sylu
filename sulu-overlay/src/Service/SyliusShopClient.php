<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Complete access to Sylius' Shop API for the headless Sulu catalog:
 * homepage preview, categories, product lists, single product.
 *
 * WHY THE HOST-HEADER TRICK:
 * Sylius determines its channel from the request's hostname
 * (Sylius\Component\Channel\Context\RequestBased\HostnameBasedRequestResolver -
 * see FIXES.md No. 15). Inside the Docker network, the Sylius container
 * is simply called "sylius" - that name isn't registered as a hostname on
 * any channel (only "localhost", "austria.localhost",
 * "switzerland.localhost" are). A request to http://sylius/... with no
 * further work would therefore find NO channel at all. The trick:
 * separate the TCP connection target (sylius) from the HTTP Host header -
 * connect to "sylius", but send "localhost" (or similar) in the Host
 * header, exactly like real virtual hosts do.
 *
 * EVERY FIELD AND FILTER BELOW IS VERIFIED AGAINST REAL RESPONSES FROM
 * THE RUNNING SHOP API (see FIXES.md No. 23), NOT COPIED FROM
 * DOCUMENTATION OF UNKNOWN VERSION VINTAGE:
 *
 *   - The taxon filter for product lists is called
 *     "productTaxons.taxon.code", NOT "taxon" (the latter is accepted by
 *     the API but ignored, returning 0 results - confirmed via a real
 *     test call).
 *   - Products are only retrievable through their "code"
 *     (/api/v2/shop/products/{code}), NOT through "slug" - a direct call
 *     with the slug returns 404.
 *   - Price and stock data are already embedded in "defaultVariantData" -
 *     both for a single product and in list responses. No second request
 *     per product needed.
 *   - Image paths in "images[].path" are already complete, directly
 *     usable URLs.
 *
 * Every error results in an empty response, never an exception escaping
 * outward - an empty list or "product not found" is always better for a
 * demo page than a 500.
 */
final class SyliusShopClient
{
    /** Channel code => hostname, as configured in dach_demo.yaml on the Sylius side. */
    private const CHANNEL_HOSTNAMES = [
        'germany' => 'localhost',
        'austria' => 'austria.localhost',
        'switzerland' => 'switzerland.localhost',
    ];

    private const CHANNEL_LOCALES = [
        'germany' => 'de_DE',
        'austria' => 'de_AT',
        'switzerland' => 'de_CH',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        // The Sylius container is simply called "sylius" on the Docker
        // network - see docker-compose.yaml, the service name is the DNS
        // name on the "kickstarter" network.
        private readonly string $syliusInternalBaseUrl = 'http://sylius',
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchFeaturedProducts(string $channelCode = 'germany', int $limit = 6): array
    {
        $data = $this->request($channelCode, '/api/v2/shop/products', [
            'itemsPerPage' => max(1, min($limit, 20)),
            'order[createdAt]' => 'desc',
        ]);

        $items = $this->extractCollection($data);
        $products = [];
        foreach ($items as $item) {
            $mapped = $this->mapProduct($item);
            if (null !== $mapped) {
                $products[] = $mapped;
            }
            if (count($products) >= $limit) {
                break;
            }
        }

        return $products;
    }

    /**
     * All categories (taxons) for the catalog overview page.
     *
     * @return array<int, array{code: string, name: string, slug: ?string, description: ?string}>
     */
    public function fetchTaxons(string $channelCode = 'germany'): array
    {
        $data = $this->request($channelCode, '/api/v2/shop/taxons', ['itemsPerPage' => 50]);

        $items = $this->extractCollection($data);
        $taxons = [];
        foreach ($items as $item) {
            if (!isset($item['code'], $item['name'])) {
                continue;
            }
            $taxons[] = [
                'code' => $this->strOrEmpty($item['code']),
                'name' => $this->strOrEmpty($item['name']),
                'slug' => isset($item['slug']) ? $this->str($item['slug']) : null,
                'description' => isset($item['description']) ? $this->str($item['description']) : null,
            ];
        }

        return $taxons;
    }

    /**
     * Products within a category, for the catalog listing page. Already
     * contains price/stock status (defaultVariantData) - no extra
     * request per product needed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductsByTaxon(string $taxonCode, string $channelCode = 'germany', int $limit = 24): array
    {
        $data = $this->request($channelCode, '/api/v2/shop/products', [
            // Verified: "taxon" alone is ignored, see class docblock.
            'productTaxons.taxon.code' => $taxonCode,
            'itemsPerPage' => max(1, min($limit, 50)),
        ]);

        $items = $this->extractCollection($data);
        $products = [];
        foreach ($items as $item) {
            $mapped = $this->mapProduct($item);
            if (null !== $mapped) {
                $products[] = $mapped;
            }
        }

        return $products;
    }

    /**
     * A single product for the product detail page.
     *
     * @return array<string, mixed>|null null if not found or the API is
     *         unreachable - the controller then decides on a 404.
     */
    public function fetchProduct(string $code, string $channelCode = 'germany'): ?array
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->syliusInternalBaseUrl . '/api/v2/shop/products/' . rawurlencode($code),
                [
                    'headers' => [
                        'Host' => $hostname,
                        'Accept' => 'application/ld+json',
                        'Accept-Language' => $locale,
                    ],
                    'timeout' => 3.0,
                ],
            );

            if (404 === $response->getStatusCode()) {
                return null;
            }

            $data = $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: product could not be loaded.', [
                'code' => $code,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->mapProduct($data, withDescription: true);
    }

    /**
     * Symfony's toArray() is typed as plain `array`, which isn't precise
     * enough for the `array<string, mixed>` return types declared
     * throughout this class. This narrows it once, in one place - a
     * Shop API response is always a JSON object at the top level, so
     * anything else (a bare list, a scalar) is treated as "no usable
     * data" rather than passed on (see FIXES.md No. 41).
     *
     * @return array<string, mixed>
     */
    private function toAssoc(ResponseInterface $response): array
    {
        /** @var array<array-key, mixed> $decoded */
        $decoded = $response->toArray(false);

        $assoc = [];
        foreach ($decoded as $key => $value) {
            $assoc[(string) $key] = $value;
        }

        return $assoc;
    }

    /**
     * Narrows an arbitrary API value to a string, or null when it isn't
     * one. Every value read out of a Shop API response is `mixed` as far
     * as static analysis is concerned - the API could return anything -
     * so these two helpers exist to convert once, safely, instead of
     * scattering `is_scalar()` checks across every read site (see
     * FIXES.md No. 41).
     */
    private function str(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : null;
    }

    /**
     * Same idea for values that must be a string: falls back to an empty
     * string rather than null, for the fields we treat as mandatory
     * (already guarded by an isset() check at the call site).
     */
    private function strOrEmpty(mixed $value): string
    {
        return $this->str($value) ?? '';
    }

    /**
     * Shared GET call with Host-header channel selection. Returns an
     * empty array on any error (never an exception).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(string $channelCode, string $path, array $query): array
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request('GET', $this->syliusInternalBaseUrl . $path, [
                'headers' => [
                    'Host' => $hostname,
                    'Accept' => 'application/ld+json',
                    'Accept-Language' => $locale,
                ],
                'query' => $query,
                'timeout' => 3.0,
            ]);

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API unreachable.', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * API Platform serves collections under "hydra:member" (JSON-LD) or
     * "member" (version-dependent) - check both instead of guessing.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extractCollection(array $data): array
    {
        $items = $data['hydra:member'] ?? $data['member'] ?? null;
        if (!is_array($items)) {
            if ([] !== $data) {
                $this->logger->warning('Unexpected collection format from the Sylius Shop API.', [
                    'keys' => array_keys($data),
                ]);
            }

            return [];
        }

        // Filter to actual associative arrays rather than just asserting
        // the shape in the docblock: the API could return a list of
        // scalars, and every caller immediately does $item['code'] on
        // these (see FIXES.md No. 41).
        $collection = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = [];
            foreach ($item as $key => $value) {
                $entry[(string) $key] = $value;
            }
            $collection[] = $entry;
        }

        return $collection;
    }

    /**
     * Turns a raw product resource into the fields our templates need.
     * Price comes from "defaultVariantData.price" (cents, verified) - if
     * that path is missing, the entry isn't a usable product and gets
     * skipped rather than shown with a wrong price.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function mapProduct(array $item, bool $withDescription = false): ?array
    {
        if (!isset($item['code'], $item['name'])) {
            return null;
        }

        $variant = is_array($item['defaultVariantData'] ?? null) ? $item['defaultVariantData'] : null;
        $priceCents = is_int($variant['price'] ?? null) ? $variant['price'] : null;
        $originalPriceCents = is_int($variant['originalPrice'] ?? null) ? $variant['originalPrice'] : null;
        $inStock = is_bool($variant['inStock'] ?? null) ? $variant['inStock'] : null;

        $images = is_array($item['images'] ?? null) ? $item['images'] : [];
        $imagePath = null;
        foreach ($images as $image) {
            if (is_array($image) && isset($image['path'])) {
                $imagePath = $this->str($image['path']);
                break;
            }
        }

        $mainTaxonIri = isset($item['mainTaxon']) ? $this->str($item['mainTaxon']) : null;
        // "/api/v2/shop/taxons/guitars" -> "guitars"
        $mainTaxonCode = null !== $mainTaxonIri ? basename($mainTaxonIri) : null;

        $product = [
            'code' => $this->strOrEmpty($item['code']),
            'name' => $this->strOrEmpty($item['name']),
            'slug' => isset($item['slug']) ? $this->str($item['slug']) : null,
            'shortDescription' => isset($item['shortDescription']) ? $this->str($item['shortDescription']) : null,
            'priceCents' => $priceCents,
            'originalPriceCents' => ($originalPriceCents !== $priceCents) ? $originalPriceCents : null,
            'inStock' => $inStock,
            'imagePath' => $imagePath,
            'mainTaxonCode' => $mainTaxonCode,
        ];

        if ($withDescription) {
            $product['description'] = isset($item['description']) ? $this->str($item['description']) : null;
        }

        return $product;
    }

    // =======================================================================
    //  CART - verified against a running instance (see FIXES.md No. 26):
    //
    //    POST   /api/v2/shop/orders                     create a cart
    //           Content-Type: application/ld+json, body {}
    //           -> returns the full order incl. tokenValue
    //    POST   /api/v2/shop/orders/{token}/items        add an item
    //           Content-Type: application/ld+json
    //           body {"productVariant": "/api/v2/shop/product-variants/{code}", "quantity": N}
    //    PATCH  /api/v2/shop/orders/{token}/items/{id}    change quantity
    //           Content-Type: application/merge-patch+json (NOT ld+json -
    //           API Platform expects a different content type for PATCH
    //           than for POST, confirmed by testing both)
    //           body {"quantity": N}
    //    DELETE /api/v2/shop/orders/{token}/items/{id}    remove an item
    //           -> 204 No Content, no body
    //    GET    /api/v2/shop/orders/{token}               fetch the cart
    //
    //  Sylius assigns a default shipping/payment method to the cart
    //  automatically as soon as it holds an item (confirmed: "paypal" /
    //  "dhl_standard_de" appeared in the response after the first item was
    //  added) - that's a pricing preview only (correct tax/shipping totals
    //  shown in the cart), not a real selection. The actual choice happens
    //  in checkout via the state machine, same as CreateTestOrdersCommand.php.
    // =======================================================================

    /**
     * @return array<string, mixed>|null null if the API is unreachable.
     */
    public function createCart(string $channelCode = 'germany'): ?array
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request('POST', $this->syliusInternalBaseUrl . '/api/v2/shop/orders', [
                'headers' => [
                    'Host' => $hostname,
                    'Content-Type' => 'application/ld+json',
                    'Accept' => 'application/ld+json',
                    'Accept-Language' => $locale,
                ],
                'body' => '{}',
                'timeout' => 3.0,
            ]);

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: could not create a cart.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null null if not found or unreachable.
     */
    public function fetchCart(string $token, string $channelCode = 'germany'): ?array
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->syliusInternalBaseUrl . '/api/v2/shop/orders/' . rawurlencode($token),
                [
                    'headers' => [
                        'Host' => $hostname,
                        'Accept' => 'application/ld+json',
                        'Accept-Language' => $locale,
                    ],
                    'timeout' => 3.0,
                ],
            );

            if (404 === $response->getStatusCode()) {
                return null;
            }

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: could not fetch the cart.', [
                'token' => $token,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null null on failure (e.g. an invalid
     *         variant code, an expired cart token).
     */
    public function addCartItem(string $token, string $variantCode, int $quantity, string $channelCode = 'germany'): ?array
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->syliusInternalBaseUrl . '/api/v2/shop/orders/' . rawurlencode($token) . '/items',
                [
                    'headers' => [
                        'Host' => $hostname,
                        'Content-Type' => 'application/ld+json',
                        'Accept' => 'application/ld+json',
                        'Accept-Language' => $locale,
                    ],
                    'json' => [
                        'productVariant' => '/api/v2/shop/product-variants/' . $variantCode,
                        'quantity' => max(1, $quantity),
                    ],
                    'timeout' => 3.0,
                ],
            );

            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Sylius Shop API: adding a cart item was rejected.', [
                    'variant' => $variantCode,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: could not add a cart item.', [
                'variant' => $variantCode,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null null on failure.
     */
    public function updateCartItemQuantity(string $token, int $itemId, int $quantity, string $channelCode = 'germany'): ?array
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request(
                'PATCH',
                $this->syliusInternalBaseUrl . '/api/v2/shop/orders/' . rawurlencode($token) . '/items/' . $itemId,
                [
                    'headers' => [
                        'Host' => $hostname,
                        // Deliberately merge-patch+json, NOT ld+json - see
                        // class docblock, confirmed by testing both.
                        'Content-Type' => 'application/merge-patch+json',
                        'Accept' => 'application/ld+json',
                        'Accept-Language' => $locale,
                    ],
                    'json' => ['quantity' => max(1, $quantity)],
                    'timeout' => 3.0,
                ],
            );

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: could not update cart item quantity.', [
                'itemId' => $itemId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function removeCartItem(string $token, int $itemId, string $channelCode = 'germany'): bool
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];

        try {
            $response = $this->httpClient->request(
                'DELETE',
                $this->syliusInternalBaseUrl . '/api/v2/shop/orders/' . rawurlencode($token) . '/items/' . $itemId,
                [
                    'headers' => [
                        'Host' => $hostname,
                        'Accept' => 'application/ld+json',
                    ],
                    'timeout' => 3.0,
                ],
            );

            // Verified: a successful delete returns 204 No Content.
            return $response->getStatusCode() < 300;
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: could not remove cart item.', [
                'itemId' => $itemId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // =======================================================================
    //  CHECKOUT - verified against a running instance (see FIXES.md No. 27):
    //
    //    PUT    /api/v2/shop/orders/{token}                    set address
    //           Content-Type: application/ld+json
    //           body {"email": "...", "billingAddress": {...}, "shippingAddress": {...}}
    //           -> checkoutState becomes "addressed". Confirmed this is a
    //           partial update, not a full-object replace: items and
    //           everything else on the order stay untouched even though
    //           they weren't in the request body.
    //    PATCH  /api/v2/shop/orders/{token}/shipments/{id}     select shipping
    //           Content-Type: application/merge-patch+json
    //           body {"shippingMethod": "/api/v2/shop/shipping-methods/{code}"}
    //           -> checkoutState becomes "shipping_selected"
    //    PATCH  /api/v2/shop/orders/{token}/payments/{id}      select payment
    //           Content-Type: application/merge-patch+json
    //           body {"paymentMethod": "/api/v2/shop/payment-methods/{code}"}
    //           -> checkoutState becomes "payment_selected". Confirmed
    //           with a method OTHER than the auto-assigned default
    //           (klarna_invoice instead of paypal) - the explicit choice
    //           is what sticks, not the pricing-preview default.
    //    PATCH  /api/v2/shop/orders/{token}/complete           complete
    //           Content-Type: application/merge-patch+json
    //           body {"notes": ""}
    //           -> checkoutState "completed", paymentState
    //           "awaiting_payment" (not yet confirmed as paid - correct:
    //           a real payment hasn't happened, same end state as
    //           CreateTestOrdersCommand.php before its manual completion
    //           step), shippingState "ready", order number assigned.
    // =======================================================================

    /**
     * @param array<string, string> $billingAddress firstName, lastName,
     *        street, city, postcode, countryCode
     * @param array<string, string> $shippingAddress same shape
     * @return array<string, mixed>|null null on failure.
     */
    public function setCheckoutAddress(
        string $token,
        string $email,
        array $billingAddress,
        array $shippingAddress,
        string $channelCode = 'germany',
    ): ?array {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request(
                'PUT',
                $this->syliusInternalBaseUrl . '/api/v2/shop/orders/' . rawurlencode($token),
                [
                    'headers' => [
                        'Host' => $hostname,
                        'Content-Type' => 'application/ld+json',
                        'Accept' => 'application/ld+json',
                        'Accept-Language' => $locale,
                    ],
                    'json' => [
                        'email' => $email,
                        'billingAddress' => $billingAddress,
                        'shippingAddress' => $shippingAddress,
                    ],
                    'timeout' => 5.0,
                ],
            );

            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Sylius Shop API: setting the checkout address was rejected.', [
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: could not set the checkout address.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<int, array{code: string, name: string, description: ?string, price: int}>
     */
    public function fetchShippingMethods(string $token, int $shipmentId, string $channelCode = 'germany'): array
    {
        $data = $this->request(
            $channelCode,
            '/api/v2/shop/orders/' . rawurlencode($token) . '/shipments/' . $shipmentId . '/methods',
            [],
        );

        $items = $this->extractCollection($data);
        $methods = [];
        foreach ($items as $item) {
            if (!isset($item['code'], $item['name'])) {
                continue;
            }
            $methods[] = [
                'code' => $this->strOrEmpty($item['code']),
                'name' => $this->strOrEmpty($item['name']),
                'description' => isset($item['description']) ? $this->str($item['description']) : null,
                'price' => is_int($item['price'] ?? null) ? $item['price'] : 0,
            ];
        }

        return $methods;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectShippingMethod(string $token, int $shipmentId, string $methodCode, string $channelCode = 'germany'): ?array
    {
        return $this->patchCheckoutSubResource(
            $token,
            '/shipments/' . $shipmentId,
            'shippingMethod',
            '/api/v2/shop/shipping-methods/' . $methodCode,
            $channelCode,
        );
    }

    /**
     * @return array<int, array{code: string, name: string, description: ?string, instructions: ?string}>
     */
    public function fetchPaymentMethods(string $token, int $paymentId, string $channelCode = 'germany'): array
    {
        $data = $this->request(
            $channelCode,
            '/api/v2/shop/orders/' . rawurlencode($token) . '/payments/' . $paymentId . '/methods',
            [],
        );

        $items = $this->extractCollection($data);
        $methods = [];
        foreach ($items as $item) {
            if (!isset($item['code'], $item['name'])) {
                continue;
            }
            $methods[] = [
                'code' => $this->strOrEmpty($item['code']),
                'name' => $this->strOrEmpty($item['name']),
                'description' => isset($item['description']) ? $this->str($item['description']) : null,
                'instructions' => isset($item['instructions']) ? $this->str($item['instructions']) : null,
            ];
        }

        return $methods;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectPaymentMethod(string $token, int $paymentId, string $methodCode, string $channelCode = 'germany'): ?array
    {
        return $this->patchCheckoutSubResource(
            $token,
            '/payments/' . $paymentId,
            'paymentMethod',
            '/api/v2/shop/payment-methods/' . $methodCode,
            $channelCode,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function completeCheckout(string $token, string $channelCode = 'germany'): ?array
    {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request(
                'PATCH',
                $this->syliusInternalBaseUrl . '/api/v2/shop/orders/' . rawurlencode($token) . '/complete',
                [
                    'headers' => [
                        'Host' => $hostname,
                        'Content-Type' => 'application/merge-patch+json',
                        'Accept' => 'application/ld+json',
                        'Accept-Language' => $locale,
                    ],
                    'json' => ['notes' => ''],
                    'timeout' => 5.0,
                ],
            );

            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Sylius Shop API: completing the order was rejected.', [
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: could not complete the order.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Shared PATCH helper for the shipment/payment method selection calls,
     * which have the identical shape (merge-patch+json, one IRI field).
     *
     * @return array<string, mixed>|null
     */
    private function patchCheckoutSubResource(
        string $token,
        string $subPath,
        string $fieldName,
        string $fieldIri,
        string $channelCode,
    ): ?array {
        $hostname = self::CHANNEL_HOSTNAMES[$channelCode] ?? self::CHANNEL_HOSTNAMES['germany'];
        $locale = self::CHANNEL_LOCALES[$channelCode] ?? self::CHANNEL_LOCALES['germany'];

        try {
            $response = $this->httpClient->request(
                'PATCH',
                $this->syliusInternalBaseUrl . '/api/v2/shop/orders/' . rawurlencode($token) . $subPath,
                [
                    'headers' => [
                        'Host' => $hostname,
                        'Content-Type' => 'application/merge-patch+json',
                        'Accept' => 'application/ld+json',
                        'Accept-Language' => $locale,
                    ],
                    'json' => [$fieldName => $fieldIri],
                    'timeout' => 5.0,
                ],
            );

            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Sylius Shop API: checkout step rejected.', [
                    'path' => $subPath,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            return $this->toAssoc($response);
        } catch (\Throwable $e) {
            $this->logger->warning('Sylius Shop API: checkout step failed.', [
                'path' => $subPath,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
