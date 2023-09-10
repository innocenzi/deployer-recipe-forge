<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;

final class Environment
{
    public readonly string $forgeApiKey;
    public readonly string $repositoryBranch;
    public readonly string $repositoryName;
    public readonly ?string $githubRunnerId;
    public readonly ?string $slackWebhookUrl;

    private function __construct()
    {
        if (!\defined('DEPLOYER')) {
            throw new \Exception('This class is not meant to be called outside of a Deployer script.');
        }

        $this->forgeApiKey = $this->getEnvironmentVariable('FORGE_API_KEY', required: true);
        $this->repositoryName = $this->getEnvironmentVariable('REPOSITORY_NAME', required: true);
        $this->repositoryBranch = $this->getEnvironmentVariable('REPOSITORY_BRANCH', required: true);
        $this->githubRunnerId = $this->getEnvironmentVariable('RUNNER_ID', required: false);
        $this->slackWebhookUrl = $this->getEnvironmentVariable('SLACK_WEBHOOK_URL', required: false);
    }

    public static function initialize(): self
    {
        return new self();
    }

    private function getEnvironmentVariable(string $name, bool $required): mixed
    {
        if (!getenv($name) && $required === true) {
            throw new ConfigurationException("The [{$name}] environment variable is required.");
        }

        return getenv($name);
    }
}
