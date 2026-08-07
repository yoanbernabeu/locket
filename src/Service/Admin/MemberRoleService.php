<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Attribution du rôle d'administrateur.
 *
 * POURQUOI cet endpoint existe : le cahier des charges demande qu'une famille puisse avoir
 * deux parents administrateurs. Le premier admin naît à l'installation ; sans promotion, il
 * n'existait aucun moyen d'en créer un second, et `User::setAdmin()` restait du code mort. Un
 * compte unique signifie aussi qu'un téléphone perdu laisse l'instance sans personne pour la
 * gérer — or AdminVoter interdit précisément de retirer un admin par l'application.
 *
 * La rétrogradation n'est volontairement PAS offerte, par symétrie avec
 * AdminVoter::DISABLE_MEMBER : un admin ne peut pas retirer ses prérogatives à un autre.
 */
final class MemberRoleService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function promoteToAdmin(User $target): void
    {
        if ($target->isDisabled()) {
            throw new ForbiddenException('Un membre retiré ne peut pas être promu administrateur.');
        }

        if ($target->isAdmin()) {
            throw new ConflictException('Ce membre est déjà administrateur.');
        }

        $target->setAdmin(true);
        $this->entityManager->flush();
    }
}
