<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Corps de requête en flux, tel que le contrôleur de médias le passe désormais au domaine.
 */
final class Streams
{
    /**
     * @return resource
     */
    public static function of(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');
        \assert(\is_resource($stream));

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
