<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Support;

class UnifiApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $responseBody = '')
    {
        parent::__construct($message);
    }
}
