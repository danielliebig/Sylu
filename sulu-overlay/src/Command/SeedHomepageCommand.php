<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fills the homepage automatically created by "sulu:build dev" with
 * demo content - no manual click in the admin interface needed.
 *
 * SULU 3 REARCHITECTED PAGE MODIFICATION COMPLETELY compared to
 * Sulu 1.x/2.x: no more PHPCR/DocumentManager API, instead a Symfony
 * Messenger message bus (ModifyPageMessage, CreatePageMessage). Pages
 * themselves live in ordinary Doctrine ORM tables (pa_pages,
 * pa_page_dimension_contents), no longer in PHPCR.
 *
 * EVERY STEP BELOW IS VERIFIED AGAINST THE ACTUAL SULU 3.0 CODE AND A
 * REAL RUNNING DATABASE:
 *
 *   - Namespace/constructor of ModifyPageMessage:
 *     vendor/sulu/sulu/packages/page/src/Application/Message/ModifyPageMessage.php
 *   - Handler flow (loads via PageRepositoryInterface, passes $data to
 *     every PageMapperInterface, which forwards it to
 *     ContentPersisterInterface):
 *     vendor/sulu/sulu/packages/page/src/Application/MessageHandler/ModifyPageMessageHandler.php
 *   - EnableFlushStamp IS REQUIRED: without this stamp in the envelope,
 *     the handler runs to completion (reports success!), but Doctrine
 *     never flushes - the change disappears without a trace in memory.
 *     Confirmed by the source itself: "Marker stamp to enable
 *     DoctrineFlushMiddleware for envelopes with this stamp."
 *     (vendor/sulu/messenger/.../EnableFlushStamp.php)
 *   - Data structure checked directly via SQL (pa_pages: webspaceKey, uuid,
 *     depth; pa_page_dimension_contents: stage, templateKey, title,
 *     templateData as JSON with the XML property names as keys)
 *   - ModifyPageMessage writes exclusively to STAGE_DRAFT (comment in the
 *     handler code). Publishing (draft -> live) deliberately stays a
 *     manual click in the admin - a draft row is harmless, an automatic
 *     go-live with no human in the loop would not be.
 *
 * If the command fails anyway (e.g. because a future Sulu update
 * reshuffles these classes again): the error message unwraps the actual
 * cause from the Messenger HandlerFailedException. The manual path in the
 * Sulu admin (README.md, "Integration" section) works in every case,
 * independent of this command.
 */
#[AsCommand(
    name: 'app:seed-homepage',
    description: 'Fills the Sulu homepage with demo content (draft - publishing stays manual).',
)]
final class SeedHomepageCommand extends Command
{
    use HandleTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly Connection $connection,
    ) {
        parent::__construct();
        $this->messageBus = $messageBus;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The homepage is the only page with depth=0 in the "website"
        // webspace (confirmed against a real database row) - found this
        // way regardless of the UUID, which is new on every install.
        // A pure read query, no risk.
        $uuidRaw = $this->connection->fetchOne(
            'SELECT uuid FROM pa_pages WHERE webspaceKey = ? AND depth = 0 LIMIT 1',
            ['website'],
        );

        // fetchOne() is typed as mixed - narrow it properly here, once,
        // rather than casting blindly (a cast from mixed is itself a
        // static-analysis finding; see FIXES.md No. 41).
        if (!is_string($uuidRaw) && !is_int($uuidRaw)) {
            $io->error('No homepage found. Has "make sulu-install" been run yet?');

            return Command::FAILURE;
        }

        $uuid = (string) $uuidRaw;

        $io->writeln(sprintf('Homepage found: %s', $uuid));

        // Locale "de": must match the webspace's localization in
        // sulu-overlay/config/webspaces/website.xml, which this project
        // overrides to German (the Sulu skeleton ships English as the
        // only content localization - see FIXES.md No. 44). Writing to a
        // locale the webspace doesn't define would store content nobody
        // can reach; verify.sh section 15 checks the two stay in sync.
        $data = [
            'locale' => 'de',
            'template' => 'rockband_landing',
            'title' => 'Rockband Demo – Dein Equipment für die Bühne',
            'url' => '/',
            'heroSubline' => 'Gitarren, Verstärker, Schlagzeug und Effektgeräte – kuratiert für Musiker in Deutschland, Österreich und der Schweiz.',
            'introText' => 'Willkommen im Rockband Demo Shop. Ob Proberaum oder große Bühne – wir führen ausgewähltes Equipment von Marken, denen Musiker seit Jahrzehnten vertrauen: Fender, Gibson, Marshall, Pearl, Shure und Boss. Alle Preise inklusive Mehrwertsteuer, Versand innerhalb der DACH-Region, Zahlung nach Wahl.',
            'featuredChannel' => 'germany',
        ];

        try {
            $message = new ModifyPageMessage(['uuid' => $uuid], $data);
            // EnableFlushStamp: see class docblock - without it, nothing
            // persists, and no error gets thrown either.
            $envelope = new Envelope($message, [new EnableFlushStamp()]);
            $this->handle($envelope);

            $io->success(
                'Homepage draft filled in. Open it in the Sulu admin (http://localhost/admin/) '.
                'and publish it, then reload http://localhost/.',
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // Messenger wraps handler errors in HandlerFailedException -
            // the actual cause sits in getWrappedExceptions().
            if ($e instanceof HandlerFailedException) {
                $wrapped = $e->getWrappedExceptions();
                if ([] !== $wrapped) {
                    $e = reset($wrapped);
                }
            }

            $io->error(sprintf(
                "Automatic fill-in failed:\n%s: %s\n\n".
                'No cause for concern - the database was not changed '.
                '(ModifyPageMessage runs through the official handler path, '.
                'which persists nothing on error). Please use the manual '.
                'path instead: README.md, "Integration" section.',
                $e::class,
                $e->getMessage(),
            ));

            return Command::FAILURE;
        }
    }
}
