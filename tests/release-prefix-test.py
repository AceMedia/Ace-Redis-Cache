#!/usr/bin/env python3
"""Fail when a release archive still carries a name from before the aceredis prefix."""
import re, sys, zipfile

OLD = re.compile(
    r"(?<![A-Za-z0-9])(?:ace_redis_|ace_rc_|ace_oc_|ace_object_cache_|ace_known_|ace_managed_|ace_nocache|ace_http_|ace_rcaq_)"
    r"|(?<![A-Za-z0-9_])(?:ACE_REDIS_|ACE_OC_|ACE_FORCE_|ACE_RC_(?:BLOCK_CACHE|VARNISH|EXIT_ON_HIT))"
    r"|aceredis-source-only|'ace (?:autoload-top|trim-autoload|block-cache)'"
)
bad = []
with zipfile.ZipFile(sys.argv[1]) as z:
    names = z.namelist()
    for name in names:
        if name.startswith('ace-redis-cache/compat/') or '/tests/' in name:
            bad.append((name, 'file must not ship'))
        if not name.endswith(('.php', '.js', '.json', '.txt')):
            continue
        text = z.read(name).decode('utf-8', 'replace')
        for m in OLD.finditer(text):
            bad.append((name, text[max(0, m.start() - 30):m.end() + 30].replace('\n', ' ')))
for name, what in bad[:40]:
    print('FAIL', name, '::', what)
print(('FAIL' if bad else 'PASS'), len(names), 'files,', len(bad), 'problems')
sys.exit(1 if bad else 0)
