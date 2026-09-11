# Validation status

Validation performed while building this snapshot:

- `php -l` passed for every PHP file in `src/`, `config/`, `tests/`, and `examples/` on PHP 8.4.23.
- `scripts/core-smoke.php` passed with real `pcntl_fork()` child processes. It verifies two OS processes can arrive at the same FileCoordinator barrier, block, auto-release and observe shared state.
- Query matcher smoke checks passed for quoted MySQL-style SQL and non-matching tables.
- Execution state completion smoke check passed.
- `composer.json` parses as valid JSON.

The build environment did **not** contain Composer, MySQL, PostgreSQL or Redis, and outbound network/DNS was unavailable. Therefore dependency installation and full Laravel host-application integration tests could not be executed here. The repository includes PHPUnit tests and a Laravel-version Testbench CI matrix so those checks run in a normal Composer/GitHub environment.
