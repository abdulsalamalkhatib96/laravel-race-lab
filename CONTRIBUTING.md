# Contributing

Keep the core deterministic: do not replace barriers/gates with timing sleeps. New checkpoint types must be side-effect free with respect to the resource being tested. Add a regression test for the buggy interleaving and a passing test for the fixed implementation.

Before a PR:

```bash
composer install
composer test
php scripts/core-smoke.php
php artisan race-lab:doctor
```
