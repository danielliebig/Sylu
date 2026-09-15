<?php

declare(strict_types=1);

/*
 * src/Fixture/RockbandProductsFixture.php
 *
 * Namespace App\Fixture -> directory fixtures/
 * The autoload entry for this is in composer.json and gets added
 * automatically by "make install-apps":
 *
 *     "autoload": { "psr-4": { "App\\Fixture\\": "fixtures/" } }
 */

namespace App\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;


/**
 * Six rockband equipment products for the DACH demo.
 *
 * IMAGE STRATEGY - two tiers, so the install never crashes:
 *   1. Local file from var/demo-images/<code>.jpg (drop your own photo
 *      there before running "make fixtures" - see README.md)
 *   2. Placeholder generated via GD with a category-specific icon (see
 *      FIXES.md No. 37) plus the product name
 *
 * A Pixabay-based download tier existed here until FIXES.md No. 38:
 * Pixabay's CDN reliably returns 403 Forbidden for this kind of
 * automated request (confirmed, not assumed - see FIXES.md No. 35),
 * so the download step never actually succeeded and was removed
 * entirely rather than kept as permanently-dead code.
 *
 * The fixture is idempotent: existing products are skipped.
 */
#[Autoconfigure(tags: ['sylius_fixtures.fixture'])]
final class RockbandProductsFixture extends AbstractFixture
{
    private const CHANNELS = ['germany', 'austria', 'switzerland'];
    private const LOCALES = ['de_DE', 'de_AT', 'de_CH'];

    /** @var array<int, array<string, mixed>> */
    private const PRODUCTS = [
        [
            'code' => 'fender_stratocaster',
            'name' => 'Fender Stratocaster',
            'taxon' => 'guitars',
            'price_eur' => 129900,
            'price_chf' => 124900,
            'on_hand' => 12,
            'short' => 'Die Legende unter den E-Gitarren.',
            'description' => 'Der Klassiker seit 1954: drei Single-Coil-Tonabnehmer, Tremolo und der unverwechselbar glasklare Ton. Erlenkorpus, Ahornhals, moderne Bundierung. Gigbag im Lieferumfang.',
        ],
        [
            'code' => 'gibson_les_paul',
            'name' => 'Gibson Les Paul',
            'taxon' => 'guitars',
            'price_eur' => 149900,
            'price_chf' => 144900,
            'on_hand' => 10,
            'short' => 'Mahagoni, Humbucker, endloses Sustain.',
            'description' => 'Massiver Mahagonikorpus mit geriegelter Ahorndecke, zwei Humbucker und der Ton, der den Rock definiert hat. Set-Neck-Konstruktion, Koffer inklusive.',
        ],
        [
            'code' => 'marshall_dsl40cr',
            'name' => 'Marshall DSL40CR',
            'taxon' => 'amplifiers',
            'price_eur' => 89900,
            'price_chf' => 86900,
            'on_hand' => 15,
            'short' => '40 Watt Vollroehre, zwei Kanaele.',
            'description' => 'Classic-Gain- und Ultra-Gain-Kanal, digitales Reverb, serieller Effektweg und schaltbare Leistungsreduktion auf 20 Watt. Celestion V-Type, 12 Zoll.',
        ],
        [
            'code' => 'pearl_export_exx',
            'name' => 'Pearl Export EXX',
            'taxon' => 'drums',
            'price_eur' => 109900,
            'price_chf' => 105900,
            'on_hand' => 8,
            'short' => 'Das meistverkaufte Schlagzeug der Welt.',
            'description' => 'Fusion-Set aus Pappel und Asia-Mahagoni, komplett mit Hardware, Hocker und Becken. Der Standard fuer Proberaum und Buehne.',
        ],
        [
            'code' => 'shure_sm58',
            'name' => 'Shure SM58',
            'taxon' => 'microphones',
            'price_eur' => 15900,
            'price_chf' => 15400,
            'on_hand' => 20,
            'short' => 'Der Industriestandard fuer Gesang.',
            'description' => 'Dynamisches Nierenmikrofon, praktisch unzerstoerbar, mit integriertem Poppschutz und Schwingungsdaempfung. Seit Jahrzehnten auf jeder Buehne der Welt.',
        ],
        [
            'code' => 'boss_ds1_distortion',
            'name' => 'Boss DS-1 Distortion',
            'taxon' => 'effects',
            'price_eur' => 6900,
            'price_chf' => 6700,
            'on_hand' => 20,
            'short' => 'Der orangefarbene Klassiker.',
            'description' => 'Seit 1978 unveraendert im Programm: harter, schneidender Distortion-Sound mit Tone-, Level- und Dist-Regler im unverwuestlichen Boss-Gehaeuse.',
        ],
    ];

    /**
     * Every dependency hangs off a #[Autowire] attribute pointing at a
     * verified service ID (Sylius 2.2.8). This means the class needs NO
     * entry in config/services.yaml - that was the root cause behind
     * eight failed attempts.
     *
     * Repositories are deliberately untyped: the concrete objects are
     * Doctrine EntityRepository subclasses, and which Sylius interface
     * they implement in 2.x is version-dependent. With an explicit
     * service ID, Symfony doesn't need the type anyway.
     */
    public function __construct(
        #[Autowire(service: 'sylius.factory.product')]
        private readonly FactoryInterface $productFactory,

        #[Autowire(service: 'sylius.factory.product_variant')]
        private readonly FactoryInterface $productVariantFactory,

        #[Autowire(service: 'sylius.factory.channel_pricing')]
        private readonly FactoryInterface $channelPricingFactory,

        #[Autowire(service: 'sylius.factory.product_image')]
        private readonly FactoryInterface $productImageFactory,

        #[Autowire(service: 'sylius.factory.product_taxon')]
        private readonly FactoryInterface $productTaxonFactory,

        #[Autowire(service: 'sylius.repository.taxon')]
        private readonly object $taxonRepository,

        #[Autowire(service: 'sylius.repository.channel')]
        private readonly object $channelRepository,

        #[Autowire(service: 'sylius.repository.tax_category')]
        private readonly object $taxCategoryRepository,

        #[Autowire(service: 'sylius.repository.product')]
        private readonly object $productRepository,

        // NOTE: called sylius.uploader.image, NOT sylius.image_uploader
        #[Autowire(service: 'sylius.uploader.image')]
        private readonly ImageUploaderInterface $imageUploader,

        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $manager,

        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getName(): string
    {
        return 'rockband_products';
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->scalarNode('image_dir')
                    ->defaultValue('%kernel.project_dir%/var/demo-images')
                    ->info('Directory checked for a local <product-code>.jpg before falling back to the generated placeholder icon.')
                ->end()
            ->end()
        ;
    }

    public function load(array $options): void
    {
        $imageDir = (string) ($options['image_dir'] ?? $this->projectDir . '/var/demo-images');
        $imageDir = str_replace('%kernel.project_dir%', $this->projectDir, $imageDir);

        if (!is_dir($imageDir)) {
            @mkdir($imageDir, 0o775, true);
        }

        $channels = $this->loadChannels();

        foreach (self::PRODUCTS as $data) {
            if (null !== $this->productRepository->findOneBy(['code' => $data['code']])) {
                continue;
            }

            $product = $this->createProduct($data, $channels);
            $this->attachTaxon($product, (string) $data['taxon']);
            $this->attachVariant($product, $data, $channels);
            $this->attachImage($product, $data, $imageDir);

            $this->manager->persist($product);
        }

        $this->manager->flush();
    }

    /** @return array<string, ChannelInterface> */
    private function loadChannels(): array
    {
        $channels = [];

        foreach (self::CHANNELS as $code) {
            $channel = $this->channelRepository->findOneBy(['code' => $code]);
            if ($channel instanceof ChannelInterface) {
                $channels[$code] = $channel;
            }
        }

        if ([] === $channels) {
            throw new \RuntimeException(
                'No DACH channels found. The channel fixture must run first. '
                . 'Please use "make demo-install" - it loads the suite in the correct order.'
            );
        }

        return $channels;
    }

    /**
     * @param array<string, mixed>            $data
     * @param array<string, ChannelInterface> $channels
     */
    private function createProduct(array $data, array $channels): ProductInterface
    {
        /** @var ProductInterface $product */
        $product = $this->productFactory->createNew();
        $product->setCode((string) $data['code']);
        $product->setEnabled(true);

        // Fallback locale must match the locale being written, not a
        // fixed 'de_DE': with a differing fallback, Sylius' translatable
        // layer resolves getTranslation() to the fallback's translation
        // and every loop iteration writes into that same de_DE record -
        // which is why only de_DE translations existed until FIXES.md
        // No. 48. Without de_CH/de_AT translations the Shop API returns
        // nothing at all for those channels.
        foreach (self::LOCALES as $locale) {
            $product->setCurrentLocale($locale);
            $product->setFallbackLocale($locale);
            $product->setName((string) $data['name']);
            $product->setSlug($this->slugify((string) $data['name']));
            $product->setShortDescription((string) $data['short']);
            $product->setDescription((string) $data['description']);
            // No setMetaTitle() in this Sylius version - the data model only
            // knows MetaKeywords and MetaDescription (see FIXES.md No. 12).
            // The <title> tag is generated from the product name.
            $product->setMetaDescription(mb_substr((string) $data['short'], 0, 160));
        }
        $product->setCurrentLocale('de_DE');
        $product->setFallbackLocale('de_DE');

        foreach ($channels as $channel) {
            $product->addChannel($channel);
        }

        return $product;
    }

    private function attachTaxon(ProductInterface $product, string $taxonCode): void
    {
        $taxon = $this->taxonRepository->findOneBy(['code' => $taxonCode]);
        if (!$taxon instanceof TaxonInterface) {
            return;
        }

        /** @var ProductTaxonInterface $productTaxon */
        $productTaxon = $this->productTaxonFactory->createNew();
        $productTaxon->setTaxon($taxon);
        $productTaxon->setProduct($product);
        $productTaxon->setPosition(0);

        $product->addProductTaxon($productTaxon);
        $product->setMainTaxon($taxon);
    }

    /**
     * @param array<string, mixed>            $data
     * @param array<string, ChannelInterface> $channels
     */
    private function attachVariant(ProductInterface $product, array $data, array $channels): void
    {
        /** @var ProductVariantInterface $variant */
        $variant = $this->productVariantFactory->createNew();
        $variant->setCode($data['code'] . '_variant');
        $variant->setProduct($product);
        $variant->setTracked(true);
        $variant->setOnHand((int) $data['on_hand']);
        $variant->setShippingRequired(true);

        foreach (self::LOCALES as $locale) {
            $variant->setCurrentLocale($locale);
            $variant->setFallbackLocale($locale);
            $variant->setName((string) $data['name']);
        }
        $variant->setCurrentLocale('de_DE');
        $variant->setFallbackLocale('de_DE');

        // The method_exists() guard that used to sit here was removed:
        // PHPStan proved it always evaluates to true, since
        // ProductVariantInterface declares setTaxCategory() (see FIXES.md
        // No. 40). It was defensive code written when this project was
        // still unsure which Sylius version's API it was targeting -
        // that uncertainty is long resolved.
        $taxCategory = $this->taxCategoryRepository->findOneBy(['code' => 'standard']);
        if (null !== $taxCategory) {
            $variant->setTaxCategory($taxCategory);
        }

        foreach ($channels as $channelCode => $channel) {
            // CHF prices are maintained separately rather than converted -
            // Swiss retail prices rarely follow the daily exchange rate.
            $price = 'switzerland' === $channelCode
                ? (int) $data['price_chf']
                : (int) $data['price_eur'];

            /** @var ChannelPricingInterface $pricing */
            $pricing = $this->channelPricingFactory->createNew();
            $pricing->setChannelCode($channelCode);
            $pricing->setPrice($price);
            $pricing->setOriginalPrice((int) round($price * 1.1)); // strikethrough price for the demo

            $variant->addChannelPricing($pricing);
        }

        $product->addVariant($variant);
    }

    /** @param array<string, mixed> $data */
    private function attachImage(ProductInterface $product, array $data, string $imageDir): void
    {
        $path = $this->resolveImagePath($data, $imageDir);

        if (null === $path) {
            return; // better a product without an image than a crashed setup
        }

        try {
            /** @var ProductImageInterface $image */
            $image = $this->productImageFactory->createNew();
            $image->setType('main');
            $image->setFile(new UploadedFile($path, basename($path), 'image/jpeg', null, true));

            $this->imageUploader->upload($image);
            $product->addImage($image);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[RockbandProductsFixture] Skipped image for "%s": %s',
                $data['code'],
                $e->getMessage(),
            ));
        }
    }

    /** @param array<string, mixed> $data */
    private function resolveImagePath(array $data, string $imageDir): ?string
    {
        $local = sprintf('%s/%s.jpg', $imageDir, $data['code']);

        // Tier 1: available locally - drop your own photo here (see README.md).
        if (is_file($local) && filesize($local) > 1024) {
            return $local;
        }

        // Tier 2: placeholder. A Pixabay-download tier used to sit here -
        // removed entirely in FIXES.md No. 38, since Pixabay's CDN
        // reliably returned 403 Forbidden for this kind of automated
        // request (confirmed in FIXES.md No. 35) and the download step
        // never actually succeeded.
        return $this->createPlaceholder($local, (string) $data['name'], (string) $data['taxon']);
    }

    /**
     * Drawn entirely with GD's own primitives (no external image or font
     * file needed) - a category-specific icon plus the product name.
     * Deliberately abstract line-art, not an attempt at a realistic
     * product photo - this is what a visitor sees whenever no local photo
     * was provided (see resolveImagePath() above), not just an occasional
     * fallback.
     */
    private function createPlaceholder(string $path, string $label, string $taxon): ?string
    {
        if (!\function_exists('imagecreatetruecolor')) {
            return null;
        }

        $width = 1200;
        $height = 900;

        $image = imagecreatetruecolor($width, $height);
        if (false === $image) {
            return null;
        }

        $bg = imagecolorallocate($image, 24, 24, 27);
        $accent = imagecolorallocate($image, 250, 204, 21);
        $fg = imagecolorallocate($image, 244, 244, 245);
        $line = imagecolorallocate($image, 82, 82, 91);

        imagefilledrectangle($image, 0, 0, $width, $height, $bg);
        imagefilledrectangle($image, 0, $height - 28, $width, $height, $accent);

        // Icon area: roughly the top 60% of the image, centered.
        $this->drawCategoryIcon($image, $taxon, $width, (int) ($height * 0.58), $line, $accent, $bg);

        $text = $this->asciiFold($label);
        $textWidth = imagefontwidth(5) * strlen($text);
        imagestring($image, 5, (int) (($width - $textWidth) / 2), (int) ($height * 0.66), $text, $fg);

        $note = 'Demo Placeholder';
        $noteWidth = imagefontwidth(3) * strlen($note);
        imagestring($image, 3, (int) (($width - $noteWidth) / 2), (int) ($height * 0.72), $note, $accent);

        $written = imagejpeg($image, $path, 85);
        imagedestroy($image);

        return $written ? $path : null;
    }

    /**
     * @param \GdImage $image
     *
     * Canvas is always 1200x900 (see createPlaceholder()). Every shape
     * below was first prototyped and RENDERED in Python/PIL, viewed as an
     * actual image, and only translated to GD calls once it looked right
     * - not just hand-calculated coordinates (see FIXES.md No. 37 for why
     * that distinction mattered: the first version of these icons was
     * calculated to fit the canvas correctly, but nobody had actually
     * looked at the result, and it turned out unrecognizable - two
     * overlapping circles read as a blob, not a guitar).
     *
     * PIL and GD map onto each other directly for the shapes used here:
     * PIL's ellipse(bbox) becomes GD's imagefilledellipse(cx, cy, w, h)
     * (center + size, not corners - the prototypes' own small ellipse()
     * helper already worked in center+size, so the translation is 1:1);
     * PIL's rectangle(bbox) is identical to
     * imagefilledrectangle(x1, y1, x2, y2); PIL's line(..., width=N)
     * needs an explicit imagesetthickness($image, N) before GD's
     * imageline() and imagesetthickness($image, 1) after, to reset it
     * for whatever draws next.
     */
    private function drawCategoryIcon($image, string $taxon, int $canvasWidth, int $areaHeight, int $line, int $accent, int $bg): void
    {
        $cx = (int) ($canvasWidth / 2);
        // Biased toward the lower half of the icon area, not its exact
        // midpoint - the guitar icon in particular needs more headroom
        // above center (neck + headstock) than below it.
        $cy = (int) ($areaHeight * 0.6);

        switch ($taxon) {
            case 'guitars':
                // Deliberately a simple, generic guitar silhouette (round
                // body + neck + headstock), not an attempt at a precise
                // solidbody outline - the precise version (two
                // similarly-sized overlapping ellipses) is exactly what
                // looked like a blob in the first render. Body: single
                // large oval, centered (cx, cy+150), 320x360.
                imagefilledellipse($image, $cx, $cy + 150, 320, 360, $line);
                // Neck, vertical, centered on the body.
                imagefilledrectangle($image, $cx - 24, $cy - 260, $cx + 24, $cy - 10, $line);
                // Headstock.
                imagefilledrectangle($image, $cx - 55, $cy - 310, $cx + 55, $cy - 255, $line);
                // Tuning-peg hints poking out both sides of the headstock.
                imagefilledrectangle($image, $cx - 75, $cy - 300, $cx - 55, $cy - 290, $line);
                imagefilledrectangle($image, $cx - 75, $cy - 275, $cx - 55, $cy - 265, $line);
                imagefilledrectangle($image, $cx + 55, $cy - 300, $cx + 75, $cy - 290, $line);
                imagefilledrectangle($image, $cx + 55, $cy - 275, $cx + 75, $cy - 265, $line);
                // Strings running down the neck onto the body.
                imagesetthickness($image, 2);
                for ($i = 0; $i < 6; ++$i) {
                    $x = $cx - 15 + ($i * 6);
                    imageline($image, $x, $cy - 255, $x, $cy + 180, $accent);
                }
                imagesetthickness($image, 1);
                // Sound hole (ring, simulated as two concentric filled
                // circles - GD has no stroked-ellipse-with-width primitive).
                imagefilledellipse($image, $cx, $cy + 105, 90, 90, $accent);
                imagefilledellipse($image, $cx, $cy + 105, 82, 82, $bg);
                break;

            case 'amplifiers':
                $panel = imagecolorallocate($image, 58, 58, 66);
                $grille = imagecolorallocate($image, 40, 40, 46);

                // Cabinet.
                imagefilledrectangle($image, $cx - 210, $cy - 200, $cx + 210, $cy + 170, $line);
                // Control panel strip - knobs sit ON this, not floating
                // in empty space above the cabinet like the first version.
                imagefilledrectangle($image, $cx - 210, $cy - 200, $cx + 210, $cy - 130, $panel);
                for ($i = 0; $i < 4; ++$i) {
                    imagefilledellipse($image, $cx - 120 + ($i * 80), $cy - 165, 30, 30, $accent);
                }
                // Speaker grille area.
                imagefilledrectangle($image, $cx - 180, $cy - 105, $cx + 180, $cy + 145, $grille);
                imagefilledellipse($image, $cx - 88, $cy + 20, 130, 130, $line);
                imagefilledellipse($image, $cx - 88, $cy + 20, 46, 46, $grille);
                imagefilledellipse($image, $cx + 88, $cy + 20, 130, 130, $line);
                imagefilledellipse($image, $cx + 88, $cy + 20, 46, 46, $grille);
                // Small feet so the cabinet visibly sits rather than floats.
                imagefilledrectangle($image, $cx - 190, $cy + 170, $cx - 150, $cy + 192, $panel);
                imagefilledrectangle($image, $cx + 150, $cy + 170, $cx + 190, $cy + 192, $panel);
                break;

            case 'drums':
                $rimBase = imagecolorallocate($image, 110, 110, 120);
                $skin = imagecolorallocate($image, 150, 150, 160);
                $lug = imagecolorallocate($image, 140, 140, 150);

                // A closed, skinned drum head, not an open bowl - the
                // first version's single dark ring read as a grill/
                // firepit, not an instrument.
                $shellTopY = $cy - 40;
                $shellBotY = $cy + 110;
                imagefilledrectangle($image, $cx - 170, $shellTopY, $cx + 170, $shellBotY, $line);
                imagefilledellipse($image, $cx, $shellBotY, 340, 110, $line);
                imagefilledellipse($image, $cx, $shellTopY, 340, 120, $rimBase);
                imagefilledellipse($image, $cx, $shellTopY, 300, 92, $accent);
                imagefilledellipse($image, $cx, $shellTopY, 268, 74, $skin);
                // Tension lugs around the shell.
                for ($i = -2; $i <= 2; ++$i) {
                    $x = $cx + ($i * 68);
                    imagefilledrectangle($image, $x - 7, $shellTopY + 20, $x + 7, $shellBotY - 15, $lug);
                }
                // Stand legs - thick enough to read as a stand, not
                // hairlines (the first version's 1px default was too thin).
                imagesetthickness($image, 14);
                imageline($image, $cx - 140, $shellBotY + 20, $cx - 210, $cy + 300, $line);
                imageline($image, $cx + 140, $shellBotY + 20, $cx + 210, $cy + 300, $line);
                imageline($image, $cx, $shellBotY + 45, $cx, $cy + 300, $line);
                imagesetthickness($image, 1);
                break;

            case 'microphones':
                // Unchanged from the original version - this one already
                // rendered correctly the first time (confirmed again in
                // the Python/PIL re-check before this fix).
                imagefilledellipse($image, $cx, $cy - 120, 200, 260, $line);
                imagesetthickness($image, 2);
                for ($i = -3; $i <= 3; ++$i) {
                    imageline($image, $cx - 90, $cy - 120 + ($i * 20), $cx + 90, $cy - 120 + ($i * 20), $accent);
                }
                imagesetthickness($image, 1);
                imagefilledrectangle($image, $cx - 45, $cy + 10, $cx + 45, $cy + 220, $line);
                break;

            case 'effects':
            default:
                $panel = imagecolorallocate($image, 58, 58, 66);
                $knob = imagecolorallocate($image, 140, 140, 150);

                // Housing.
                imagefilledrectangle($image, $cx - 150, $cy - 170, $cx + 150, $cy + 200, $line);
                // Jack sockets sticking out both sides - a detail that
                // immediately reads as "pedal" rather than a generic box.
                imagefilledrectangle($image, $cx - 182, $cy - 110, $cx - 150, $cy - 60, $panel);
                imagefilledrectangle($image, $cx + 150, $cy - 110, $cx + 182, $cy - 60, $panel);
                // Three control knobs with a pointer line each.
                imagesetthickness($image, 4);
                foreach ([-1, 0, 1] as $i) {
                    $x = $cx + ($i * 70);
                    imagefilledellipse($image, $x, $cy - 110, 44, 44, $knob);
                    imageline($image, $x, $cy - 110, $x, $cy - 132, $accent);
                }
                imagesetthickness($image, 1);
                // Label strip.
                imagefilledrectangle($image, $cx - 95, $cy - 45, $cx + 95, $cy - 10, $panel);
                // Footswitch and status LED.
                imagefilledellipse($image, $cx, $cy + 110, 110, 110, $knob);
                imagefilledellipse($image, $cx, $cy + 110, 74, 74, $panel);
                imagefilledellipse($image, $cx, $cy + 30, 26, 26, $accent);
                break;
        }
    }

    private function slugify(string $value): string
    {
        $value = $this->asciiFold($value);
        $value = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));

        return '' === $value ? bin2hex(random_bytes(4)) : $value;
    }

    private function asciiFold(string $value): string
    {
        $value = strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'ß' => 'ss',
        ]);

        return (string) preg_replace('/[^\x20-\x7E]/', '', $value);
    }
}
