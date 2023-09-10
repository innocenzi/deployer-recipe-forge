<?php

namespace Deployer;

final class Configuration
{
    // User configuration
    public string $deployerDirectoryName = 'deployer';
    public bool $triggersDeploymentsOnForge = false;
    public bool $disableCache = false;

    // Forge configuration
    public string $hostname;
    public string $remoteUser;
    public string $siteName;
    public string $deployPath;
    public string $currentPath;
    public string $forgeDeploymentUrl;
    public string $forgeSiteUrl;

    public function __construct()
    {
    }

    public function cache(): void
    {
        file_put_contents('.deployer_cache', json_encode([
            'hostname' => $this->hostname,
            'remoteUser' => $this->remoteUser,
            'siteName' => $this->siteName,
            'deployPath' => $this->deployPath,
            'currentPath' => $this->currentPath,
            'forgeDeploymentUrl' => $this->forgeDeploymentUrl,
            'forgeSiteUrl' => $this->forgeSiteUrl,
        ]));
    }

    public function loadFromCache(): bool
    {
        if (!file_exists('.deployer_cache') || $this->disableCache) {
            return false;
        }

        $cache = json_decode(file_get_contents('.deployer_cache'), associative: true);

        $this->hostname = $cache['hostname'];
        $this->remoteUser = $cache['remoteUser'];
        $this->siteName = $cache['siteName'];
        $this->deployPath = $cache['deployPath'];
        $this->currentPath = $cache['currentPath'];
        $this->forgeDeploymentUrl = $cache['forgeDeploymentUrl'];
        $this->forgeSiteUrl = $cache['forgeSiteUrl'];

        return true;
    }
}
