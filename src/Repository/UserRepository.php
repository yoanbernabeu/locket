<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @implements UserProviderInterface<User>
 */
class UserRepository extends ServiceEntityRepository implements UserProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByPseudo(string $pseudo): ?User
    {
        return $this->findOneBy(['pseudo' => $pseudo]);
    }

    public function findOneById(Uuid $id): ?User
    {
        return $this->find($id);
    }

    /**
     * @return list<User>
     */
    public function findAllOrderedByPseudo(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.pseudo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rechargé à chaque requête par le pare-feu : un membre désactivé perd sa session
     * immédiatement, sans avoir à l'invalider explicitement.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Utilisateur non pris en charge : « %s ».', $user::class));
        }

        $fresh = $this->find($user->getId());

        if (null === $fresh || $fresh->isDisabled()) {
            throw new UserNotFoundException();
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findOneByPseudo($identifier);

        if (null === $user || $user->isDisabled()) {
            throw new UserNotFoundException();
        }

        return $user;
    }
}
