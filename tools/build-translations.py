#!/usr/bin/env python3
"""
Build Lavkhata translation files.

  python3 tools/build-translations.py

1. Extracts every translatable string from lavkhata/**/*.php into languages/lavkhata.pot
2. Compiles languages/lavkhata-bn_BD.po into .mo (WordPress < 6.5) and .l10n.php (WordPress 6.5+)
3. Lists strings that are missing from the .po file

To translate a new string, add it to lavkhata-bn_BD.po and run this again.
"""
import os
import re
import struct
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lavkhata')
LANG = os.path.join(ROOT, 'languages')
DOMAIN = 'lavkhata'

SINGLE = re.compile(r"(?:\b__|\besc_html__|\besc_html_e|\besc_attr__|\besc_attr_e|\b_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'lavkhata'")
CONTEXT = re.compile(r"\b_x\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'lavkhata'")
PLURAL = re.compile(r"\b_n(?:_noop)?\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'")


def unq(s):
    return s.replace("\\'", "'").replace('\\\\', '\\')


def extract():
    found = {}
    for root, _, files in os.walk(ROOT):
        for f in sorted(files):
            if not f.endswith('.php') or f.endswith('.l10n.php'):
                continue
            path = os.path.join(root, f)
            text = open(path, encoding='utf-8').read()
            rel = os.path.relpath(path, ROOT)
            for m in SINGLE.finditer(text):
                found.setdefault(unq(m.group(1)), rel)
            for m in CONTEXT.finditer(text):
                found.setdefault(unq(m.group(1)), rel)
            for m in PLURAL.finditer(text):
                found.setdefault(unq(m.group(1)) + '\x00' + unq(m.group(2)), rel)
    return found


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def po_unescape(s):
    return s.replace('\\n', '\n').replace('\\"', '"').replace('\\\\', '\\')


def write_pot(found):
    out = ['msgid ""', 'msgstr ""', '"Content-Type: text/plain; charset=UTF-8\\n"', '"Project-Id-Version: Lavkhata\\n"', '']
    for msg, ref in sorted(found.items()):
        out.append('#: ' + ref)
        if '\x00' in msg:
            s, p = msg.split('\x00')
            out += ['msgid "%s"' % po_escape(s), 'msgid_plural "%s"' % po_escape(p), 'msgstr[0] ""', 'msgstr[1] ""', '']
        else:
            out += ['msgid "%s"' % po_escape(msg), 'msgstr ""', '']
    open(os.path.join(LANG, DOMAIN + '.pot'), 'w', encoding='utf-8').write('\n'.join(out))


def read_po(path):
    entries = {}
    cur = {}
    key = None

    def flush():
        if 'msgid' in cur and cur['msgid']:
            if 'msgid_plural' in cur:
                k = cur['msgid'] + '\x00' + cur['msgid_plural']
                v = cur.get('msgstr[0]', '') + '\x00' + cur.get('msgstr[1]', '')
                if v.strip('\x00'):
                    entries[k] = v
            elif cur.get('msgstr'):
                entries[cur['msgid']] = cur['msgstr']

    for line in open(path, encoding='utf-8'):
        line = line.rstrip('\n')
        if line.startswith('msgid '):
            flush()
            cur = {}
        m = re.match(r'^(msgid_plural|msgid|msgstr\[\d\]|msgstr) "(.*)"$', line)
        if m:
            key = m.group(1)
            cur[key] = po_unescape(m.group(2))
        elif line.startswith('"') and key:
            cur[key] += po_unescape(line[1:-1])
    flush()
    return entries


def write_po(path, entries, found):
    out = ['msgid ""', 'msgstr ""', '"Content-Type: text/plain; charset=UTF-8\\n"', '"Language: bn_BD\\n"', '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"', '']
    for msg in sorted(set(entries) | set(found)):
        tr = entries.get(msg, '')
        if '\x00' in msg:
            s, p = msg.split('\x00')
            t = tr.split('\x00') if tr else ['', '']
            out += ['msgid "%s"' % po_escape(s), 'msgid_plural "%s"' % po_escape(p), 'msgstr[0] "%s"' % po_escape(t[0]), 'msgstr[1] "%s"' % po_escape(t[1] if len(t) > 1 else t[0]), '']
        else:
            out += ['msgid "%s"' % po_escape(msg), 'msgstr "%s"' % po_escape(tr), '']
    open(path, 'w', encoding='utf-8').write('\n'.join(out))


def write_mo(path, entries):
    header = 'Content-Type: text/plain; charset=UTF-8\nLanguage: bn_BD\nPlural-Forms: nplurals=2; plural=(n != 1);\n'
    items = sorted([('', header)] + list(entries.items()))
    ids = b''
    strs = b''
    offsets = []
    for k, v in items:
        kb, vb = k.encode('utf-8'), v.encode('utf-8')
        offsets.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\x00'
        strs += vb + b'\x00'
    n = len(items)
    key_start = 7 * 4 + 16 * n
    val_start = key_start + len(ids)
    table = b''
    for o in offsets:
        table += struct.pack('<II', o[1], o[0] + key_start)
    for o in offsets:
        table += struct.pack('<II', o[3], o[2] + val_start)
    head = struct.pack('<Iiiiiii', 0x950412de, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    open(path, 'wb').write(head + table + ids + strs)


def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def write_l10n(path, entries):
    lines = ['<?php', "return ['domain'=>'lavkhata','plural-forms'=>'nplurals=2; plural=(n != 1);','language'=>'bn_BD','messages'=>["]
    for k in sorted(entries):
        lines.append('%s=>%s,' % (php_str(k), php_str(entries[k])))
    lines.append(']];')
    open(path, 'w', encoding='utf-8').write('\n'.join(lines) + '\n')


def main():
    os.makedirs(LANG, exist_ok=True)
    found = extract()
    write_pot(found)
    po = os.path.join(LANG, DOMAIN + '-bn_BD.po')
    entries = read_po(po) if os.path.exists(po) else {}
    # Optional seed dictionary: python3 tools/build-translations.py seed.py
    if len(sys.argv) > 1:
        ns = {}
        exec(open(sys.argv[1], encoding='utf-8').read(), ns)
        entries.update(ns['BN'])
    entries = {k: v for k, v in entries.items() if k in found}
    write_po(po, entries, found)
    write_mo(os.path.join(LANG, DOMAIN + '-bn_BD.mo'), entries)
    write_l10n(os.path.join(LANG, DOMAIN + '-bn_BD.l10n.php'), entries)
    missing = [k for k in found if k not in entries]
    print('%d strings, %d translated, %d missing' % (len(found), len(entries), len(missing)))
    for m in sorted(missing):
        print('  missing: ' + m.replace('\x00', ' | '))


if __name__ == '__main__':
    main()
