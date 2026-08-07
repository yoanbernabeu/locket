<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Conversation;
use App\Entity\User;
use App\Repository\ConversationMemberRepository;
use App\Security\DeviceContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Accès à une conversation.
 *
 * Deux conditions cumulatives, jamais l'une sans l'autre : appartenance ACTIVE (un membre
 * retiré a un `leftAt` non nul et perd immédiatement lecture comme écriture) et appareil
 * ACTIF (un appareil en attente d'appairage ne lit rien).
 *
 * @extends Voter<string, Conversation>
 */
final class ConversationVoter extends Voter
{
    public const string VIEW = 'CONVERSATION_VIEW';
    public const string POST = 'CONVERSATION_POST';

    public function __construct(
        private readonly ConversationMemberRepository $members,
        private readonly DeviceContext $deviceContext,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::POST], true) && $subject instanceof Conversation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || $user->isDisabled()) {
            return false;
        }

        if (!$this->members->isActiveMember($subject, $user)) {
            return false;
        }

        return $this->deviceContext->getDevice()?->isActive() ?? false;
    }
}
