<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates completed test orders across the DACH channels.
 *
 *     php bin/console app:create-test-orders --count=5
 *
 * The command runs through the SAME state machine as the browser
 * checkout:
 *
 *     cart -> addressed -> shipping_selected -> payment_selected -> completed
 *
 * That makes it a smoke test as well: if it fails, the browser checkout
 * is broken too - and the error message names the exact transition along
 * with the state it reached. Most common cause: no active shipping or
 * payment method for the given channel.
 *
 * The shipping state deliberately stays at "ready" - exactly like after a
 * real checkout, before the goods leave the warehouse.
 *
 * SYLIUS 1.13
 * Inject the winzou factory instead of StateMachineInterface and call:
 *     $this->stateMachineFactory->get($order, $graph)->apply($transition);
 *     $this->stateMachineFactory->get($order, $graph)->can($transition);
 */
#[AsCommand(
    name: 'app:create-test-orders',
    description: 'Creates completed test orders for the demo.',
)]
final class CreateTestOrdersCommand extends Command
{
    /** @var array<int, array<string, string>> */
    private const ADDRESSES = [
        [
            'firstName' => 'Lena', 'lastName' => 'Hoffmann',
            'street' => 'Planken 12', 'city' => 'Mannheim', 'postcode' => '68161',
            'country' => 'DE', 'channel' => 'germany', 'phone' => '+49 621 1234567',
        ],
        [
            'firstName' => 'Tobias', 'lastName' => 'Berger',
            'street' => 'Seckenheimer Strasse 44', 'city' => 'Mannheim', 'postcode' => '68165',
            'country' => 'DE', 'channel' => 'germany', 'phone' => '+49 621 7654321',
        ],
        [
            'firstName' => 'Marlene', 'lastName' => 'Gruber',
            'street' => 'Mariahilfer Strasse 88', 'city' => 'Wien', 'postcode' => '1070',
            'country' => 'AT', 'channel' => 'austria', 'phone' => '+43 1 2345678',
        ],
        [
            'firstName' => 'Jonas', 'lastName' => 'Steiner',
            'street' => 'Bahnhofstrasse 21', 'city' => 'Zuerich', 'postcode' => '8001',
            'country' => 'CH', 'channel' => 'switzerland', 'phone' => '+41 44 1234567',
        ],
        [
            'firstName' => 'Nina', 'lastName' => 'Fischer',
            'street' => 'Langstrasse 5', 'city' => 'Zuerich', 'postcode' => '8004',
            'country' => 'CH', 'channel' => 'switzerland', 'phone' => '+41 44 7654321',
        ],
    ];

    /**
     * Verified service IDs (Sylius 2.2.8) via #[Autowire] - this class
     * needs NO entry in config/services.yaml.
     *
     * Repositories deliberately untyped: the concrete objects are
     * Doctrine EntityRepository subclasses; which Sylius interface they
     * implement in 2.x is version-dependent. With an explicit service ID,
     * Symfony doesn't need the type.
     */
    public function __construct(
        #[Autowire(service: 'sylius.factory.order')]
        private readonly FactoryInterface $orderFactory,

        #[Autowire(service: 'sylius.factory.order_item')]
        private readonly FactoryInterface $orderItemFactory,

        #[Autowire(service: 'sylius.factory.customer')]
        private readonly FactoryInterface $customerFactory,

        #[Autowire(service: 'sylius.factory.address')]
        private readonly FactoryInterface $addressFactory,

        // called sylius.modifier.order_item_quantity,
        // NOT sylius.order_item_quantity_modifier
        #[Autowire(service: 'sylius.modifier.order_item_quantity')]
        private readonly OrderItemQuantityModifierInterface $itemQuantityModifier,

        #[Autowire(service: 'sylius.modifier.order')]
        private readonly OrderModifierInterface $orderModifier,

        #[Autowire(service: 'sylius.order_processing.order_processor')]
        private readonly OrderProcessorInterface $orderProcessor,

        #[Autowire(service: 'sylius_abstraction.state_machine')]
        private readonly StateMachineInterface $stateMachine,

        #[Autowire(service: 'sylius.repository.channel')]
        private readonly object $channelRepository,

        #[Autowire(service: 'sylius.repository.product_variant')]
        private readonly object $productVariantRepository,

        #[Autowire(service: 'sylius.repository.shipping_method')]
        private readonly object $shippingMethodRepository,

        #[Autowire(service: 'sylius.repository.payment_method')]
        private readonly object $paymentMethodRepository,

        #[Autowire(service: 'sylius.repository.customer')]
        private readonly object $customerRepository,

        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of orders', '5')
            ->addOption('items', 'i', InputOption::VALUE_REQUIRED, 'Max. items per order', '3')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = max(1, (int) $input->getOption('count'));
        $maxItems = max(1, (int) $input->getOption('items'));

        /** @var array<int, ProductVariantInterface> $variants */
        $variants = $this->productVariantRepository->findAll();

        if ([] === $variants) {
            $io->error('No product variants found. Please run "make demo-install" first.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Creating %d test order(s)', $count));
        $created = 0;

        for ($i = 0; $i < $count; ++$i) {
            $address = self::ADDRESSES[$i % \count(self::ADDRESSES)];

            try {
                $order = $this->createOrder($address, $variants, $maxItems);
                ++$created;

                $io->writeln(sprintf(
                    '  <info>+</info> %-14s | %-12s | %10s %s | %s',
                    $order->getNumber() ?? '(new)',
                    $address['channel'],
                    number_format($order->getTotal() / 100, 2, ',', '.'),
                    $order->getCurrencyCode() ?? '',
                    $order->getCheckoutState(),
                ));
            } catch (\Throwable $e) {
                $io->warning(sprintf('Order %d failed: %s', $i + 1, $e->getMessage()));
            }
        }

        $this->manager->flush();

        if (0 === $created) {
            $io->error('Not a single order could be created. Please run "make doctor".');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d of %d orders created. Visible under /shop/admin/orders/',
            $created,
            $count,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string>               $addressData
     * @param array<int, ProductVariantInterface> $variants
     */
    private function createOrder(array $addressData, array $variants, int $maxItems): OrderInterface
    {
        $channel = $this->channelRepository->findOneBy(['code' => $addressData['channel']]);

        if (!$channel instanceof ChannelInterface) {
            throw new \RuntimeException(sprintf('Channel "%s" not found.', $addressData['channel']));
        }

        /** @var OrderInterface $order */
        $order = $this->orderFactory->createNew();
        $order->setChannel($channel);
        $order->setCurrencyCode($channel->getBaseCurrency()?->getCode() ?? 'EUR');
        $order->setLocaleCode($channel->getDefaultLocale()?->getCode() ?? 'de_DE');
        $order->setCustomer($this->resolveCustomer($addressData));
        $order->setTokenValue(bin2hex(random_bytes(16)));

        // --- Fill the cart ---------------------------------------------
        shuffle($variants);
        $itemCount = random_int(1, min($maxItems, \count($variants)));

        for ($i = 0; $i < $itemCount; ++$i) {
            /** @var OrderItemInterface $item */
            $item = $this->orderItemFactory->createNew();
            $item->setVariant($variants[$i]);
            $this->itemQuantityModifier->modify($item, random_int(1, 2));
            $this->orderModifier->addToOrder($order, $item);
        }

        $this->orderProcessor->process($order);
        $this->manager->persist($order);

        // --- Step 1: address ---------------------------------------------
        $order->setBillingAddress($this->createAddress($addressData));
        $order->setShippingAddress($this->createAddress($addressData));
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_ADDRESS);

        // --- Step 2: shipping ---------------------------------------------
        $this->selectShipping($order, $addressData['channel']);
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);

        // --- Step 3: payment ---------------------------------------------
        $this->selectPayment($order, $addressData['channel']);
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);

        // --- Step 4: completion ------------------------------------------
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);

        // --- Set payment to "completed" ---------------------------------------
        foreach ($order->getPayments() as $payment) {
            /** @var PaymentInterface $payment */
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            }
        }

        $this->manager->flush();

        return $order;
    }

    /** @param array<string, string> $data */
    private function resolveCustomer(array $data): CustomerInterface
    {
        $email = sprintf(
            '%s.%s@demo-kunde.de',
            strtolower($this->ascii($data['firstName'])),
            strtolower($this->ascii($data['lastName'])),
        );

        $existing = $this->customerRepository->findOneBy(['email' => $email]);
        if ($existing instanceof CustomerInterface) {
            return $existing;
        }

        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->createNew();
        $customer->setEmail($email);
        $customer->setFirstName($data['firstName']);
        $customer->setLastName($data['lastName']);
        $customer->setPhoneNumber($data['phone']);

        $this->manager->persist($customer);

        return $customer;
    }

    /** @param array<string, string> $data */
    private function createAddress(array $data): AddressInterface
    {
        /** @var AddressInterface $address */
        $address = $this->addressFactory->createNew();
        $address->setFirstName($data['firstName']);
        $address->setLastName($data['lastName']);
        $address->setStreet($data['street']);
        $address->setCity($data['city']);
        $address->setPostcode($data['postcode']);
        $address->setCountryCode($data['country']);
        $address->setPhoneNumber($data['phone']);

        return $address;
    }

    private function selectShipping(OrderInterface $order, string $channelCode): void
    {
        $method = null;

        foreach ($this->shippingMethodRepository->findAll() as $candidate) {
            /** @var ShippingMethodInterface $candidate */
            if (!$candidate->isEnabled()) {
                continue;
            }

            // Skip free shipping: that one hangs off a minimum-order-value
            // rule and isn't available for every test order.
            if (str_starts_with((string) $candidate->getCode(), 'free_')) {
                continue;
            }

            foreach ($candidate->getChannels() as $channel) {
                if ($channel->getCode() === $channelCode) {
                    $method = $candidate;
                    break 2;
                }
            }
        }

        if (null === $method) {
            throw new \RuntimeException(sprintf(
                'No active shipping method for channel "%s". Please check under Shipping Methods in the admin.',
                $channelCode,
            ));
        }

        foreach ($order->getShipments() as $shipment) {
            /** @var ShipmentInterface $shipment */
            $shipment->setMethod($method);
        }

        $this->orderProcessor->process($order);
    }

    private function selectPayment(OrderInterface $order, string $channelCode): void
    {
        $method = null;

        foreach ($this->paymentMethodRepository->findAll() as $candidate) {
            /** @var PaymentMethodInterface $candidate */
            if (!$candidate->isEnabled()) {
                continue;
            }

            foreach ($candidate->getChannels() as $channel) {
                if ($channel->getCode() === $channelCode) {
                    $method = $candidate;
                    break 2;
                }
            }
        }

        if (null === $method) {
            throw new \RuntimeException(sprintf(
                'No active payment method for channel "%s". Please check under Payment Methods in the admin.',
                $channelCode,
            ));
        }

        foreach ($order->getPayments() as $payment) {
            /** @var PaymentInterface $payment */
            $payment->setMethod($method);
        }

        $this->orderProcessor->process($order);
    }

    private function apply(OrderInterface $order, string $transition): void
    {
        $graph = OrderCheckoutTransitions::GRAPH;

        if (!$this->stateMachine->can($order, $graph, $transition)) {
            throw new \RuntimeException(sprintf(
                'Transition "%s" not possible. Current checkout state: "%s". '
                . 'Usually caused by a missing active shipping or payment method for the channel.',
                $transition,
                $order->getCheckoutState(),
            ));
        }

        $this->stateMachine->apply($order, $graph, $transition);
    }

    private function ascii(string $value): string
    {
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/[^A-Za-z0-9]/', '', $value);
    }
}
