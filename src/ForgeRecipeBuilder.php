<?php

namespace Deployer;

final class ForgeRecipeBuilder
{
    public function __construct(
        public Configuration $configuration = new Configuration(),
    ) {
    }

    public function configure(): void
    {
        $forge = new Forge(
            environment: $environment = Environment::initialize(),
            configuration: $this->configuration,
            forge: new ForgeClient($environment->forgeApiKey),
        );

        $forge->configure();
    }

    /**
     * Disables the cache. Not recommended.
     */
    public function withoutCache(): self
    {
        $this->configuration->disableCache = true;

        return $this;
    }

    /**
     * Sets the directory that contains deployments on the server.
     * @default deployer
     */
    public function setDeployerDirectory(string $name): self
    {
        $this->configuration->deployerDirectoryName = $name;

        return $this;
    }

    /**
     * Defines whether Forge deployments should be triggered when a deployment is successful.
     */
    public function triggerDeploymentsOnForge(): self
    {
        $this->configuration->triggersDeploymentsOnForge = true;

        return $this;
    }
}
