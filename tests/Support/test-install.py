"""Проверки изоляции локальных зависимостей от конфигурации пакета."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('installer', Path(__file__).with_name('install.py'))
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


class InstallationModesTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='apisutra-install-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve() / 'adapter'
        self.application = self.root / 'tests/Integration/Laravel'
        self.application.mkdir(parents=True)
        self.source = Path(self.temporary.name).resolve() / 'core'
        source_file = self.source / 'src/Core/AbstractClient.php'
        source_file.parent.mkdir(parents=True)
        source_file.write_text('unchanged source')
        for directory in [self.root, self.application]:
            (directory / 'composer.json').write_text(json.dumps({
                'name': 'fixture/package', 'require': {'apisutra/php': '^0.1'},
                'repositories': [{'type': 'path', 'url': 'sdk-fixture'}] if directory == self.application else [],
            }))
            (directory / 'composer.lock').write_text('original lock')

    def invoke(self, *arguments):
        with contextlib.redirect_stdout(io.StringIO()), \
                patch.object(installer, 'run', return_value='{"versions": ["fixture"]}') as run:
            installer.main(list(arguments), self.root)
        return run.call_args_list

    def test_local_mode_keeps_both_main_manifests_and_locks_unchanged(self):
        originals = {path: path.read_bytes() for directory in [self.root, self.application]
                     for path in [directory / 'composer.json', directory / 'composer.lock']}
        calls = self.invoke('--core', str(self.source))
        self.assertEqual(self.source, (self.root / '.test/core').resolve())
        for directory in [self.root, self.application]:
            local = json.loads((directory / 'composer.local.json').read_text())
            self.assertEqual('0.1.0', local['repositories'][0]['options']['versions']['apisutra/php'])
            self.assertEqual(self.source, (directory / local['repositories'][0]['url']).resolve())
        for path, content in originals.items():
            self.assertEqual(content, path.read_bytes())
        for call in calls:
            self.assertEqual('composer.local.json', call.args[2]['COMPOSER'])

    def test_public_mode_ignores_leftover_local_files_and_inherited_composer(self):
        self.invoke('--core', str(self.source))
        for mode in ['locked', 'lowest', 'latest']:
            with self.subTest(mode=mode), patch.dict(os.environ, {'COMPOSER': 'composer.local.json'}), \
                    patch.object(installer, 'prepare_public_sources') as prepare:
                calls = self.invoke('--dependencies', mode)
                prepare.assert_called_once_with(self.root)
                installs = [call for call in calls if call.args[0][1] in ['install', 'update']]
                self.assertEqual(2, len(installs))
                for call in calls:
                    self.assertEqual('composer.json', call.args[2]['COMPOSER'])
                for call in installs:
                    self.assertEqual(mode == 'lowest', '--prefer-lowest' in call.args[0])
                    self.assertEqual('install' if mode == 'locked' else 'update', call.args[0][1])

    def test_local_manifest_is_regenerated_after_config_changes(self):
        self.invoke('--core', str(self.source))
        manifest = self.root / 'composer.json'
        data = json.loads(manifest.read_text())
        data['require']['fixture/dependency'] = '^1.0'
        manifest.write_text(json.dumps(data))
        self.invoke('--core', str(self.source))
        local = json.loads((self.root / 'composer.local.json').read_text())
        self.assertEqual('^1.0', local['require']['fixture/dependency'])
        self.assertEqual([], json.loads(manifest.read_text())['repositories'])

    def test_invalid_local_checkout_fails_before_composer(self):
        with patch.object(installer, 'run') as run, self.assertRaisesRegex(SystemExit, 'Invalid core checkout'):
            installer.main(['--core', str(self.source / 'missing')], self.root)
        run.assert_not_called()

    def test_local_mode_preserves_existing_source_directory(self):
        core = self.root / '.test/core'
        core.mkdir(parents=True)
        (core / 'keep').write_text('work in progress')
        with self.assertRaisesRegex(SystemExit, 'move it'):
            self.invoke('--core', str(self.source))
        self.assertEqual('work in progress', (core / 'keep').read_text())

    def test_public_sources_follow_resolved_revision_without_changing_local_source(self):
        self.invoke('--core', str(self.source))
        fetched = self.root / '.test/core-source'
        (fetched / '.git').mkdir(parents=True)
        (fetched / 'src/Core').mkdir(parents=True)
        (fetched / 'src/Core/AbstractClient.php').write_text('public source')
        reference = 'a' * 40
        (self.root / 'composer.lock').write_text(json.dumps({'packages': [{
            'name': 'apisutra/php', 'version': '0.1.3', 'source': {'reference': reference},
        }]}))
        with patch.object(installer, 'run', return_value='') as run, contextlib.redirect_stdout(io.StringIO()):
            installer.prepare_public_sources(self.root)
        self.assertEqual(fetched, (self.root / '.test/core').resolve())
        self.assertEqual('unchanged source', (self.source / 'src/Core/AbstractClient.php').read_text())
        self.assertIn(['git', 'fetch', '--depth=1', installer.CORE_REPOSITORY, reference],
                      [call.args[0] for call in run.call_args_list])
        self.invoke('--core', str(self.source))
        self.assertEqual(self.source, (self.root / '.test/core').resolve())
        self.assertEqual('public source', (fetched / 'src/Core/AbstractClient.php').read_text())

    def test_dirty_public_checkout_is_not_updated(self):
        core = self.root / '.test/core-source'
        (core / '.git').mkdir(parents=True)
        (self.root / 'composer.lock').write_text(json.dumps({'packages': [{
            'name': 'apisutra/php', 'version': '0.1.3', 'source': {'reference': 'a' * 40},
        }]}))
        with patch.object(installer, 'run', return_value=' M keep') as run, \
                self.assertRaisesRegex(SystemExit, 'has changes'):
            installer.prepare_public_sources(self.root)
        self.assertEqual([['git', 'status', '--porcelain']], [call.args[0] for call in run.call_args_list])


if __name__ == '__main__':
    unittest.main()
