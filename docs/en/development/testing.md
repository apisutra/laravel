<!-- languages --> <a href="testing.md">English</a> · <a href="../../ru/development/testing.md">Русский</a> <!-- /languages -->
# Develop and verify the Laravel package <a id="section-1"></a>

Requires PHP 8.4/8.5, Composer 2, Python 3.9+, Git and curl/intl/mbstring/XML extensions.
Laravel dependencies require `^13.12`, matching the verified lower bound.
Checks use fake HTTP and do not require a database. Run from the adapter checkout.
The list below is the full verification set, not a required sequence for every edit.
Start with affected tests; for implementation changes, run the package suite, analysis
and lint once ready. Run the real application for changes to Laravel wiring, config,
discovery, or behavior that depends on bootstrap. Text-only changes need documentation
and whitespace checks; changed PHP examples also need analysis and execution.
Archive and dependency/platform checks apply when those areas change, or for CI and
release verification. Repeat passed checks only after relevant changes, failures,
or new evidence. Routine checks need no separate report files.

```bash
python3 tests/Support/install.py --core ../php
python3 tests/Support/test-install.py
composer validate --strict
composer dump-autoload --optimize --strict-psr
composer test
composer test:integration
composer lint
composer analyse
composer check-docs
composer analyse-docs
composer check-package
```

Use `--core` for joint development or before a compatible core release is available:

```bash
python3 tests/Support/install.py --core /absolute/path/to/php
```

The installer generates ignored `composer.local.json` files beside both main manifests
and selects them through Composer's `COMPOSER` environment variable. Their locks are
`composer.local.lock`; main manifests and locks remain unchanged. `.test/core` points
to the selected source and Composer symlinks it into vendor. Changes are immediately
available. The local fixture version is 0.1.0; this does not publish or tag the core.
Repeat `--core` on subsequent runs. Use `--dependencies latest` to resolve changed
dependency declarations; local manifests are regenerated from the main manifests.

Without `--core`, the installer uses Packagist for the core in both environments,
even when local files or a previous `.test/core` symlink exist. Ordinary
`composer install` also uses the main manifest without requiring a core checkout.
Both require a published core compatible with `^0.1 || ^0.2`. The full installer additionally
fetches the resolved core revision into `.test/core-source` for docs, examples and
distribution checks, and points `.test/core` there; it does not override the runtime
dependency. These directories are ignored and excluded from archives. The integration
application still uses path repositories for the adapter under test and the example SDK.

Without a lock, the first install resolves dependencies and creates the selected lock.
Both main locks pin the released core; CI's locked jobs require them. Local development and checks need no publication. Local manifests
and locks are excluded from Git and both distribution formats.

## Dependency matrix <a id="section-2"></a>

CI and release verification cover locked on PHP 8.4 and 8.5, lowest on 8.4 and latest
on 8.5. Both the package and application must resolve the selected dependency mode.
To reproduce a relevant combination locally, use isolated checkouts:

```bash
python3 tests/Support/install.py --dependencies lowest
composer test
composer test:integration
# Fresh compatible versions:
python3 tests/Support/install.py --dependencies latest
```

The installer displays resolved Laravel versions. Composer security constraints remain
enabled. Quality, documentation and distribution checks run once on locked PHP 8.4.
Without `--core`, lowest/latest also resolve the core from Packagist. Add `--core ../php`
to check those dependency modes against your local core instead.
An independent Redis smoke checks optional Laravel wiring; the core owns the full
atomic-backend matrix. Neither suite proves Octane or a separate queue worker lifecycle.

The lowest set includes older Symfony Translation code that emits PHP 8.4 deprecations.
Diagnostics remain visible on stderr; JSON probes use stdout separately. Updating
compatible dependencies removes these warnings; no global suppression is applied.

## Distributions and documentation <a id="section-3"></a>

`check-package` compares Git and Composer archives and installs each into a separate
Laravel application without dev dependencies. It verifies discovery, disabled discovery,
explicit providers, SDK overrides and config caching. Use `--staged` to check the index;
a temporary `GIT_INDEX_FILE` can select uncommitted changes without changing user staging.
A Git tree is sufficient even before the first commit.

Documentation shares the checker from the selected public core checkout. It verifies
local links, API declarations, language pairs and PHP snippets. PHPStan checks the
existing SDK's Laravel code; the core checks its standalone code.

## Joint Records SDK checks <a id="sdk-example"></a>

When changing the example, the adapter API it uses, or their guides, verify both current
checkouts before merging. From the adapter checkout:

```bash
python3 tests/Support/install.py --core ../php
composer analyse-docs
python3 tests/Support/check-sdk-package.py --root . --core ../php
```

The installation check uses the shipped example without dev dependencies, in Laravel
with and without the adapter. It verifies optional discovery, explicit construction,
DI, overrides and config caching. The core owns the standalone installation check.
Archive verification includes these checks; do not repeat them separately after it.
Before release, verify the final trees and update the selected core revision.
Default adapter CI uses a selected core revision; it cannot detect later changes in
the other repository. Joint checkout verification closes that gap during development.
