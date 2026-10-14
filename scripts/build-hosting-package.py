#!/usr/bin/env python3
"""Build a clean Laravel hosting archive with locked production dependencies."""
import argparse
import hashlib
import shutil
import subprocess
import tempfile
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--output', required=True, help='Destination ZIP; do not place it inside the project')
parser.add_argument('--composer', default='composer', help='Composer executable (or composer.phar with --php)')
parser.add_argument('--php', default='php')
args = parser.parse_args()
output = Path(args.output).resolve()
if output == root or root in output.parents:
    raise SystemExit('The output must be outside the repository.')
tracked = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard'], cwd=root, text=True).splitlines()
excluded = ('storefront/', 'tests/', '.github/')
with tempfile.TemporaryDirectory(prefix='chiqui-hosting-') as temp:
    stage = Path(temp) / 'chiqui_tienda'
    stage.mkdir()
    for name in sorted(set(tracked)):
        source = root / name
        if '/__pycache__/' in name or name.endswith('.pyc') or name.startswith(excluded) or name in {'.gitignore', 'phpunit.xml', '.editorconfig', '.gitattributes'} or not source.is_file():
            continue
        destination = stage / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, destination)
    composer = [args.php, args.composer] if args.composer.endswith('.phar') else [args.composer]
    subprocess.run(composer + ['install', '--no-dev', '--no-scripts', '--prefer-dist', '--no-interaction', '--optimize-autoloader'], cwd=stage, check=True)
    # Ensure empty runtime directories exist; no runtime files enter the release.
    for directory in ['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
        (stage / directory).mkdir(parents=True, exist_ok=True)
    version = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
    dirty = bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=root, text=True).strip())
    (stage / 'VERSION.txt').write_text('Chiqui Tienda\nSource commit: ' + version + ('\nContains local changes' if dirty else '') + '\nProduction PHP dependencies from composer.lock\n', encoding='utf-8')
    files = sorted(p for p in stage.rglob('*') if p.is_file())
    forbidden = [p for p in files if p.name == '.env' or p.suffix.lower() in {'.key', '.pem', '.sqlite', '.log'}]
    if forbidden:
        raise SystemExit('Unexpected private/runtime files: ' + ', '.join(str(p.relative_to(stage)) for p in forbidden))
    manifest = '\n'.join(hashlib.sha256(p.read_bytes()).hexdigest() + '  ' + p.relative_to(stage).as_posix() for p in files) + '\n'
    (stage / 'MANIFEST.sha256').write_text(manifest, encoding='utf-8')
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
        for path in sorted(stage.rglob('*')):
            if path.is_file():
                archive.write(path, 'chiqui_tienda/' + path.relative_to(stage).as_posix())
        for path in sorted(stage.rglob('*')):
            if path.is_dir() and not any(path.iterdir()):
                archive.writestr('chiqui_tienda/' + path.relative_to(stage).as_posix() + '/', '')
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:
            raise SystemExit('Archive CRC validation failed.')
    print(str(output))
    print('SHA256: ' + hashlib.sha256(output.read_bytes()).hexdigest())
