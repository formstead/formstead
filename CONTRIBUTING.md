# Contributing to Formstead

Thank you for helping. This repository is the WordPress plugin root. Keep changes small, in English, and limited to one topic.

## Requirements

- Docker
- Node.js 22 (see `.nvmrc`)
- Git

PHP, Composer, WordPress, MariaDB, PHPUnit, PHPCS, and PHPStan run inside `wp-env` containers. Do not use a host PHP install (including Herd) for this project.

## Local environment

```sh
npm install
npx wp-env start
```

The development site is at http://localhost:8888. Default credentials are `admin` / `password`.

Stop the environment with `npx wp-env stop`. Destroy it, including the database, with `npx wp-env destroy`.

## Tests and quality checks

Commands run in the WordPress environment. The plugin is mounted at `wp-content/plugins/formstead`.

```sh
npx wp-env run tests-cli --env-cwd=wp-content/plugins/formstead vendor/bin/phpunit
npx wp-env run cli --env-cwd=wp-content/plugins/formstead vendor/bin/phpcs
npx wp-env run cli --env-cwd=wp-content/plugins/formstead vendor/bin/phpstan analyse
```

If those paths change, `notes/stage-0-probes.md` records the confirmed container and mount. After Composer dependencies change, install them in the container:

```sh
npx wp-env run cli --env-cwd=wp-content/plugins/formstead composer install
```

PHPUnit, PHPCS, PHPStan, and Plugin Check must pass in CI before a pull request is merged.

## Pull requests

- One topic per pull request.
- Open against `main`.
- Link the GitHub issue with `Closes #n`.
- Do not add domain logic in a scaffolding change, and do not mix refactors with behaviour changes.

## Commits

Write commit messages in English, in the imperative mood, and say why the change exists.

```
Add a network-activation guard so Multisite installs fail closed.
```

## Code

- PHP 8.3+, WordPress 7.0+, text domain `formstead`.
- Register activation and deactivation hooks at the top level of `formstead.php`.
- User-facing strings go through WordPress translation functions.
- Do not log submission contents, secrets, or IP addresses.

## License

Contributions are licensed under GPL-2.0-or-later, the same license as the plugin.
