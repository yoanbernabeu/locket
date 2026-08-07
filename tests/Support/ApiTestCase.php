<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base des tests fonctionnels : un client HTTP, une base neuve, un jeu de fixtures.
 *
 * Le redémarrage du noyau entre deux requêtes est désactivé : les tests manipulent des
 * entités entre deux appels, et un noyau relancé les détacherait silencieusement.
 */
abstract class ApiTestCase extends WebTestCase
{
    use DatabaseResetTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected Fixtures $fixtures;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = self::getContainer();
        $this->resetDatabase($container);
        $this->resetMediaDirectory($container);

        $entityManager = $container->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $this->fixtures = new Fixtures($container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function request(string $method, string $uri, ?array $body = null, ?string $rawBody = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $rawBody ?? (null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(): array
    {
        $content = $this->client->getResponse()->getContent();
        \assert(\is_string($content));

        $decoded = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function login(string $pseudo, string $pin, ?string $deviceId = null): void
    {
        $this->request('POST', '/api/auth/login', ['pseudo' => $pseudo, 'pin' => $pin, 'deviceId' => $deviceId]);
    }

    protected function users(): \App\Repository\UserRepository
    {
        $repository = self::getContainer()->get(\App\Repository\UserRepository::class);
        \assert($repository instanceof \App\Repository\UserRepository);

        return $repository;
    }

    protected function logout(): void
    {
        $this->request('POST', '/api/auth/logout');
        $this->client->getCookieJar()->clear();
    }
}
