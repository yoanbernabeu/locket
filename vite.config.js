import { defineConfig } from 'vite';
import { resolve } from 'node:path';

/**
 * Deux cibles de build aux contraintes différentes :
 *
 *  - l'application, versionnée par empreinte et servie depuis /build ;
 *  - le service worker, qui DOIT être servi depuis la racine du site sous un nom
 *    stable. Un service worker ne contrôle que les pages situées sous son propre
 *    chemin : depuis /build/, il ne verrait jamais /c/<conversation>.
 *
 * Aucun framework d'interface : l'application tient en deux écrans, et chaque
 * kilo-octet compte pour des grands-parents en 4G. Le rendu est fait à la main,
 * la logique métier vit dans des services purs, déjà couverts par les tests.
 */
export default defineConfig(({ mode }) => {
  const isServiceWorker = mode === 'sw';

  return {
    // Les fichiers statiques (icônes, manifeste) vivent déjà dans public/ et sont
    // servis directement par le serveur : Vite n'a pas à les recopier.
    publicDir: false,
    build: {
      target: 'es2022',
      outDir: isServiceWorker ? 'public' : 'public/build',
      emptyOutDir: !isServiceWorker,
      manifest: !isServiceWorker,
      // Les sourcemaps exposeraient le code non minifié en production, servi de
      // surcroît avec un cache immuable.
      sourcemap: mode === 'development',
      rollupOptions: isServiceWorker
        ? {
            input: resolve(process.cwd(), 'assets/sw/sw.js'),
            output: {
              entryFileNames: 'sw.js',
              format: 'iife',
              inlineDynamicImports: true,
            },
          }
        : {
            input: {
              app: resolve(process.cwd(), 'assets/app/main.js'),
              admin: resolve(process.cwd(), 'assets/admin/admin.js'),
              setup: resolve(process.cwd(), 'assets/setup/setup.js'),
            },
            output: {
              entryFileNames: 'assets/[name]-[hash].js',
              chunkFileNames: 'assets/[name]-[hash].js',
              assetFileNames: 'assets/[name]-[hash][extname]',
            },
          },
    },
  };
});
