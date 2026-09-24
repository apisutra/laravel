# ApiSutra Laravel development

This repository owns Laravel integration for `apisutra/php`. Keep shared mechanisms
in the core. Follow PSR-12, PHP 8.4+, strict types, typed contracts, one named type per
file and Russian PHP comments. Preserve existing behavior, explicit overrides and
zero-config defaults. Read the affected public contract before changing it.

Inspect, implement, verify, and report. Create plans only at the user's explicit request,
regardless of task size. Audits, ADRs, and report files are not routine prerequisites.
Resolve technical choices independently; group unresolved product or compatibility
questions with recommendations. Do not reopen accepted decisions without new evidence
or ask again to perform already authorized work. Record useful results in existing task
materials when available; otherwise a concise chat summary is sufficient.

Documentation is English and Russian, paired in `docs/translations.json`. Review
meaning before recording content hashes. Keep private plans out of public sources.
Public docs use full repository URLs for excluded development files.

Complete the implementation before routine test runs, static analysis, lint, and other
completion checks. Do not run checks after every file or small batch of edits. Interim
checks must resolve a concrete blocking uncertainty, reproduce a failure, or satisfy an
explicitly agreed technical gate. Once the code is ready, start with affected tests,
then run the package suite, static analysis and lint; run the real application when changing Laravel wiring,
configuration, discovery, or behavior that depends on application bootstrap.
Text-only changes need documentation checks and `git diff --check`, not the PHP suite
or dependency matrix. Changed PHP examples also need example analysis and execution.
Distribution changes require archive installation checks against the changed tree;
dependency/platform changes require the relevant matrix combinations. Full matrices
and archive checks remain in CI and release verification. Do not repeat passed checks
without relevant changes, failures, or new evidence, or archive routine test logs.
Commands and setup are in [testing](docs/en/development/testing.md). Do not claim
untested PHP/dependency combinations or remote CI results as passed.
