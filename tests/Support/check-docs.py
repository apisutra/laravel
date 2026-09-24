"""Проверки документации адаптера с общим проверяющим инструментом ядра."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--root', type=Path, default=ROOT)
parser.add_argument('--distribution', action='store_true')
args = parser.parse_args()
core = ROOT / '.test/core'
support = core / 'tests/Support'
if not support.is_dir():
    raise SystemExit('Run python3 tests/Support/install.py first (public core checkout required).')
sys.path.insert(0, str(support))
spec = importlib.util.spec_from_file_location('core_docs', support / 'check-docs.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
result = module.inspect(args.root, args.distribution)
if not args.distribution:
    result['errors'] += module.declaration_errors(args.root, json.loads((ROOT / 'tests/Support/docs-api.json').read_text()))
for error in result['errors']:
    print(error)
print(f"Documents: {result['documents']}; links: {result['local_links']}; errors: {len(result['errors'])}")
if result['errors']:
    raise SystemExit(1)
if not args.distribution:
    php = os.environ.get('APISUTRA_PHP', 'php')
    subprocess.run([php, str(support / 'check-docs-api.php'), str(args.root), str(ROOT / 'tests/Support/docs-api.json')], check=True)
    subprocess.run([php, str(support / 'check-docs-translations.php'), str(args.root)], check=True)
