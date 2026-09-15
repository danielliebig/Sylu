<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\CartManager;
use App\Service\SyliusShopClient;
use App\Twig\ShopExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the sylius_price Twig filter.
 *
 * Pure function, no infrastructure needed. Real collaborator instances
 * are constructed rather than mocked because SyliusShopClient and
 * CartManager are both `final` - PHPUnit cannot mock those, and neither
 * is actually touched by formatPrice(). An HTTP client that is never
 * called satisfies the constructor perfectly well.
 *
 * What this pins down: Sylius returns prices as an integer in cents
 * (129900 = 1.299,00 EUR), verified against real API responses in
 * FIXES.md No. 23. These tests don't detect a change on Sylius' side -
 * only that our own conversion stays correct and German-formatted.
 */
final class ShopExtensionTest extends TestCase
{
    private function extension(): ShopExtension
    {
        $client = new SyliusShopClient(new MockHttpClient(), new NullLogger());

        return new ShopExtension($client, new CartManager($client, new RequestStack()));
    }

    public function testFormatsCentsAsGermanCurrency(): void
    {
        self::assertSame('1.299,00 €', $this->extension()->formatPrice(129900));
    }

    public function testUsesCommaAsDecimalSeparatorAndDotAsThousands(): void
    {
        // German convention, not the PHP default - worth pinning down.
        self::assertSame('9,99 €', $this->extension()->formatPrice(999));
        self::assertSame('99,00 €', $this->extension()->formatPrice(9900));
        self::assertSame('1.000,00 €', $this->extension()->formatPrice(100000));
        self::assertSame('1.234.567,89 €', $this->extension()->formatPrice(123456789));
    }

    public function testReturnsEmptyStringForNull(): void
    {
        // Products without a price must not render "0,00 €" - an absent
        // price and a free product are different things.
        self::assertSame('', $this->extension()->formatPrice(null));
    }

    public function testZeroIsFormattedRatherThanTreatedAsAbsent(): void
    {
        self::assertSame('0,00 €', $this->extension()->formatPrice(0));
    }

    public function testCurrencySymbolIsConfigurable(): void
    {
        // The Swiss channel prices in CHF (see dach_demo.yaml).
        self::assertSame('1.249,00 CHF', $this->extension()->formatPrice(124900, 'CHF'));
    }

    public function testHandlesNegativeAmounts(): void
    {
        // Not expected in the storefront, but a refund or a miscalculated
        // discount shouldn't produce garbage.
        self::assertSame('-5,00 €', $this->extension()->formatPrice(-500));
    }
}
