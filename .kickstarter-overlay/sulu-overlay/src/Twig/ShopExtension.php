<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CartManager;
use App\Service\SyliusShopClient;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Registers itself automatically with Symfony/Twig, because the class
 * extends AbstractExtension and lives in the autoconfigured "src/"
 * namespace (standard Symfony skeleton convention) - no entry in
 * config/services.yaml needed. Exactly the pattern earned on the Sylius
 * side only after several failed attempts (see FIXES.md No. 8): simple
 * services with exclusively autowireable dependencies
 * (HttpClientInterface, LoggerInterface) need no explicit wiring.
 */
final class ShopExtension extends AbstractExtension
{
    public function __construct(
        private readonly SyliusShopClient $shopClient,
        private readonly CartManager $cartManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sylius_featured_products', $this->fetchFeaturedProducts(...)),
            new TwigFunction('sylius_cart_summary', $this->cartSummary(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new \Twig\TwigFilter('sylius_price', $this->formatPrice(...)),
        ];
    }

    /**
     * Sylius returns prices as an integer in cents (129900 = €1,299.00 /
     * CHF 1,299.00 - verified against real API responses, see
     * SyliusShopClient.php).
     */
    public function formatPrice(?int $cents, string $currencySymbol = '€'): string
    {
        if (null === $cents) {
            return '';
        }

        return number_format($cents / 100, 2, ',', '.') . ' ' . $currencySymbol;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchFeaturedProducts(string $channel = 'germany', int $limit = 6): array
    {
        return $this->shopClient->fetchFeaturedProducts($channel, $limit);
    }

    /**
     * Cart badge for the navigation - a cheap summary, not the full cart
     * (see CartManager::getSummary). Never creates a cart just to show a
     * badge - an empty summary means no token in the session yet.
     *
     * @return array{count: int, total: int}
     */
    public function cartSummary(string $channel = 'germany'): array
    {
        return $this->cartManager->getSummary($channel);
    }
}
