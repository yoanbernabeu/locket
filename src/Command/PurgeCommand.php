<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Retention\RetentionPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'locket:purge', description: 'Supprime messages, médias et clés expirés.')]
final class PurgeCommand extends Command
{
    public function __construct(private readonly RetentionPurger $purger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->purger->purge();

        (new SymfonyStyle($input, $output))->definitionList(
            ['Messages supprimés' => (string) $report->messagesDeleted],
            ['Pièces jointes supprimées' => (string) $report->attachmentsDeleted],
            ['Fichiers médias supprimés' => (string) $report->mediaFilesDeleted],
            ['Clés de conversation supprimées' => (string) $report->conversationKeysDeleted],
            ['Invitations supprimées' => (string) $report->invitationsDeleted],
            ['Compteurs de connexion supprimés' => (string) $report->loginAttemptsDeleted],
        );

        return Command::SUCCESS;
    }
}
