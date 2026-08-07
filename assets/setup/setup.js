/**
 * Assistant d'installation.
 *
 * L'administrateur est un membre comme les autres du point de vue
 * cryptographique : son premier appareil génère ses propres clés, qui ne
 * quittent jamais ce navigateur. C'est pourquoi cet écran ne peut pas être un
 * simple formulaire HTML — le serveur n'a aucun moyen de fabriquer ces clés.
 */

import '../app/styles.css';
import { ApiClient } from '../app/api.js';
import { createDeviceIdentity } from '../crypto/identity.js';
import { toBase64Url } from '../crypto/codec.js';
import { openLocketDb } from '../store/db.js';
import { createRepositories } from '../store/repositories.js';
import { bytesToHex } from '../app/api.js';
import { escapeHtml } from '../app/views.js';

const api = new ApiClient();

function afficherErreur(message) {
  const zone = document.querySelector('#zone-erreur');
  zone.innerHTML = `<p class="message-erreur" role="alert">${escapeHtml(message)}</p>`;
  zone.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

document.addEventListener('submit', async (event) => {
  const form = event.target;
  if (form.id !== 'formulaire-installation') return;
  event.preventDefault();

  const pin = form.pin.value;
  if (!/^\d{6,12}$/.test(pin)) {
    return afficherErreur('Le code secret doit contenir entre 6 et 12 chiffres.');
  }

  const bouton = form.querySelector('button[type=submit]');
  bouton.disabled = true;
  bouton.textContent = 'Création en cours…';

  try {
    const db = await openLocketDb();
    const repos = createRepositories(db);

    const identity = await createDeviceIdentity();
    identity.label = 'Premier appareil';
    identity.deviceIdHex = bytesToHex(identity.deviceId);

    const result = await api.post('/setup', {
      json: {
        instanceName: form.instanceName.value.trim(),
        pseudo: form.pseudo.value.trim(),
        displayName: form.displayName.value.trim(),
        email: form.email.value.trim(),
        pin,
        device: {
          deviceId: identity.deviceIdHex,
          ikPub: toBase64Url(identity.ikPub),
          skPub: toBase64Url(identity.skPub),
          label: identity.label,
        },
      },
    });

    // Les clés privées sont conservées AVANT la redirection : les perdre ici
    // rendrait le compte administrateur inutilisable dès sa création.
    await repos.identity.save({
      ...identity,
      userId: result.user.id,
      pseudo: result.user.pseudo,
      displayName: form.displayName.value.trim(),
      isAdmin: true,
      status: 'active',
    });

    // L'installation crée le compte mais n'ouvre pas de session : on connecte
    // l'administrateur dans la foulée, sinon il retomberait sur l'écran de
    // connexion juste après avoir saisi ces mêmes identifiants.
    await api.login({ pseudo: result.user.pseudo, pin, deviceId: identity.deviceIdHex });

    globalThis.location.href = '/';
  } catch (error) {
    afficherErreur(
      error.status === 409
        ? 'Cette instance est déjà installée.'
        : (error.payload?.message ?? "L'installation n'a pas pu aboutir. Vérifiez les champs et réessayez."),
    );
    bouton.disabled = false;
    bouton.textContent = "Créer l'espace familial";
  }
});
