<!-- languages --> <a href="../../CHANGELOG.md">English</a> · <a href="changelog.md">Русский</a> <!-- /languages -->
# История изменений <a id="section-1"></a>

## 0.1.0

Первый выпуск интеграции Laravel 13.12+ с ApiSutra PHP 0.1; требуется PHP 8.4+.

- Автоматический package discovery и переопределяемые bindings клиента, запросов,
  транспорта и валидации; ClientConfigFactory добавляет defaults приложения без
  закрепления Application в конфигурации клиента.
- RequestFactory переносит HTTP-вход и загрузки; ClientResponseAdapter возвращает JSON,
  текст и файлы с безопасными именами скачивания.
- Pagination::collect() предоставляет ленивую Laravel-коллекцию с сохранением DTO.
  Пользовательские гидраторы DTO могут использовать DI приложения.
- ApiSutra::for()->fake/assert и InteractsWithApiSutra обеспечивают изолированные тестовые
  сессии, строгую проверку отсутствующих mock и автоматическую очистку.
- Ограниченное наблюдение исполнения: Laravel-события, Telescope, logging, корреляция
  приложения и автоматическая обработка жизненного цикла request/job/command.
- Queue middleware ReleaseOnSdkThrottle возвращает повторяемые jobs в очередь при отказе
  квоты/cooldown, используя учёт попыток и сроков Laravel.
- Artisan-генерация клиента/запроса/DTO и секция about; defaults интеграции работают
  без обязательной публикации конфигурации.
- Руководства EN/RU и общий Records SDK показывают подключение приложения, разработку SDK,
  async-вызовы, OAuth2, общий cooldown и необязательную установку адаптера.

HTTP-выполнение и его политики предоставляет [apisutra/php](https://github.com/apisutra/php).
См. [карту возможностей](overview.md#capabilities) и [документацию](README.md).
