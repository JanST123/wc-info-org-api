<?php

namespace App\Services;

class AdminLinkService
{
    private string $secret1;
    private string $secret2;

    public function __construct(?string $secret1 = null, ?string $secret2 = null)
    {
        $this->secret1 = $secret1 ?? (string) config('wcinfo.admin_hash_secret');
        $this->secret2 = $secret2 ?? (string) config('wcinfo.admin_hash_secret2');
    }

    public function hash(int $toiletId, string $placeId): string
    {
        return md5($this->secret1 . $toiletId . $placeId . $this->secret2);
    }

    public function verify(int $toiletId, string $placeId, string $hash): bool
    {
        return hash_equals($this->hash($toiletId, $placeId), $hash);
    }
}
