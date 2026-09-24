"""Установить учебный SDK из проверяемой поставки в Laravel-приложение без dev-зависимостей."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import sys

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--root', type=Path, required=True, help='Корень проверяемой поставки ApiSutra')
parser.add_argument('--report', type=Path)
parser.add_argument('--core', type=Path, required=True)
args = parser.parse_args()
adapter = args.root.resolve()
core = args.core.resolve()
sdk = core / 'docs/example/sdk'
PHP = os.environ.get('APISUTRA_PHP', 'php')
commands = []


def run(command, cwd, environment=None):
    if command[0] == PHP:
        command = [PHP, '-d', 'display_errors=stderr', *command[1:]]
    result = subprocess.run(command, cwd=cwd, env=environment, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.stderr:
        sys.stderr.write(result.stderr)
    commands.append({'command': command, 'exit_code': result.returncode})
    if result.returncode:
        raise RuntimeError(f'{command}:\n{result.stdout}')
    return result.stdout


def repository(path, name, version):
    # Версии принадлежат только стенду; публичный SDK зависит от выпущенной линии ApiSutra.
    return {'type': 'path', 'url': str(path), 'options': {'symlink': False, 'versions': {name: version}}}


def application(path):
    shutil.copytree(ROOT / 'tests/Integration/Laravel', path, ignore=shutil.ignore_patterns(
        'vendor', 'storage', '__pycache__', 'composer.local.json', 'composer.local.lock',
    ))
    for cache in (path / 'bootstrap/cache').glob('*.php'):
        cache.unlink()
    manifest = json.loads((path / 'composer.json').read_text())
    manifest['config']['vendor-dir'] = 'vendor'
    environment = dict(os.environ, COMPOSER='composer.json', APISUTRA_LARAVEL_VENDOR=str(path / 'vendor'), APISUTRA_PHP=PHP, APISUTRA_TEST_REDIS='0')
    return manifest, environment


report = {'status': 'running', 'commands': commands}
with tempfile.TemporaryDirectory(prefix='apisutra-sdk-install-') as temporary:
    folder = Path(temporary)
    minimal = folder / 'minimal'
    minimal.mkdir()
    (minimal / 'composer.json').write_text(json.dumps({
        'require': {'apisutra/laravel': '^0.1'},
        'repositories': [repository(core, 'apisutra/php', '0.1.0'), repository(adapter, 'apisutra/laravel', '0.1.0')],
        'config': {'allow-plugins': False},
    }, indent=2) + '\n')
    environment = dict(os.environ, COMPOSER='composer.json')
    print('Adapter: minimal install without Foundation, Queue, Console or Telescope', flush=True)
    run(['composer', 'update', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress'], minimal, environment)
    run([PHP, str(ROOT / 'tests/Support/minimal-install-smoke.php'), str(minimal)], minimal, environment)
    report['minimal'] = 'passed'

    plain = folder / 'laravel-without-adapter'
    manifest, environment = application(plain)
    del manifest['require']['apisutra/laravel']
    manifest['repositories'] = [repository(sdk, 'example/records-sdk', 'dev-main'), repository(core, 'apisutra/php', '0.1.0')]
    (plain / 'composer.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (plain / 'bootstrap/providers.php').write_text('<?php\n\nreturn [];\n')
    (plain / 'routes/web.php').write_text('<?php\n')
    print('SDK: Laravel without adapter, optional discovery and explicit construction', flush=True)
    run(['composer', 'update', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress'], plain, environment)
    output = run([PHP, str(ROOT / 'tests/Support/sdk-without-adapter.php'), str(plain)], plain, environment)
    assert 'Records SDK in Laravel without adapter — OK.' in output, output
    run([PHP, 'artisan', 'config:cache'], plain, environment)
    output = run([PHP, str(ROOT / 'tests/Support/sdk-without-adapter.php'), str(plain)], plain, environment)
    assert 'Records SDK in Laravel without adapter — OK.' in output, output
    report['laravel_without_adapter'] = 'passed'

    app = folder / 'laravel'
    manifest, environment = application(app)
    manifest['repositories'] = [repository(sdk, 'example/records-sdk', 'dev-main'), repository(core, 'apisutra/php', '0.1.0'), repository(adapter, 'apisutra/laravel', '0.1.0')]
    (app / 'composer.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print('SDK: Laravel install and package discovery', flush=True)
    # Сохраняем зависимости публичного lock; без него выполняем первичное разрешение.
    packages = ['apisutra/php', 'apisutra/laravel', 'example/records-sdk'] if (app / 'composer.lock').is_file() else []
    run(['composer', 'update', *packages, '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress'], app, environment)
    run(['composer', 'dump-autoload', '--no-dev', '--optimize', '--strict-psr'], app, environment)
    run([PHP, 'verify.php'], app, environment)
    run([PHP, 'verify-sdk.php'], app, environment)
    run(['python3', 'check-sdk-config.py'], app, environment)
    report['laravel'] = 'passed'

    print('SDK: explicit providers when discovery is disabled', flush=True)
    manifest['extra'] = {'laravel': {'dont-discover': ['*']}}
    (app / 'composer.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (app / 'bootstrap/providers.php').write_text('''<?php

declare(strict_types=1);

use ApiSutra\\Laravel\\SdkServiceProvider;
use Example\\Records\\Laravel\\DemoServiceProvider;
use Integration\\ApplicationProvider;

return [SdkServiceProvider::class, DemoServiceProvider::class, ApplicationProvider::class];
''')
    run([PHP, 'artisan', 'package:discover', '--no-ansi'], app, environment)
    package_manifest = (app / 'bootstrap/cache/packages.php').read_text()
    assert 'apisutra/laravel' not in package_manifest and 'apisutra/php' not in package_manifest and 'example/records-sdk' not in package_manifest, 'Discovery остался включён'
    observed = json.loads(run([PHP, 'probe-sdk-config.php'], app, environment))
    assert observed['id'] == 7 and observed['extras'] == {'new_field': False}, observed
    report['explicit_providers'] = 'passed'

report['status'] = 'passed'
if args.report:
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
print('SDK Laravel installs with/without adapter, discovery and explicit providers — OK.')
