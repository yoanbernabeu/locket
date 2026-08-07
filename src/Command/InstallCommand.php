<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Préparation d'une instance : schéma de base, transports Messenger, clés VAPID.
 *
 * Un seul fichier d'environnement est écrit, et uniquement à l'exécution : les clés VAPID sont
 * des secrets d'instance, elles n'ont rien à faire dans un fichier versionné. Sa destination
 * est réglable par `LOCKET_SECRETS_FILE`, que l'image Docker pointe vers `/data/secrets.env` —
 * dans le volume, donc sauvegardé avec le reste, et hors de l'arbre de travail, qu'une
 * reconstruction d'image remplace intégralement. À défaut, `.env.local` à la racine du projet.
 */
#[AsCommand(name: 'locket:install', description: 'Prépare l\'instance : schéma, transports et clés VAPID.')]
final class InstallCommand extends Command
{
    private const string DEFAULT_SECRETS_FILE = '.env.local';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
        // `string:` ramène l'absence de variable à une chaîne vide plutôt qu'à null.
        #[Autowire('%env(string:default::LOCKET_SECRETS_FILE)%')]
        private readonly string $secretsFile = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force-vapid', null, InputOption::VALUE_NONE, 'Régénère les clés VAPID même si elles existent déjà.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Installation de Locket');

        $io->section('Schéma de base de données');
        $io->writeln($this->runCommand('doctrine:migrations:migrate', ['--allow-no-migration' => true, '--no-interaction' => true]));

        $io->section('Transports Messenger');
        $io->writeln($this->runCommand('messenger:setup-transports', []));

        $io->section('Clés VAPID');
        $io->writeln($this->ensureVapidKeys((bool) $input->getOption('force-vapid')));

        $io->success('Instance prête. Ouvrez /setup pour créer le premier compte.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(string $name, array $arguments): string
    {
        $application = $this->getApplication();

        if (null === $application || !$application->has($name)) {
            return \sprintf('Commande « %s » indisponible, étape ignorée.', $name);
        }

        $buffer = new BufferedOutput();
        $status = $application->find($name)->run(new ArrayInput($arguments), $buffer);

        return 0 === $status ? trim($buffer->fetch()) : \sprintf('Échec de « %s » : %s', $name, trim($buffer->fetch()));
    }

    private function ensureVapidKeys(bool $force): string
    {
        $path = $this->secretsFilePath();
        $existing = is_file($path) ? (file_get_contents($path) ?: '') : '';

        if (!$force && 1 === preg_match('/^VAPID_PUBLIC_KEY="?[^"\s]+/m', $existing)) {
            return 'Clés VAPID déjà présentes dans '.$path.' (utilisez --force-vapid pour les régénérer).';
        }

        $keys = VAPID::createVapidKeys();

        $updated = $this->upsertEnvLine($existing, 'VAPID_PUBLIC_KEY', $keys['publicKey']);
        $updated = $this->upsertEnvLine($updated, 'VAPID_PRIVATE_KEY', $keys['privateKey']);

        $this->filesystem->dumpFile($path, $updated);
        // La clé privée VAPID signe l'identité de l'instance auprès des services de push :
        // elle ne doit être lisible que par le compte qui fait tourner l'application.
        $this->filesystem->chmod($path, 0o600);

        return 'Clés VAPID générées et écrites dans '.$path.'.';
    }

    private function secretsFilePath(): string
    {
        return '' === $this->secretsFile
            ? $this->projectDir.'/'.self::DEFAULT_SECRETS_FILE
            : $this->secretsFile;
    }

    private function upsertEnvLine(string $contents, string $key, string $value): string
    {
        $line = \sprintf('%s="%s"', $key, $value);

        if (1 === preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents)) {
            return (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
        }

        return ('' === $contents ? '' : rtrim($contents, "\n")."\n").$line."\n";
    }
}
