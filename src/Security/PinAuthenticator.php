<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Device;
use App\Entity\User;
use App\Repository\DeviceRepository;
use App\Repository\UserRepository;
use App\Serializer\DevicePresenter;
use App\Service\Auth\PinHasher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentification pseudo + PIN, liée à un appareil.
 *
 * ABSENCE D'ORACLE D'EXISTENCE : la vérification est menée hors du chargement d'utilisateur
 * de Symfony, volontairement. Un `UserBadge` classique lèverait `UserNotFoundException` avant
 * tout calcul de hachage, rendant un pseudo inconnu discernable au temps de réponse. Ici, le
 * même travail Argon2id est effectué dans les deux cas, et le même message est renvoyé.
 */
final class PinAuthenticator extends AbstractAuthenticator
{
    public const string LOGIN_ROUTE = 'api_auth_login';
    public const string SESSION_DEVICE_KEY = 'locket.device_id';

    private const string GENERIC_FAILURE = 'Pseudo ou code incorrect.';
    private const string ATTR_DEVICE = '_locket_device';
    private const string ATTR_DEVICE_STATUS = '_locket_device_status';

    public function __construct(
        private readonly UserRepository $users,
        private readonly DeviceRepository $devices,
        private readonly PinHasher $pinHasher,
        private readonly LoginThrottler $throttler,
        private readonly DevicePresenter $devicePresenter,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return self::LOGIN_ROUTE === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $payload = $this->decodeBody($request);
        $pseudo = \is_string($payload['pseudo'] ?? null) ? trim($payload['pseudo']) : '';
        $pin = \is_string($payload['pin'] ?? null) ? $payload['pin'] : '';
        $deviceId = \is_string($payload['deviceId'] ?? null) ? $payload['deviceId'] : null;

        if ('' === $pseudo || '' === $pin) {
            throw new CustomUserMessageAuthenticationException(self::GENERIC_FAILURE);
        }

        $this->throttler->assertNotThrottled($pseudo, $request->getClientIp());

        // Le verrouillage est interrogé sur le PSEUDO, avant même de savoir s'il correspond à
        // un compte : c'est ce qui rend le 429 indépendant de l'existence de l'utilisateur.
        $this->throttler->assertNotLocked($pseudo);

        $user = $this->users->findOneByPseudo($pseudo);
        $isValid = $this->pinHasher->verify($user, $pin);

        // Un compte désactivé est traité comme inexistant : il ne doit pas être identifiable.
        if (!$isValid || null === $user || $user->isDisabled()) {
            $this->throttler->registerFailure($pseudo, $user);

            throw new CustomUserMessageAuthenticationException(self::GENERIC_FAILURE);
        }

        $this->throttler->registerSuccess($user);
        $device = $this->resolveDevice($user, $deviceId);

        $request->attributes->set(self::ATTR_DEVICE, $device);
        $request->attributes->set(self::ATTR_DEVICE_STATUS, $device?->getStatus()->value ?? 'unknown');

        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_DEVICE_KEY, $device?->getDeviceId());
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        \assert($user instanceof User);

        $device = $request->attributes->get(self::ATTR_DEVICE);
        \assert(null === $device || $device instanceof Device);

        return new JsonResponse([
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'pseudo' => $user->getPseudo(),
                'displayName' => $user->getDisplayName(),
                'isAdmin' => $user->isAdmin(),
            ],
            'device' => null === $device ? null : $this->devicePresenter->present($device),
            'status' => $request->attributes->get(self::ATTR_DEVICE_STATUS),
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof TooManyAttemptsException) {
            return new JsonResponse(
                ['error' => $exception->getMessageKey()],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $exception->getRetryAfterSeconds()],
            );
        }

        return new JsonResponse(['error' => self::GENERIC_FAILURE], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CustomUserMessageAuthenticationException(self::GENERIC_FAILURE);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Un appareil qui appartient à quelqu'un d'autre est traité comme inconnu : confirmer
     * son existence livrerait un moyen de tester des identifiants d'appareils.
     */
    private function resolveDevice(User $user, ?string $deviceId): ?Device
    {
        if (null === $deviceId) {
            return null;
        }

        $device = $this->devices->findOneByDeviceId($deviceId);

        if (null === $device || !$device->getUser()->getId()->equals($user->getId())) {
            return null;
        }

        $device->touch(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        return $device;
    }
}
