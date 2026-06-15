# Contributing

Contributions are welcome and accepted via pull requests. Please review these guidelines before submitting.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure coding style passes by running `composer lint`.
- Send a coherent commit history — each commit in your pull request should be meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- We follow [SemVer](https://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

```bash
composer lint
```

To automatically fix style issues:

```bash
composer lint:fix
```

## Static Analysis

```bash
composer phpstan
```

## Tests

```bash
composer test
```

## Code of Conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to uphold it.
