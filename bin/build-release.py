#!/usr/bin/env python3
"""Build the runtime ZIP from tracked files, respecting the directory exclusions."""
from pathlib import Path
import fnmatch, hashlib, json, re, subprocess, zipfile
root = Path(__file__).resolve().parents[1]
version = re.search(r"define\('ACE_REDIS_CACHE_VERSION', '([^']+)'", (root / 'ace-redis-cache.php').read_text()).group(1)
files = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
patterns = [line.strip().lstrip('/') for line in (root / '.distignore').read_text().splitlines() if line.strip() and not line.startswith('#')]
files = sorted(p for p in files if p and not any(fnmatch.fnmatch(p, pat) or p.startswith(pat.rstrip('/') + '/') or fnmatch.fnmatch(Path(p).name, pat) for pat in patterns))
out = root.parent / 'evidence' / 'arc'
out.mkdir(parents=True, exist_ok=True)
archive = out / f'ace-redis-cache-{version}.zip'
manifest = {}
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for name in files:
        data = (root / name).read_bytes()
        info = zipfile.ZipInfo('ace-redis-cache/' + name, (2026, 10, 30, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        z.writestr(info, data)
        manifest[name] = hashlib.sha256(data).hexdigest()
sha = hashlib.sha256(archive.read_bytes()).hexdigest()
(out / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
Path(str(archive) + '.sha256').write_text(sha + '  ' + archive.name + '\n')
print(json.dumps({'zip': str(archive), 'sha256': sha, 'files': len(files)}))
