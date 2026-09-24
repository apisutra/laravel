"""Установить зависимости из Packagist или явно подключить локальное ядро."""
import argparse
import json
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]
CORE_REPOSITORY = 'https://github.com/apisutra/php.git'


def run(command, directory, environment=None, capture=False):
    result = subprocess.run(command, cwd=directory, env=environment, check=True,
                            text=True, stdout=subprocess.PIPE if capture else None)
    return result.stdout


def link_core(root, source):
    if not (source / 'src/Core/AbstractClient.php').is_file():
        raise SystemExit('Invalid core checkout: src/Core/AbstractClient.php not found.')
    core = root / '.test/core'
    if core.is_symlink() and core.resolve() == source:
        return
    if core.exists() and not core.is_symlink():
        raise SystemExit('.test/core is a directory; move it before selecting a local checkout.')
    core.parent.mkdir(exist_ok=True)
    if core.is_symlink():
        core.unlink()
    core.symlink_to(source, target_is_directory=True)


def local_manifest(directory, core_url):
    data = json.loads((directory / 'composer.json').read_text())
    repository = {
        'type': 'path', 'url': core_url,
        'options': {'symlink': True, 'versions': {'apisutra/php': '0.1.0'}},
    }
    data['repositories'] = [repository, *data.get('repositories', [])]
    (directory / 'composer.local.json').write_text(json.dumps(data, indent=4) + '\n')


def install_dependencies(directory, dependencies, local):
    environment = os.environ.copy()
    # Выбор режима явный: унаследованный COMPOSER не включает локальную подмену.
    environment['COMPOSER'] = 'composer.local.json' if local else 'composer.json'
    operation = ['install'] if dependencies == 'locked' else ['update', '--prefer-stable']
    if dependencies == 'lowest':
        operation.append('--prefer-lowest')
    run(['composer', *operation, '--no-interaction', '--prefer-dist', '--no-progress'], directory, environment)
    for package in ['apisutra/php', 'laravel/framework']:
        info = json.loads(run(['composer', 'show', package, '--format=json'], directory, environment, capture=True))
        print(f'{directory}: {package} {", ".join(info["versions"])}', flush=True)


def prepare_public_sources(root):
    # Dev-инструменты исключены из dist: загружаем исходники установленной версии отдельно.
    lock = json.loads((root / 'composer.lock').read_text())
    package = next(package for package in lock['packages'] if package['name'] == 'apisutra/php')
    reference = package.get('source', {}).get('reference') or package.get('dist', {}).get('reference')
    if not reference:
        raise SystemExit('The installed core has no source reference for development checks.')
    core = root / '.test/core-source'
    core.mkdir(parents=True, exist_ok=True)
    if not (core / '.git').is_dir():
        if any(core.iterdir()):
            raise SystemExit('.test/core-source is not a Git checkout; move it before fetching public sources.')
        run(['git', 'init'], core)
    if run(['git', 'status', '--porcelain'], core, capture=True).strip():
        raise SystemExit('.test/core-source has changes; preserve them before fetching public sources.')
    run(['git', 'fetch', '--depth=1', CORE_REPOSITORY, reference], core)
    run(['git', 'checkout', '--detach', 'FETCH_HEAD'], core)
    link_core(root, core)
    print(f'Development sources: apisutra/php {package["version"]} ({reference})', flush=True)


def main(argv=None, root=ROOT):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--core', type=Path, help='Явный путь к рабочему дереву apisutra/php')
    parser.add_argument('--dependencies', choices=['locked', 'lowest', 'latest'], default='locked')
    args = parser.parse_args(argv)
    application = root / 'tests/Integration/Laravel'
    local = args.core is not None
    if local:
        link_core(root, args.core.resolve())
        local_manifest(root, '.test/core')
        local_manifest(application, '../../../.test/core')
        print('Local core enabled; using ignored composer.local.json / composer.local.lock.', flush=True)
    else:
        print('Packagist mode; local manifests and core checkouts do not override dependencies.', flush=True)
    install_dependencies(root, args.dependencies, local)
    if not local:
        prepare_public_sources(root)
    install_dependencies(application, args.dependencies, local)


if __name__ == '__main__':
    main()
