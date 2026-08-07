// Fournit une implémentation d'IndexedDB en mémoire pour les tests Node.
// Suffisant pour la logique de stockage et de purge ; les comportements propres
// aux navigateurs (quotas, éviction, stockage de Blob, événements « blocked »)
// restent couverts par les tests end-to-end Playwright.
import 'fake-indexeddb/auto';
