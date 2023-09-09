<h2 align="center">Forge recipe for Deployer</h2>

<p align="center">
  <a href="https://packagist.org/packages/innocenzi/deployer-recipe-forge">
    <img alt="release version" src="https://img.shields.io/github/v/release/innocenzi/deployer-recipe-forge?include_prereleases">
  </a>
  <br />
  <br />
  <p align="center">
    Seamless zero-downtime deployment on Forge with Deployer
  </p>
  <pre><div align="center">composer require --dev innocenzi/deployer-recipe-forge</div></pre>
</p>

&nbsp;

# About

`deployer-recipe-forge` is a recipe for [Deployer](https://deployer.org/) that helps implementing zero-downtime on [Laravel Forge](https://forge.laravel.com/servers).

It  uses [Forge's API](https://forge.laravel.com/api-documentation) to fetch a site's credentials, such as its IP address and the remote username, so you don't have to hardcode them in your `deploy.php`. It automatically finds the right Forge site using your repository's name, so the only configuration to do happens on Forge.

Additionally, it's able to send notifications on Slack with either [Deployer's integration](https://deployer.org/docs/7.x/contrib/slack), which requires that you setup a webhook, or by pinging [Forge's deployment URL](https://forge.laravel.com/docs/sites/deployments.html#using-deployment-triggers).

&nbsp;

# Installation

Install the package as a development dependency:

```bash
composer require --dev innocenzi/deployer-recipe-forge
```

&nbsp;

Then, create a `deploy.php` file at the root of your project. Import the recipe and call `configure_forge()`. You may then customize your deployment script as usual with Deployer.

```php
namespace Deployer;

// This is required
require 'vendor/innocenzi/deployer-recipe-forge/forge.php';
configure_forge();

// This is your custom deployment script
after('artisan:migrate', 'artisan:optimize');
after('artisan:migrate', 'artisan:queue:restart');

after('deploy:vendors', function () {
    set('bin/bun', fn () => which('bun'));
    run('cd {{release_path}} && {{bin/bun}} i');
    run('cd {{release_path}} && {{bin/bun}} run build');
});

after('deploy:failed', 'deploy:unlock');
```

&nbsp;

# Usage

## Setting up the repository

This recipe is designed to be used within a GitHub workflow, using the [action provided by Deployer](https://github.com/deployphp/action).

Setting it up requires at least two [repository secrets](https://docs.github.com/en/actions/security-guides/using-secrets-in-github-actions#creating-secrets-for-a-repository):
- Your server's _private_ SSH key, which will be used by `deployphp/action` to SSH
- Your [Forge API token](https://forge.laravel.com/docs/accounts/api.html), which will be used by the recipe to fetch your site and server's credentials

These repository secrets must then be forwarded to the action as environment variables, or, for the SSH key, as the `private-key` argument:

```yaml
- uses: deployphp/action@v1
  with:
    private-key: ${{ secrets.PRIVATE_SSH_KEY }}
    dep: deploy
  env:
    FORGE_API_KEY: ${{ secrets.FORGE_API_KEY }}
    REPOSITORY_BRANCH: ${{ github.ref_name }}
    REPOSITORY_NAME: ${{ github.repository }}
```

&nbsp;

> [!WARNING]
> Note that the `REPOSITORY_BRANCH` and `REPOSITORY_NAME` variables are required for the recipe to work, since they are used to match your site and branch to your site Forge.

> [!NOTE]
> For convenience, if you are using a paid GitHub plan, you may setup your Forge API token and Slack webhook as [organization-wide secrets](https://docs.github.com/en/codespaces/managing-codespaces-for-your-organization/managing-secrets-for-your-repository-and-organization-for-github-codespaces#adding-secrets-for-an-organization).

&nbsp;

## Setting up Forge

### Creating a new site

The following steps explain how to setup a new site on Forge to work with this recipe on GitHub Actions.

1. Create an [isolated](https://forge.laravel.com/docs/sites/user-isolation.html#overview) site.

	 > Currently, this recipe only works with isolated Forge sites. You must then create an isolated site.

2. Install the repository using the [GitHub integration](https://forge.laravel.com/docs/sites/the-basics.html#git-repository).
   
	 > You may uncheck "install composer dependencies" to make the installation faster, as the site directory will be erased anyway. <br />
	 > If you haven't [associated the server's deploy key with your GitHub repository](https://forge.laravel.com/docs/servers/ssh.html#server-ssh-key-git-project-access), make sure that you do or that you [create a deploy key for this site](https://forge.laravel.com/docs/servers/ssh.html#deploy-keys).

3. SSH into the server to copy its public key (`~/.ssh/id_rsa.pub`) and add it to the [server's authorized keys](https://forge.laravel.com/docs/accounts/ssh.html#adding-ssh-key-to-existing-servers) on Forge.

   > This is the first step to allow the Deployer action to SSH into the server.

4. Additionally, add the server's public key (`~/.ssh/id_rsa.pub`) to the [repository's deploy keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys#deploy-keys).
   
	 > This will allow Deployer to clone the repository from its SSH session on the server.

5. Finally, SSH into the server to copy its private key (`~/.ssh/id_rsa`) and add it to the [repository's secrets](https://docs.github.com/en/actions/security-guides/using-secrets-in-github-actions#creating-secrets-for-a-repository).
   
   > This is the last step to allow the Deployer action to SSH into the server. <br />
	 > We suggest naming the secret after the target environment. For instance, if you are creating a staging site, it may be named `STAGING_SSH_KEY`.

6. You may then trigger the deployment workflow.

&nbsp;

### Adding to an existing site

First, make sure the website is isolated. It must be located at `/home/<isolated-username>/<site-name>` on the server. Otherwise, you must create a [new isolated site](#creating-a-new-site).

You must first **backup your `.env` file and `storage` directory**, since Deployer will delete the site's directory to replace it with a symlink.

After backing them up, you may follow steps 3 to 6. Once the deployment is complete, you may restore your `.env` and `storage` in `/home/<isolated-username>/deployer/<site-name>/shared`.

&nbsp;

> [!WARNING]
> Proceed with caution. Make sure to put the site on maintenance and think of the potential side-effects that may happen during this migration.

&nbsp;

## Slack notifications

### Using Deployer

This recipe supports sending Slack notifications using a webhook. To create it, you may follow [Slack's documentation](https://api.slack.com/messaging/webhooks) on the topic. You must then add the webhook as a repository or organization secret, and forward it to the action as an environment variable.

```yaml
- uses: deployphp/action@v1
  with:
    private-key: ${{ secrets.PRIVATE_SSH_KEY }}
    dep: deploy
  env:
    # ...
    # Add these two
    SLACK_WEBHOOK_URL: ${{ secrets.SLACK_WEBHOOK_URL }}
    RUNNER_ID: ${{ github.run_id }}
```

> [!NOTE]
> Note that `RUNNER_ID` is required, because the Slack notifications link to the associated GitHub workflow.

&nbsp;

### Using Forge

Alternatively, you may [set up Slack notifications on Forge](https://forge.laravel.com/docs/sites/deployments.html#slack), and trigger a deployment on Forge. This way, you also get to use Forge's deployment history.

To trigger the deployment on Forge, simply set `trigger_forge_deployment` to `true`:

```php
configure_forge(trigger_forge_deployment: true);
```

> [!WARNING]
> When using this strategy, make sure to empty the default deployment script configured by Forge.

&nbsp;

## Multiple environments

This recipe supports deploying to multiple environments, such as staging or production, without any specific configuration, other than adding the different private SSH keys to your repository secrets.

It works by associating the repository branch to the one defined on the Forge site. For instance, you may have a `main` branch associated to `example.com`, and a `develop` branch associated to `staging.example.com`.

&nbsp;

### For two environments

The private SSH key must be provided to the `private-key` argument, for instance using a conditional, as follows:

```yaml
- uses: deployphp/action@v1
  with:
    private-key: ${{ github.ref_name == 'develop' && secrets.STAGING_SSH_KEY || secrets.PRODUCTION_SSH_KEY }}
    dep: deploy
  env:
    # ...
```

&nbsp;

### For more environments

If you have more than two environments, you may simply duplicate the actions and use an [`if` statement](https://docs.github.com/en/actions/using-jobs/using-conditions-to-control-job-execution) to ensure they run for the correct branches:

```yaml
steps:
  - name: Deploy (staging)
    if: github.ref_name == 'develop'
    uses: deployphp/action@v1
    with:
      private-key: ${{ secrets.STAGING_SSH_KEY }}
      dep: deploy
    # ...

  - name: Deploy (production)
    if: github.ref_name == 'main'
    uses: deployphp/action@v1
    with:
      private-key: ${{ secrets.PRODUCTION_SSH_KEY }}
      dep: deploy
    # ...
```

&nbsp;

## Example workflow

This workflow deploys on production or staging, depending on the branch that pushed, after running the `test.yml` and `style.yml` workflows. It will skip deployments if the commit body contains `[skip deploy]`, and will notify about deployments on Slack.

Additionally, you may [dispatch the workflow manually](https://docs.github.com/en/actions/using-workflows/manually-running-a-workflow).

```yaml
name: Deploy

on:
  workflow_dispatch:
  push:
    branches: [main, develop]

concurrency:
  group: ${{ github.ref }}

jobs:
  test:
    uses: ./.github/workflows/test.yml

  style:
    uses: ./.github/workflows/style.yml

  deploy:
    needs: [test, style]
    if: ${{ !contains(github.event.head_commit.message, '[skip deploy]') }}
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2

      - uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - uses: deployphp/action@v1
        with:
          private-key: ${{ github.ref_name == 'develop' && secrets.STAGING_SSH_KEY || secrets.PRODUCTION_SSH_KEY }}
          dep: deploy
        env:
          FORGE_API_KEY: ${{ secrets.FORGE_API_KEY }}
          SLACK_WEBHOOK_URL: ${{ secrets.SLACK_WEBHOOK_URL }}
          REPOSITORY_BRANCH: ${{ github.ref_name }}
          REPOSITORY_NAME: ${{ github.repository }}
          RUNNER_ID: ${{ github.run_id }}
```

&nbsp;

## Using `dep` locally

Since the recipe depends on multiple environment variables, you cannot easily call `vendor/bin/dep` locally.

You may work around that limitation by adding `FORGE_API_KEY` to your `.env`, as well as `REPOSITORY_NAME` and `REPOSITORY_BRANCH`:

```dotenv
FORGE_API_KEY="abc...123"
REPOSITORY_NAME="yourorg/repo"
REPOSITORY_BRANCH="develop"
```

Then, you may add the following script to `composer.json`, and run `composer dep` instead of `vendor/bin/dep`:

```json
"dep": "set -o allexport && source ./.env && set +o allexport && vendor/bin/dep"
```

> [!WARNING]
> Using `dep` locally will create a `.deployer_cache` file, which you should add to `.gitignore`.

> [!NOTE]
> If you get denied SSH access, make sure to [add your public key on your server](https://forge.laravel.com/docs/accounts/ssh.html#adding-ssh-key-to-existing-servers) and associate it to your site's user.

&nbsp;

# Q&A

**Why not [Envoyer](https://envoyer.io/)?**
> Envoyer's integration with Forge removes the convenience of being able to manage everything within Forge directly. I forces us to juggle between two different sites, and we also have to pay more. We also believe zero-dowtime deployments should be built in Forge directly.

**Why are issues closed?**
> This recipe is mostly used internally at Jetfly, and we are not willing to do support for it nor add many features. Pull requests for fixes and small additions may be welcome, though.
