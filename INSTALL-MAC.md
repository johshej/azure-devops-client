# Installing `ado` on macOS

`ado` is a plain PHP CLI (Symfony Console) with no Linux-specific dependencies, so it runs unmodified on macOS. It needs PHP 8.4+, Composer, and an Azure DevOps Personal Access Token (PAT).

## 1. Install PHP and Composer

```sh
brew install php composer
php -v        # confirm 8.4.x or newer
```

## 2. Get the code

Clone the repo (private, so you need access — either your SSH key added to GitHub, or the `gh` CLI):

```sh
git clone git@github.com:johshej/azure-devops-client.git ~/projects/azure.devops
# or, via the gh CLI if you don't have an SSH key set up on this machine:
gh repo clone johshej/azure-devops-client ~/projects/azure.devops
```

## 3. Install dependencies

```sh
cd ~/projects/azure.devops
composer install
```

This creates `vendor/`, which the `ado` script requires.

## 4. Put `ado` on your PATH

Symlink it into a directory already on `PATH`. On Apple Silicon, Homebrew's `/opt/homebrew/bin` is on `PATH` by default:

```sh
ln -s ~/projects/azure.devops/ado /opt/homebrew/bin/ado
```

On an Intel Mac, use `/usr/local/bin` instead:

```sh
ln -s ~/projects/azure.devops/ado /usr/local/bin/ado
```

Verify:

```sh
ado --version
```

## 5. Configure

You'll need a PAT from Azure DevOps: **User Settings → Personal Access Tokens → New Token**. Grant at least **Work Items (Read, write, & manage)**; add **Project and Team (Read)** if you hit permission errors on `ado projects`/`ado teams`/`ado sprints`.

```sh
ado config --pat=<your-pat> --org=<your-org-name>
```

(Omit `--pat`/`--org` to be prompted interactively, with the PAT input hidden.) This tests the connection, lists your projects, and lets you pick a default project. Config is saved to `~/.ado/config.json` (mode `0700`).

## 6. Verify

```sh
ado ls --mine --limit=200 --state=Active
```

If that lists your active work items, you're set.
