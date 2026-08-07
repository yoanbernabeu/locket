<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Insère les bundles produits par Vite à partir de son manifeste.
 *
 * Les noms de fichiers portent une empreinte de contenu : on ne peut donc pas
 * les écrire en dur dans les gabarits, sinon chaque build casserait les pages.
 *
 * Le manifeste doit être parcouru RÉCURSIVEMENT : Vite regroupe le code partagé
 * entre points d'entrée dans des chunks communs, et y déplace au passage les
 * feuilles de style. Se contenter des styles déclarés sur l'entrée elle-même
 * livre une page sans mise en forme.
 */
final class ViteExtension extends AbstractExtension
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vite_entry', $this->renderEntry(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Rend les balises d'un point d'entrée.
     *
     * L'ordre importe : les feuilles de style précèdent le script, pour que la
     * page ne s'affiche jamais brute le temps du chargement.
     */
    public function renderEntry(string $entry): string
    {
        $manifest = $this->loadManifest();
        $key = $this->findEntryKey($manifest, $entry);

        if (null === $key) {
            // En développement, un message explicite vaut mieux qu'une page muette.
            return $this->debug
                ? sprintf(
                    '<!-- Point d\'entrée Vite « %s » introuvable. Lancez : npm run build -->',
                    htmlspecialchars($entry, \ENT_QUOTES),
                )
                : '';
        }

        $tags = '';
        foreach ($this->collectStyles($manifest, $key) as $css) {
            $tags .= sprintf('<link rel="stylesheet" href="/build/%s">', htmlspecialchars($css, \ENT_QUOTES));
        }

        return $tags.sprintf(
            '<script type="module" src="/build/%s"></script>',
            htmlspecialchars((string) $manifest[$key]['file'], \ENT_QUOTES),
        );
    }

    /**
     * Retrouve la clé du manifeste correspondant au nom logique demandé.
     * Le chemin source n'est pas prévisible (main.js, admin.js…) : on s'appuie
     * sur le nom du chunk, que Vite dérive du nom du point d'entrée.
     */
    private function findEntryKey(array $manifest, string $entry): ?string
    {
        foreach ($manifest as $key => $chunk) {
            if (($chunk['isEntry'] ?? false) && ($chunk['name'] ?? null) === $entry) {
                return $key;
            }
        }

        return $manifest["assets/{$entry}/{$entry}.js"] ?? null ? "assets/{$entry}/{$entry}.js" : null;
    }

    /**
     * Collecte les feuilles de style du chunk et de tous ses imports.
     *
     * @param array<string, string> $seen protège des cycles d'imports
     *
     * @return string[]
     */
    private function collectStyles(array $manifest, string $key, array &$seen = []): array
    {
        if (isset($seen[$key])) {
            return [];
        }
        $seen[$key] = $key;

        $chunk = $manifest[$key] ?? null;
        if (null === $chunk) {
            return [];
        }

        $styles = $chunk['css'] ?? [];
        foreach ($chunk['imports'] ?? [] as $imported) {
            $styles = array_merge($styles, $this->collectStyles($manifest, $imported, $seen));
        }

        return array_values(array_unique($styles));
    }

    /** @return array<string, array<string, mixed>> */
    private function loadManifest(): array
    {
        // En production, le manifeste n'est lu qu'une fois par processus : avec
        // FrankenPHP en mode worker, le processus vit longtemps.
        if (null !== $this->manifest && !$this->debug) {
            return $this->manifest;
        }

        $path = $this->projectDir.'/public/build/.vite/manifest.json';
        if (!is_file($path)) {
            return $this->manifest = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->manifest = \is_array($decoded) ? $decoded : [];
    }
}
