#!/usr/bin/env python3
"""Rebuilds assets/js/se-i18n-sw.js from the plain-text lists in i18n/sw_*.txt.
Each line:  English text|||Kiswahili text   (curly quotes and extra spaces in the English part do not matter).
Run from the project folder:  python3 tools/build_sw_wordlist.py
To see which strings a page still shows in English, open it in Kiswahili with ?i18n_debug=1 and read the browser console."""
import json, re, glob, os
def norm(s): return re.sub(r'\s+', ' ', s.replace('\u2018', "'").replace('\u2019', "'").replace('\u201c', '"').replace('\u201d', '"')).strip()
d = {}
for f in sorted(glob.glob('i18n/sw_*.txt')):
    for n, line in enumerate(open(f, encoding='utf-8'), 1):
        line = line.rstrip('\n')
        if not line.strip(): continue
        if '|||' not in line: print('skipped (no |||) %s:%d' % (f, n)); continue
        k, v = line.split('|||', 1); d[norm(k)] = v.strip()
PATTERNS = [
 (r'^(\d+) nights?$','usiku {1}'),(r'^(\d+) guests?$','wageni {1}'),(r'^Up to (\d+) guests?$','Hadi wageni {1}'),
 (r'^(\d+) reviews?$','maoni {1}'),(r'^\((\d+) reviews?\)$','(maoni {1})'),(r'^(\d+(?:\.\d+)?) km away$','{1} km kutoka hapa'),
 (r'^(\d+(?:\.\d+)?) km from (.+)$','{1} km kutoka {2}'),(r'^Hi,? (.+)$','Habari, {1}'),(r'^Welcome back,? (.+)$','Karibu tena, {1}'),
 (r'^Email verified, (.+)\.$','Barua pepe imethibitishwa, {1}.'),(r'^Hosted by (.+)$','Mwenyeji: {1}'),(r'^(\d+) unread$','{1} hazijasomwa'),
 (r'^Notifications, (\d+) unread$','Arifa, {1} hazijasomwa'),(r'^(\d+) out of 5 stars?$','nyota {1} kati ya 5'),
 (r'^Booking (#?[A-Z0-9-]{4,})$','Uhifadhi {1}'),(r'^Conversations \((\d+)\)$','Mazungumzo ({1})'),(r'^All \((\d+)\)$','Yote ({1})'),
 (r'^Unanswered \((\d+)\)$','Hayajajibiwa ({1})'),(r'^(\d+) stays? found$','malazi {1} yamepatikana'),(r'^(\d+) attractions? found$','vivutio {1} vimepatikana'),
 (r'^Member since (.+)$','Mwanachama tangu {1}'),(r'^Total: (.+)$','Jumla: {1}'),(r'^Showing (\d+) of (\d+)$','Inaonyesha {1} kati ya {2}'),
]
js = ('/* StayExplore Kenya - Kiswahili word list. Used offline by se-i18n.js. Generated from i18n/sw_*.txt by tools/build_sw_wordlist.py.\n'
      ' * Anything not listed here stays in English. A native Kiswahili speaker should review it before launch. */\n')
js += 'window.SE_SW=' + json.dumps(d, ensure_ascii=False, indent=0, sort_keys=True) + ';\n'
js += 'window.SE_SW_PATTERNS=[' + ','.join('[/%s/,%s]' % (p, json.dumps(r, ensure_ascii=False)) for p, r in PATTERNS) + '];\n'
open('assets/js/se-i18n-sw.js', 'w', encoding='utf-8').write(js)
print(len(d), 'phrases,', len(PATTERNS), 'patterns,', os.path.getsize('assets/js/se-i18n-sw.js'), 'bytes')
