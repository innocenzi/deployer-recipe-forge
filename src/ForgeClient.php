<?php

namespace Deployer;

use Deployer\Utility\Httpie;

class ForgeClient
{
    public function __construct(
        private readonly string $token,
    ) {
    }

    public function callForgeEndpoint(string $endpoint): array
    {
        return json_decode(
            json: $this->get("https://forge.laravel.com/api/v1/{$endpoint}"),
            associative: true,
        );
    }

    public function json(string $endpoint): array
    {
        return json_decode(
            json: Httpie::get("https://forge.laravel.com/api/v1/{$endpoint}")
                ->header('Authorization', 'Bearer ' . $this->token)
                ->send(),
            associative: true,
        );
    }

    public function triggerDeployment(string $url): void
    {
        Httpie::post($url)
            ->header('Authorization', 'Bearer ' . $this->token)
            ->send();
    }
}
