<!-- languages --> <a href="CHANGELOG.md">English</a> · <a href="docs/ru/changelog.md">Русский</a> <!-- /languages -->
# Changelog <a id="section-1"></a>

## 0.1.0

Initial release of the Laravel 13.12+ integration for ApiSutra PHP 0.1, requiring PHP 8.4+.

- Automatic package discovery and overridable client, request, transport and validation
  bindings; ClientConfigFactory supplies application defaults without pinning Application
  in client configuration.
- RequestFactory transfers HTTP input and uploads; ClientResponseAdapter returns JSON,
  text and file responses with safe download filenames.
- Pagination::collect() exposes a lazy DTO-preserving Laravel collection. Custom DTO
  hydrators can use application dependency injection.
- ApiSutra::for()->fake/assert and InteractsWithApiSutra provide isolated test sessions,
  strict missing-mock checks and automatic cleanup.
- Bounded execution observation with Laravel events, Telescope and logging, application
  correlation and automatic request/job/command lifecycle handling.
- ReleaseOnSdkThrottle queue middleware releases repeatable jobs on quota/cooldown refusal
  using Laravel attempt and deadline accounting.
- Artisan client/request/DTO generation and an about section; integration defaults work
  without mandatory configuration publishing.
- English/Russian guides and the shared Records SDK example cover application setup,
  SDK authorship, async calls, OAuth2, shared cooldown and optional adapter installation.

HTTP execution and its policies are provided by [apisutra/php](https://github.com/apisutra/php).
See the [capability map](README.md#capabilities) and [documentation](docs/en/README.md).
