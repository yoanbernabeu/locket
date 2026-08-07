<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Message;
use App\Util\Base64Url;

final class MessagePresenter
{
    /**
     * @return array{id: string, seq: int, envelope: string, senderPseudo: string, createdAt: string}
     */
    public function present(Message $message): array
    {
        return [
            'id' => $message->getId()->toRfc4122(),
            'seq' => $message->getServerSeq(),
            'envelope' => Base64Url::encode($message->getEnvelope()),
            'senderPseudo' => $message->getSenderUser()->getPseudo(),
            'createdAt' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(array $messages): array
    {
        return array_map($this->present(...), $messages);
    }
}
