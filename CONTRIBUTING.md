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

The development site is at http://localhost:8888. Default credentials are `admin` / `password`. `wp-env` activates Twenty Twenty-Five after start; WordPress 7.1.3 can otherwise leave `template` set to a missing `default` theme and render an empty front page.

Stop the environment with `npx wp-env stop`. Destroy it, including the database, with `npx wp-env destroy`.

## Mail

Local mail is captured by Mailpit. Start it after `wp-env`:

```sh
npm run mail:up
```

Open http://localhost:8025 to read messages. The development container sends SMTP to the Mailpit container on port 1025. Stop it with `npm run mail:down`.

If 8025 or 1025 is already in use, set `FORMSTEAD_MAILPIT_UI_PORT` and `FORMSTEAD_MAILPIT_SMTP_PORT` before `npm run mail:up`. The container still receives mail on port 1025.

## Tests and quality checks

`npm run check` is the check before you push and before you merge. It runs PHPUnit, PHPCS, PHPStan, and Plugin Check inside `wp-env`. Plugin Check skips development paths that `.distignore` keeps out of the ZIP.

GitHub Actions does not start on a push, a pull request, or a schedule. After merging a stage into `main`, open the Actions tab, choose CI, and run the workflow on `main`. That run is the full set: `checks` (PHPUnit on PHP 8.3–8.5 with WordPress 7.0, 7.1, and trunk; PHPCS, PHPStan, and Mailpit on PHP 8.3 / WordPress 7.1), `mysql`, `package`, `e2e`, and the PHP 7.4 syntax lint. The trunk cell may fail without failing the workflow. Fix a red run before starting the next stage.

Branch protection is unavailable on this private repository. Do not push directly to `main`, including fixes to CI.

```sh
npm run check
```

Commands run in the WordPress environment. Clone the repository into a directory named `formstead`. `wp-env` uses that directory name as the plugin slug, and these scripts expect the plugin at `wp-content/plugins/formstead`. Individual steps:

```sh
npm test
npm run lint
npm run analyse
npm run plugin-check
```

After Composer dependencies change, install them in the container:

```sh
npm run composer -- install
```

PHPUnit must run in `tests-cli`. The `cli` container shares the development database, and the WordPress test installer drops that database.

## Browser tests

Playwright uses the wp-env tests site at http://localhost:8889. `npm test` reinstalls that site and deactivates plugins, so run setup again before the browser tests. The development site on port 8888 is left alone.

```sh
npx wp-env start
npm run e2e:setup
npm run e2e
```

`npx wp-env start` is required after `.wp-env.json` changes so the tests site defines `FORMSTEAD_E2E`. Tests that read mail also need `npm run mail:up`. Mailpit joins both the development and the tests Docker networks.

## Pull requests

- Keep `main` as the only permanent branch. Use one short-lived branch and one pull request per roadmap stage, opened against `main`.
- Make as many working commits as useful on that branch. Keep the pull request focused on the stage and include its fixes before merging.
- Link an existing issue when useful. A separate issue or pull request for each implementation step is not required.
- Run `npm run check` before merging. Use **Squash and merge** so a completed stage adds one commit to `main`; the source branch is deleted automatically.
- Run the CI workflow by hand on `main` after merging. If it fails, use a short-lived fix branch and squash its pull request before starting the next stage.
- Small fixes outside an active stage may use their own short-lived branch and squash pull request. Do not push directly to `main`.

## Commits

Write commit messages in English, in the imperative mood, and say why the change exists.

For a stage squash commit, include the stage number and summarize its result. Stages 0–9 are stored as ten commits, including their follow-up fixes. Earlier pull requests describe the archived development history.

```
Complete stage 9: add the admin API, signed webhooks, and Next.js integration.
```

## Versions and releases

Keep the plugin version, `readme.txt` stable tag, and database migrations consistent. Create Git tags and GitHub Releases for versions actually distributed for testing or publication; a completed stage does not require either. Record changes in `CHANGELOG.md`.

## Code

- PHP 8.3+, WordPress 7.0+, text domain `formstead`.
- Register activation and deactivation hooks at the top level of `formstead.php`.
- User-facing strings go through WordPress translation functions.
- Do not log submission contents, secrets, or IP addresses.

## License

Contributions are licensed under GPL-2.0-or-later, the same license as the plugin.
