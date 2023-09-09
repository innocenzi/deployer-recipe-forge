<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;
use Deployer\Utility\Httpie;

$deployer_vendor = \dirname(\constant('DEPLOYER_DEPLOY_FILE')) . '/vendor/deployer/deployer/';
require $deployer_vendor . 'recipe/laravel.php';
require $deployer_vendor . 'contrib/slack.php';

/**
 * Configures the host and variables from Forge and the environment.
 */
function configure_forge(
    bool $trigger_forge_deployment = false,
    string $deployer_directory = 'deployer',
): void {
    // Defines constants from environment variables
    \define('FORGE_API_KEY', getenv('FORGE_API_KEY'));
    \define('SLACK_WEBHOOK_URL', getenv('SLACK_WEBHOOK_URL'));
    \define('REPOSITORY_BRANCH', getenv('REPOSITORY_BRANCH'));
    \define('REPOSITORY_NAME', strtolower(getenv('REPOSITORY_NAME')));
    \define('RUNNER_ID', getenv('RUNNER_ID'));

    // Calls Forge to obtain host settings
    [$hostname, $remote_user, $site_name, $deploy_path, $current_path, $deployment_url, $forge_site_url] = get_host_settings_from_forge($deployer_directory);

    host($hostname)
        ->setRemoteUser($remote_user)
        ->setDeployPath($deploy_path);

    // Required settings
    set('current_path', $current_path);
    set('use_atomic_symlink', false);
    set('update_code_strategy', 'clone');
    set('repository', sprintf('git@github.com:%s.git', REPOSITORY_NAME));

    // GitHub Actions variables
    set('runner_id', RUNNER_ID);
    set('runner_url', '{{repository_url}}/actions/runs/{{runner_id}}');

    // Other variables variables
    set('site_name', $site_name);
    set('site_url', "https://{$site_name}");
    set('forge_site_url', $forge_site_url);
    set('repository_name', REPOSITORY_NAME);
    set('repository_url', 'https://github.com/{{repository_name}}');
    set('repository_branch', REPOSITORY_BRANCH);
    set('commit_url', '{{repository_url}}/commit/{{commit_short_sha}}');
    set('commit_author', fn () => runLocally('git log -n 1 --pretty=format:"%an"'));
    set('commit_short_sha', fn () => runLocally('git log -n 1 --pretty=format:"%h"'));
    set('commit_text', fn () => runLocally('git log -n 1 --pretty=format:"%s"'));
    set('slack_notifications_enabled', fn () => !empty(SLACK_WEBHOOK_URL));

    // If there is a directory where the symlink should be, we consider it to be
    // the initial site deployed from Forge, so we delete it.
    before('deploy:setup', function () {
        if (test('[ ! -L {{current_path}} ] && [ -d {{current_path}} ]')) {
            warning('Deleting site directory created by the initial Forge installation ({{current_path}})');
            run('rm -rf {{current_path}}');
        }
    });

    // Overrides `deploy:info` with useful informations
    task('deploy:info', function () {
        info('Hostname: {{remote_user}}@{{hostname}}');
        info('Site path: {{current_path}}');
        info('Deploy path: {{deploy_path}}');
        info('Repository branch: {{repository_branch}}');
        info('Repository name: {{repository_url}}');
        info('Commit: {{commit_url}}');
        info('Slack notifications: {{slack_notifications_enabled}}');
        info('Forge deployments: {{forge_deployments_enabled}}');
    });

    // Forge deployments
    set('forge_deployments_enabled', function () use ($trigger_forge_deployment, $deployment_url) {
        if (!$trigger_forge_deployment) {
            return false;
        }

        if (!$deployment_url) {
            warning('No deployment URL specified for this site.');

            return false;
        }

        return true;
    });

    after('deploy:success', function () use ($deployment_url) {
        if (!get('forge_deployments_enabled')) {
            return;
        }

        info("Pinging Forge deployment URL: {$deployment_url}");
        call_forge($deployment_url);
    });

    if (get('slack_notifications_enabled')) {
        // Configures slack integration
        set('slack_webhook', SLACK_WEBHOOK_URL);
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
}

/**
 * Makes a Forge-authenticated GET call to the given URL.
 */
function call_forge(string $url): mixed
{
    if (!FORGE_API_KEY) {
        throw new ConfigurationException('The FORGE_API_KEY environment variable is required.');
    }

    return Httpie::get($url)
        ->header('Authorization', 'Bearer ' . FORGE_API_KEY)
        ->send();
}

/**
 * Makes an authenticated GET call to the given Forge API endpoint.
 */
function call_forge_api(string $endpoint): array
{
    return json_decode(
        json: call_forge("https://forge.laravel.com/api/v1/{$endpoint}"),
        associative: true,
    );
}

/**
 * Fetches settings required by Deployer from Forge.
 */
function get_host_settings_from_forge(string $deployer_directory): array
{
    // Since this function is executed on each task call,
    // we cache the settings to avoid repetitive API calls
    if (file_exists('.deployer_cache')) {
        return json_decode(file_get_contents('.deployer_cache'), associative: true);
    }

    ['sites' => $sites] = call_forge_api('sites');

    foreach ($sites as $site) {
        if (!isset($site['repository'], $site['name'])) {
            continue;
        }

        if (strtolower($site['repository']) !== REPOSITORY_NAME) {
            continue;
        }

        if ($site['repository_branch'] !== REPOSITORY_BRANCH) {
            continue;
        }

        ['server' => $server] = call_forge_api("servers/{$site['server_id']}");
        $host = $server['ip_address'];
        $remote_user = $site['username'];
        $site_name = $site['name'];
        $deployment_url = $site['deployment_url'] ?: false;
        $forge_site_url = "https://forge.laravel.com/servers/{$site['server_id']}/sites/{$site['id']}/application";

        file_put_contents('.deployer_cache', json_encode($settings = [
            $host, // hostname/ip
            $remote_user, // user on the server
            $site_name,
            "/home/{$remote_user}/{$deployer_directory}/{$site_name}", // deployer (deploy path)
            "/home/{$remote_user}/{$site_name}", // actual path (current path)
            $deployment_url, // deployment url
            $forge_site_url, // url to access the site on forge
        ]));

        return $settings;
    }

    throw new \Exception('Could not find Forge site for repository [' . REPOSITORY_NAME . ']');
}
