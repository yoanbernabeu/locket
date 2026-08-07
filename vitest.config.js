import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // Deux projets aux contraintes distinctes : la crypto est PURE et tourne en
    // Node nu (ni DOM, ni IndexedDB), ce qui la rend rapide et déterministe.
    // Le stockage a besoin d'une implémentation d'IndexedDB simulée.
    projects: [
      {
        test: {
          name: 'crypto',
          include: ['assets/crypto/**/*.test.js'],
          environment: 'node',
        },
      },
      {
        test: {
          name: 'store',
          include: ['assets/store/**/*.test.js', 'assets/app/**/*.test.js', 'assets/sw/**/*.test.js'],
          environment: 'node',
          setupFiles: ['assets/test/setup-indexeddb.js'],
        },
      },
    ],
  },
});
