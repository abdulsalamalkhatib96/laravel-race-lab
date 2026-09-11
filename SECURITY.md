# Security
Race Lab is a **development/test dependency** and should be installed with `composer require --dev`.
It refuses to run in `production` unless `RACE_LAB_ALLOW_PRODUCTION=true` is explicitly set.
Query bindings are not captured by default. When enabled they are redacted by default.
Serialized closures are signed using a key derived from `APP_KEY`; do not run plans obtained from untrusted sources.
