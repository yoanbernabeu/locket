/**
 * Parcours de bout en bout dans de vrais navigateurs.
 *
 * Ce niveau de test existe pour une raison précise : il est le SEUL à pouvoir
 * vérifier ce qui se passe entre deux personnes distinctes, sur deux appareils
 * distincts, avec de vraies clés. Les tests unitaires simulent un seul côté ;
 * ici, on vérifie que deux navigateurs isolés parviennent réellement à se lire.
 *
 * Chaque contexte de navigateur possède son propre IndexedDB, donc sa propre
 * identité cryptographique — exactement comme deux téléphones différents.
 */

import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

const PIN_ADMIN = '424242';
const PIN_MEMBRE = '131313';

/** Crée l'instance et le compte administrateur au premier lancement. */
async function installer(page, { famille, prenom, pseudo }) {
  await page.goto('/setup');
  await page.fill('#instanceName', famille);
  await page.fill('#displayName', prenom);
  await page.fill('#pseudo', pseudo);
  await page.fill('#email', `${pseudo}@example.test`);
  await page.fill('#pin', PIN_ADMIN);
  await page.click('button[type=submit]');
  await page.waitForURL(/\/(c\/.*)?$/);
}

async function seConnecter(page, pseudo, pin) {
  await page.goto('/');
  await page.fill('#pseudo', pseudo);
  await page.fill('#pin', pin);
  await page.click('button[type=submit]');
}

async function ouvrirGroupe(page) {
  await page.click('[data-action="ouvrir-conversation"]');
  await expect(page.locator('#composeur')).toBeVisible();
}

async function envoyer(page, texte) {
  await page.fill('#saisie', texte);
  await page.click('#composeur button[type=submit]');
  await expect(page.locator('.bulle', { hasText: texte })).toBeVisible();
}

test.describe('installation et premier usage', () => {
  test("l'assistant crée l'instance et ouvre le groupe familial", async ({ page }) => {
    await installer(page, { famille: 'Famille Test', prenom: 'Papa', pseudo: `papa-${randomUUID().slice(0, 8)}` });

    await expect(page.locator('.conversation-nom')).toContainText(/famille/i);
  });

  test('un code secret erroné est refusé sans révéler si le compte existe', async ({ page }) => {
    await seConnecter(page, 'inconnu-xyz', '999999');
    const messageInconnu = await page.locator('.message-erreur').textContent();

    await seConnecter(page, 'papa', '000000');
    const messagePinFaux = await page.locator('.message-erreur').textContent();

    // Les deux messages doivent être identiques : sinon, on dispose d'un oracle
    // permettant d'énumérer les comptes existants.
    expect(messageInconnu).toBe(messagePinFaux);
  });
});

test.describe('conversation entre deux personnes', () => {
  test('un message chiffré chez A est lisible chez B, et le serveur ne le voit pas', async ({ browser, request }) => {
    const suffixe = randomUUID().slice(0, 8);
    const pseudoAdmin = `papa-${suffixe}`;
    const pseudoMembre = `mamie-${suffixe}`;

    // --- Appareil 1 : l'administrateur installe et invite ---
    const contexteA = await browser.newContext();
    const pageA = await contexteA.newPage();
    await installer(pageA, { famille: 'Famille Dupont', prenom: 'Papa', pseudo: pseudoAdmin });

    await pageA.goto('/admin');
    await pageA.fill('#invite-displayName', 'Mamie');
    await pageA.fill('#invite-pseudo', pseudoMembre);
    await pageA.click('#formulaire-invitation-admin button[type=submit]');

    const lien = await pageA.inputValue('#lien-invitation');
    expect(lien).toContain('/j#');

    // --- Appareil 2 : un navigateur totalement distinct rejoint ---
    const contexteB = await browser.newContext();
    const pageB = await contexteB.newPage();
    await pageB.goto(lien);
    await pageB.fill('#displayName', 'Mamie');
    await pageB.fill('#pin', PIN_MEMBRE);
    await pageB.fill('#pinConfirm', PIN_MEMBRE);
    await pageB.click('button[type=submit]');
    await expect(pageB.locator('.conversation')).toBeVisible({ timeout: 15000 });

    // --- Échange réel ---
    await ouvrirGroupe(pageB);
    const secret = `Bonjour les enfants ${suffixe}`;
    await envoyer(pageB, secret);

    await pageA.goto('/');
    await ouvrirGroupe(pageA);
    await expect(pageA.locator('.bulle', { hasText: secret })).toBeVisible({ timeout: 20000 });

    // --- Le serveur ne doit rien pouvoir en tirer ---
    // On relit le message par l'API en tant qu'administrateur : il ne doit
    // apparaître que sous forme d'enveloppe chiffrée.
    const conversations = await (await pageA.request.get('/api/conversations')).json();
    const reponse = await pageA.request.get(`/api/conversations/${conversations[0].id}/messages?since=0`);
    const brut = await reponse.text();
    expect(brut).not.toContain(secret);

    await contexteA.close();
    await contexteB.close();
  });

  test('une réaction décore le message sans créer de nouvelle ligne', async ({ page }) => {
    const pseudo = `papa-${randomUUID().slice(0, 8)}`;
    await installer(page, { famille: 'Famille Réaction', prenom: 'Papa', pseudo });
    await ouvrirGroupe(page);
    await envoyer(page, 'Photo des vacances');

    await page.click('[data-action="reagir"]');
    await page.click('.barre-reponse [data-emoji="❤️"]');

    await expect(page.locator('.reaction')).toContainText('❤️ 1');
    await expect(page.locator('.ligne-message')).toHaveCount(1);
  });

  test('une réponse citée affiche le message d\'origine', async ({ page }) => {
    const pseudo = `papa-${randomUUID().slice(0, 8)}`;
    await installer(page, { famille: 'Famille Citation', prenom: 'Papa', pseudo });
    await ouvrirGroupe(page);

    await envoyer(page, 'Qui apporte le dessert ?');
    await page.click('[data-action="repondre"]');
    await envoyer(page, 'Moi !');

    await expect(page.locator('.citation')).toContainText('Qui apporte le dessert');
  });
});

test.describe('rétention', () => {
  test('chaque message affiche son échéance', async ({ page }) => {
    const pseudo = `papa-${randomUUID().slice(0, 8)}`;
    await installer(page, { famille: 'Famille Durée', prenom: 'Papa', pseudo });
    await ouvrirGroupe(page);
    await envoyer(page, 'Ce message disparaîtra');

    await expect(page.locator('.expiration')).toContainText(/Disparaît dans 7 j/);
  });

  test('un message dont l\'échéance est dépassée disparaît de l\'affichage', async ({ page }) => {
    const pseudo = `papa-${randomUUID().slice(0, 8)}`;
    await installer(page, { famille: 'Famille Purge', prenom: 'Papa', pseudo });
    await ouvrirGroupe(page);
    await envoyer(page, 'Message à faire expirer');

    // On force l'échéance dans le passé directement dans IndexedDB, puis on
    // déclenche la purge en repassant l'onglet au premier plan.
    await page.evaluate(async () => {
      const ouvrir = indexedDB.open('locket');
      const db = await new Promise((r) => (ouvrir.onsuccess = () => r(ouvrir.result)));
      const tx = db.transaction('messages', 'readwrite');
      const store = tx.objectStore('messages');
      const tous = await new Promise((r) => {
        const req = store.getAll();
        req.onsuccess = () => r(req.result);
      });
      for (const message of tous) {
        store.put({ ...message, expiresAt: Date.now() - 1000 });
      }
      await new Promise((r) => (tx.oncomplete = r));
    });

    await page.reload();
    await ouvrirGroupe(page);
    await expect(page.locator('.bulle', { hasText: 'Message à faire expirer' })).toHaveCount(0);
  });
});

test.describe('progressive web app', () => {
  test('le manifeste et le service worker sont servis correctement', async ({ page, request }) => {
    const manifeste = await request.get('/manifest.webmanifest');
    expect(manifeste.ok()).toBeTruthy();
    expect((await manifeste.json()).name).toBe('Locket');

    const sw = await request.get('/sw.js');
    expect(sw.ok()).toBeTruthy();
    // Un service worker mis en cache peut rester obsolète plusieurs jours.
    expect(sw.headers()['cache-control']).toContain('no-cache');
  });

  test('le service worker prend le contrôle de la page', async ({ page }) => {
    const pseudo = `papa-${randomUUID().slice(0, 8)}`;
    await installer(page, { famille: 'Famille PWA', prenom: 'Papa', pseudo });

    const actif = await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.ready;
      return Boolean(registration.active);
    });
    expect(actif).toBe(true);
  });
});
