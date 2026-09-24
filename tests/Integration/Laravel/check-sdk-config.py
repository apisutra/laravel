"""Публикация и кеш конфигурации SDK в отдельных процессах Laravel."""
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
PHP = os.environ.get('APISUTRA_PHP', 'php')
published = ROOT / 'config/apisutra/records.php'
original = published.read_bytes() if published.exists() else None
cache = ROOT / 'bootstrap/cache/config.php'
original_cache = cache.read_bytes() if cache.exists() else None
env = os.environ.copy()
env.update(RECORDS_BASE_URL='https://configured.example.test', RECORDS_TIMEOUT='17', RECORDS_TOKEN='synthetic-config-token')


def run(*arguments, environment=env):
    result = subprocess.run([PHP, '-d', 'display_errors=stderr', *arguments], cwd=ROOT, env=environment, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.stderr:
        sys.stderr.write(result.stderr)
    if result.returncode:
        raise RuntimeError(f'{arguments}: {result.stdout}')
    return result.stdout


checks = 0
try:
    published.unlink(missing_ok=True)
    for publish in [False, True]:
        run('artisan', 'config:clear')
        if publish:
            run('artisan', 'vendor:publish', '--tag=records-config', '--no-interaction')
            assert published.is_file(), 'Конфигурация не опубликована'
            published.write_text(published.read_text() + '\n// Пользовательское изменение.\n')
            saved = published.read_bytes()
            run('artisan', 'vendor:publish', '--tag=records-config', '--no-interaction')
            assert published.read_bytes() == saved, 'Повторный publish перезаписал конфигурацию'
            checks += 2
        for cached in [False, True]:
            run('artisan', 'config:cache' if cached else 'config:clear')
            observed = json.loads(run('probe-sdk-config.php'))
            assert observed.pop('extras')['new_field'] is False, observed
            expected = {'base_url': env['RECORDS_BASE_URL'], 'timeout': 17, 'has_auth': True, 'id': 7, 'cached': cached}
            assert observed == expected, (observed, expected)
            checks += 1
        changed = dict(env, RECORDS_BASE_URL='https://changed.example.test', RECORDS_TIMEOUT='23', RECORDS_TOKEN='')
        observed = json.loads(run('probe-sdk-config.php', environment=changed))
        assert observed['base_url'] == env['RECORDS_BASE_URL'] and observed['timeout'] == 17 and observed['has_auth'], observed
        run('artisan', 'config:cache', environment=changed)
        observed = json.loads(run('probe-sdk-config.php', environment=changed))
        assert observed['base_url'] == changed['RECORDS_BASE_URL'] and observed['timeout'] == 23 and not observed['has_auth'], observed
        checks += 2
    print(json.dumps({'sdk_config_checks': checks, 'status': 'passed'}))
finally:
    run('artisan', 'config:clear')
    if original is None:
        published.unlink(missing_ok=True)
        if published.parent.exists() and not any(published.parent.iterdir()):
            published.parent.rmdir()
    else:
        published.write_bytes(original)
    if original_cache is not None:
        cache.write_bytes(original_cache)
