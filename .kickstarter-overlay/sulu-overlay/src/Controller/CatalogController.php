<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SyliusShopClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Catalog pages for headless Sylius operation (see FIXES.md No. 23).
 *
 * DELIBERATELY AN ORDINARY SYMFONY CONTROLLER WITH ITS OWN ROUTES, NOT A
 * SULU CMS PAGE: Sulu's page tree is meant for editorially created
 * individual pages. A product catalog with potentially many items is
 * machine-generated, data-driven content - for that, a plain controller
 * with its own routes is the right tool, not one Sulu page document per
 * product. It still renders into the same shared layout (base.html.twig),
 * so it doesn't look visually different from Sulu-managed pages.
 *
 * Routing via PHP attribute (#[Route]), not a YAML file: that's the
 * modern Symfony Flex convention this skeleton already uses elsewhere
 * (App\Command\SeedHomepageCommand with #[AsCommand]) - no extra routing
 * import configuration needed.
 *
 * UNVERIFIED (the one open point): whether Sulu's own catch-all route for
 * page content intercepts these routes before Symfony reaches them
 * couldn't be checked without a live test. Symfony evaluates routes in
 * registration order, and CMS bundles usually register their catch-all
 * route at low priority specifically so application routes like these
 * take precedence - but that's an expectation, not a fact checked against
 * the code. Quick test after deploying: curl -I http://localhost/produkte/
 */
final class CatalogController
{
    private const CHANNEL = 'germany';

    public function __construct(
        private readonly SyliusShopClient $shopClient,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/produkte/', name: 'catalog_taxons', methods: ['GET'])]
    public function taxons(): Response
    {
        $taxons = $this->shopClient->fetchTaxons(self::CHANNEL);

        return new Response($this->twig->render('catalog/taxons.html.twig', [
            'taxons' => $taxons,
        ]));
    }

    #[Route('/produkte/{taxonCode}/', name: 'catalog_taxon', methods: ['GET'])]
    public function taxon(string $taxonCode): Response
    {
        $products = $this->shopClient->fetchProductsByTaxon($taxonCode, self::CHANNEL);

        // Not an error if the category is empty or the API returns
        // nothing - an empty result list is a valid state. Only the
        // category name is then missing; we fall back to the code.
        $taxons = $this->shopClient->fetchTaxons(self::CHANNEL);
        $taxonName = $taxonCode;
        foreach ($taxons as $taxon) {
            if ($taxon['code'] === $taxonCode) {
                $taxonName = $taxon['name'];
                break;
            }
        }

        return new Response($this->twig->render('catalog/taxon.html.twig', [
            'taxonCode' => $taxonCode,
            'taxonName' => $taxonName,
            'products' => $products,
        ]));
    }

    #[Route('/produkte/{taxonCode}/{code}', name: 'catalog_product', methods: ['GET'])]
    public function product(string $taxonCode, string $code): Response
    {
        $product = $this->shopClient->fetchProduct($code, self::CHANNEL);

        if (null === $product) {
            throw new NotFoundHttpException(sprintf('Product "%s" was not found.', $code));
        }

        return new Response($this->twig->render('catalog/product.html.twig', [
            'taxonCode' => $taxonCode,
            'product' => $product,
        ]));
    }
}
