<?php

namespace Deployer;

final class Forge
{
    public function __construct(
        private readonly Environment $environment,
        private readonly Configuration $configuration,
        private readonly ForgeClient $forge,
    ) {
    }

    public function configure(): void
    {
        $this->loadRecipes();
        $this->loadSettingsFromForge();
        $this->configureDeployer();
    }

    public static function make(): ForgeRecipeBuilder
    {
        return new ForgeRecipeBuilder();
    }

    private function loadRecipes(): void
    {
        $deployer_vendor = \dirname(\constant('DEPLOYER_DEPLOY_FILE')) . '/vendor/deployer/deployer/';
        require $deployer_vendor . 'recipe/laravel.php';
        require $deployer_vendor . 'contrib/slack.php';
    }

    private function loadSettingsFromForge(): void
    {
        if ($this->configuration->loadFromCache()) {
            return;
        }

        ['sites' => $sites] = $this->forge->json(endpoint: 'sites');

        foreach ($sites as $site) {
            if (!isset($site['repository'], $site['repository_branch'])) {
                continue;
            }

            if (strtolower($site['repository']) !== strtolower($this->environment->repositoryName)) {
                continue;
            }

            if ($site['repository_branch'] !== $this->environment->repositoryBranch) {
                continue;
            }

            ['server' => $server] = $this->forge->json(endpoint: "servers/{$site['server_id']}");
            $this->configuration->hostname = $server['ip_address'];
            $this->configuration->remoteUser = $site['username'];
            $this->configuration->siteName = $site['name'];
            $this->configuration->deployPath = "/home/{$this->configuration->remoteUser}/{$this->configuration->deployerDirectoryName}/{$this->configuration->siteName}";
            $this->configuration->currentPath = "/home/{$this->configuration->remoteUser}/{$this->configuration->siteName}";
            $this->configuration->forgeDeploymentUrl = $site['deployment_url'] ?: false;
            $this->configuration->forgeSiteUrl = "https://forge.laravel.com/servers/{$site['server_id']}/sites/{$site['id']}/application";
            $this->configuration->cache();

            return;
        }

        throw new \Exception("Could not find Forge site for repository [{$this->environment->repositoryName}]");
    }

    private function configureDeployer(): void
    {
        host($this->configuration->hostname)
            ->setRemoteUser($this->configuration->remoteUser)
            ->setDeployPath($this->configuration->deployPath);

        // Required settings
        set('current_path', $this->configuration->currentPath);
        set('use_atomic_symlink', false);
        set('update_code_strategy', 'clone');
        set('repository', sprintf('git@github.com:%s.git', $this->environment->repositoryName));

        // GitHub Actions variables
        set('runner_id', $this->environment->githubRunnerId);
        set('runner_url', '{{repository_url}}/actions/runs/{{runner_id}}');

        // Other variables variables
        set('site_name', $this->configuration->siteName);
        set('site_url', "https://{$this->configuration->siteName}");
        set('forge_site_url', $this->configuration->forgeSiteUrl);
        set('repository_name', $this->environment->repositoryName);
        set('repository_url', 'https://github.com/{{repository_name}}');
        set('repository_branch', $this->environment->repositoryBranch);
        set('commit_url', '{{repository_url}}/commit/{{commit_short_sha}}');
        set('commit_author', fn () => runLocally('git log -n 1 --pretty=format:"%an"'));
        set('commit_short_sha', fn () => runLocally('git log -n 1 --pretty=format:"%h"'));
        set('commit_text', fn () => runLocally('git log -n 1 --pretty=format:"%s"'));

        $this->configureSiteDirectoryDeletion();
        $this->improveDeployInfoTask();
        $this->configureDeploymentTriggerOnForge();
        $this->configureSlackNotifications();
    }

    private function configureSiteDirectoryDeletion(): void
    {
        // If there is a directory where the symlink should be, we consider it to be
        // the initial site deployed from Forge, so we delete it.
        before('deploy:setup', function () {
            if (test('[ ! -L {{current_path}} ] && [ -d {{current_path}} ]')) {
                warning('Deleting site directory created by the initial Forge installation ({{current_path}})');
                run('rm -rf {{current_path}}');
            }
        });
    }

    private function improveDeployInfoTask(): void
    {
        task('deploy:info', function () {
            info('Hostname: {{remote_user}}@{{hostname}}');
            info('Site path: {{current_path}}');
            info('Deploy path: {{deploy_path}}');
            info('Repository branch: {{repository_branch}}');
            info('Repository name: {{repository_url}}');
            info('Commit: {{commit_url}}');
            info('Slack notifications: ' . ($this->shouldSendSlackNotifications() ? 'yes' : 'no'));
            info('Triggers deployments on Forge: ' . ($this->shouldTriggerDeploymentsOnForge() ? 'yes' : 'no'));
        });
    }

    private function configureDeploymentTriggerOnForge(): void
    {
        if ($this->shouldTriggerDeploymentsOnForge()) {
            return;
        }

        after('deploy:success', function () {
            info("Pinging Forge deployment URL: {$this->configuration->forgeDeploymentUrl}");
            $this->forge->triggerDeployment($this->configuration->forgeDeploymentUrl);
        });
    }

    private function configureSlackNotifications(): void
    {
        if (!$this->shouldSendSlackNotifications()) {
            return;
        }

        set('slack_webhook', $this->environment->slackWebhookUrl);
        set('slack_title', '<{{repository_url}}|{{repository_name}}> ({{repository_branch}})');
        set('slack_text', implode("\n", [
            '*{{commit_author}}* is deploying to <{{site_url}}> (<{{forge_site_url}}|see on Forge>)',
            '*Workflow*: <{{runner_url}}|see on GitHub>',
            '*Commit*: _{{commit_text}}_ (<{{commit_url}}|`{{commit_short_sha}}`>)',
        ]));
        set('slack_success_text', 'Deployment successful.');
        set('slack_failure_text', 'Deployment failed.');
        set('slack_rollback_text', '*{{user}}* rolled back last deployment ({{rollback_name}}).');
        set('slack_color', '#38bdf8');
        set('slack_success_color', '#34d399');
        set('slack_failure_color', '#f87171');
        set('slack_rollback_color', '#fb923c');

        // Configures notifications
        before('deploy', 'slack:notify');
        after('deploy:success', 'slack:notify:success');
        after('deploy:failed', 'slack:notify:failure');
        after('rollback', 'slack:notify:rollback');
    }

    private function shouldTriggerDeploymentsOnForge(): bool
    {
        if (!$this->configuration->triggersDeploymentsOnForge) {
            return false;
        }

        if (!$this->configuration->forgeDeploymentUrl) {
            return false;
        }

        return true;
    }

    private function shouldSendSlackNotifications(): bool
    {
        return !empty($this->environment->slackWebhookUrl);
    }
}
