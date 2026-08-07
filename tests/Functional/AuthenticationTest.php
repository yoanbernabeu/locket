<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Device;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationTest extends ApiTestCase
{
    private User $admin;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $installed = $this->fixtures->install('papa', '123456');
        $this->admin = $installed['user'];
        $this->device = $installed['device'];
    }

    #[TestDox('une connexion valide renvoie l\'utilisateur et son appareil')]
    public function testSuccessfulLogin(): void
    {
        $this->login('papa', '123456', $this->device->getDeviceId());

        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $body = $this->json();
        self::assertSame('papa', $body['user']['pseudo']);
        self::assertTrue($body['user']['isAdmin']);
        self::assertSame('active', $body['status']);
        self::assertSame($this->device->getDeviceId(), $body['device']['deviceId']);
    }

    #[TestDox('un pseudo inexistant et un PIN faux renvoient la même erreur')]
    public function testUnknownPseudoAndWrongPinAreIndistinguishable(): void
    {
        $this->login('papa', '999999');
        $wrongPin = ['status' => $this->httpStatus(), 'body' => $this->json()];

        $this->client->getCookieJar()->clear();

        $this->login('inconnu', '999999');
        $unknownPseudo = ['status' => $this->httpStatus(), 'body' => $this->json()];

        self::assertSame(Response::HTTP_UNAUTHORIZED, $wrongPin['status']);
        self::assertSame($wrongPin['status'], $unknownPseudo['status']);
        self::assertSame($wrongPin['body'], $unknownPseudo['body']);
        self::assertSame(['error' => 'Pseudo ou code incorrect.'], $wrongPin['body']);
    }

    #[TestDox('C3 : un pseudo existant et un pseudo inconnu produisent la même séquence de codes HTTP')]
    public function testLockoutSequenceIsIdenticalForKnownAndUnknownPseudo(): void
    {
        // L'oracle d'origine : le verrouillage vivait sur la ligne du membre, donc n'existait
        // que pour un pseudo existant. Trois 401 suivis d'un 429 signifiaient « ce compte
        // existe » ; une suite de 401 signifiait « il n'existe pas ». Cinq tentatives suffisent
        // à énumérer toute la famille.
        $known = $this->attemptSequence('papa', 5);
        $unknown = $this->attemptSequence('personne-ici', 5);

        self::assertSame($known, $unknown, 'La séquence de codes ne doit rien dire de l\'existence du compte.');
        self::assertContains(Response::HTTP_TOO_MANY_REQUESTS, $known, 'Le verrouillage doit bien finir par s\'appliquer.');
    }

    #[TestDox('C3 : le corps de réponse d\'un 429 est lui aussi identique dans les deux cas')]
    public function testThrottledBodyIsIdenticalForKnownAndUnknownPseudo(): void
    {
        $this->attemptSequence('papa', 4);
        $knownBody = $this->json();

        $this->attemptSequence('personne-ici', 4);
        $unknownBody = $this->json();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->httpStatus());
        self::assertSame($knownBody, $unknownBody);
    }

    #[TestDox('un PIN erroné répété finit par verrouiller le compte')]
    public function testRepeatedWrongPinLocksAccount(): void
    {
        // Le seuil de tolérance est franchi au troisième échec (LockoutPolicy).
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->client->getCookieJar()->clear();
            $this->login('papa', '000000');

            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->httpStatus(), 'Échec n°'.$attempt);
        }

        $this->client->getCookieJar()->clear();
        $this->login('papa', '000000');

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->httpStatus());
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));

        // Le verrou vaut même pour le bon code : c'est ce qui rend l'attaque impraticable.
        $this->client->getCookieJar()->clear();
        $this->login('papa', '123456');

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->httpStatus());
    }

    #[TestDox('une connexion réussie remet le compteur d\'échecs à zéro')]
    public function testSuccessResetsFailureCounter(): void
    {
        $this->login('papa', '000000');
        $this->client->getCookieJar()->clear();
        $this->login('papa', '123456', $this->device->getDeviceId());

        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        // Le conteneur remet les services à zéro entre deux requêtes : on relit le membre
        // depuis son dépôt plutôt que de rafraîchir une instance possiblement détachée.
        $reloaded = $this->users()->findOneByPseudo('papa');
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getFailedAttempts());
        self::assertNull($reloaded->getLockedUntil());
    }

    #[TestDox('un accès sans session renvoie 401 en JSON, jamais une redirection')]
    public function testAnonymousAccessIsRejectedWithJson(): void
    {
        $this->request('GET', '/api/conversations');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->httpStatus());
        self::assertSame(['error' => 'Authentification requise.'], $this->json());
    }

    #[TestDox('la déconnexion invalide la session')]
    public function testLogoutEndsSession(): void
    {
        $this->login('papa', '123456', $this->device->getDeviceId());
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $this->request('POST', '/api/auth/logout');
        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());

        $this->request('GET', '/api/me');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->httpStatus());
    }

    #[TestDox('un membre désactivé ne peut plus se connecter')]
    public function testDisabledMemberCannotLogIn(): void
    {
        $member = $this->fixtures->addMember('mamie', '654321');

        $membership = self::getContainer()->get(\App\Service\Membership\MembershipService::class);
        \assert($membership instanceof \App\Service\Membership\MembershipService);
        $membership->disableMember($member['user']);

        $this->login('mamie', '654321', $member['device']->getDeviceId());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->httpStatus());
        self::assertSame(['error' => 'Pseudo ou code incorrect.'], $this->json());
    }

    #[TestDox('un appareil appartenant à quelqu\'un d\'autre est traité comme inconnu')]
    public function testForeignDeviceIsTreatedAsUnknown(): void
    {
        $member = $this->fixtures->addMember('mamie', '654321');

        $this->login('papa', '123456', $member['device']->getDeviceId());

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('unknown', $this->json()['status']);
        self::assertNull($this->json()['device']);
    }

    /**
     * Suite de codes HTTP renvoyés par N tentatives infructueuses sur un même pseudo.
     *
     * @return list<int>
     */
    private function attemptSequence(string $pseudo, int $attempts): array
    {
        $codes = [];

        for ($i = 0; $i < $attempts; ++$i) {
            $this->client->getCookieJar()->clear();
            $this->login($pseudo, '000000');
            $codes[] = $this->httpStatus();
        }

        return $codes;
    }
}
