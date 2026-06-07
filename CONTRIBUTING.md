# Contributing

Thanks for taking the time to contribute.

## Getting started

```bash
git clone https://github.com/webrek/laravel-idempotency
cd laravel-idempotency
composer install
```

## Before opening a pull request

Run the full check suite locally — CI runs the same thing across the supported
PHP and Laravel matrix:

```bash
make check     # pint --test, phpstan, phpunit
```

Or individually:

```bash
composer pint        # format
composer pint:check  # verify formatting without writing
composer stan        # static analysis (level 6)
composer test        # phpunit
```

## Guidelines

- Keep pull requests focused; one logical change per PR.
- Add or update tests for any behaviour change. Bug fixes should come with a test
  that fails before the fix.
- Match the existing code style — Pint with the Laravel preset is the source of
  truth, so run it before pushing.
- Update `CHANGELOG.md` under the `Unreleased` heading.

## Reporting bugs

Open an issue with a minimal reproduction: the route definition, the request
(method, headers, body) and the response you got versus the one you expected.
