<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Prérogatives d'administration.
 *
 * RÈGLE MÉTIER : un administrateur ne peut ni retirer ni désactiver un autre administrateur.
 * Cela couvre aussi son propre compte, puisqu'il en est un — un admin ne peut donc pas se
 * retirer par mégarde et laisser l'instance sans personne pour la gérer. Le retrait d'un
 * admin se fait hors application, en base.
 *
 * @extends Voter<string, User|null>
 */
final class AdminVoter extends Voter
{
    public const string MANAGE = 'LOCKET_ADMIN';
    public const string DISABLE_MEMBER = 'LOCKET_DISABLE_MEMBER';
    public const string PROMOTE_MEMBER = 'LOCKET_PROMOTE_MEMBER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::MANAGE => true,
            self::DISABLE_MEMBER, self::PROMOTE_MEMBER => $subject instanceof User,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User || $actor->isDisabled() || !$actor->isAdmin()) {
            return false;
        }

        return match ($attribute) {
            self::MANAGE => true,
            self::DISABLE_MEMBER => $subject instanceof User && !$subject->isAdmin(),
            // Promouvoir un membre actif est permis ; le refus des cas déjà-admin ou retiré
            // relève de la règle métier (MemberRoleService), pas de l'autorisation.
            self::PROMOTE_MEMBER => $subject instanceof User,
            default => false,
        };
    }
}
