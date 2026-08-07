import js from '@eslint/js';

export default [
  {
    ignores: ['public/build/**', 'public/sw.js', 'node_modules/**', 'vendor/**'],
  },
  js.configs.recommended,
  {
    files: ['assets/**/*.js'],
    languageOptions: {
      ecmaVersion: 2023,
      sourceType: 'module',
      globals: {
        window: 'readonly',
        document: 'readonly',
        navigator: 'readonly',
        globalThis: 'readonly',
        crypto: 'readonly',
        indexedDB: 'readonly',
        IDBKeyRange: 'readonly',
        fetch: 'readonly',
        Blob: 'readonly',
        File: 'readonly',
        FileReader: 'readonly',
        URL: 'readonly',
        TextEncoder: 'readonly',
        TextDecoder: 'readonly',
        DataView: 'readonly',
        Uint8Array: 'readonly',
        ArrayBuffer: 'readonly',
        atob: 'readonly',
        btoa: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        setInterval: 'readonly',
        clearInterval: 'readonly',
        EventSource: 'readonly',
        Notification: 'readonly',
        MediaRecorder: 'readonly',
        createImageBitmap: 'readonly',
        caches: 'readonly',
        self: 'readonly',
        Response: 'readonly',
        Request: 'readonly',
        CSS: 'readonly',
        console: 'readonly',
      },
    },
    rules: {
      'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      eqeqeq: ['error', 'smart'],
      'no-var': 'error',
      'prefer-const': 'error',
    },
  },
  {
    // Interdiction stricte de journaliser dans la couche cryptographique : une
    // trace de débogage y exposerait des clés ou du contenu en clair.
    files: ['assets/crypto/**/*.js'],
    rules: {
      'no-console': 'error',
    },
  },
  {
    // Le service worker s'exécute dans un contexte global différent de la page.
    files: ['assets/sw/**/*.js'],
    languageOptions: {
      globals: { clients: 'readonly', registration: 'readonly', skipWaiting: 'readonly' },
    },
  },
  {
    files: ['assets/**/*.test.js'],
    languageOptions: {
      globals: { describe: 'readonly', it: 'readonly', expect: 'readonly', vi: 'readonly', beforeEach: 'readonly', beforeAll: 'readonly', afterEach: 'readonly' },
    },
  },
];
