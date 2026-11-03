#!/usr/bin/env python3
"""Verify committed upstream resources offline against the package lock manifest."""
from pathlib import Path
import hashlib
import json
import sys

root = Path(__file__).resolve().parents[1]
rules = root / 'rules'
manifest = json.loads((rules / 'manifest.json').read_text())
checked = 0
try:
    if manifest['version'] != 1:
        raise ValueError('Unsupported manifest version')
    for package in manifest['packages']:
        if not package['files'] or not package['profiles']:
            raise ValueError(f'Empty package: {package["id"]}')
        for name, expected in package['files'].items():
            path = (rules / name).resolve()
            if not path.is_relative_to(rules.resolve()) or not path.is_file():
                raise ValueError(f'Unsafe or missing resource: {name}')
            if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
                raise ValueError(f'Hash mismatch: {name}')
            checked += 1
        for profile in package['profiles']:
            for resource in [profile['schema'], *profile['schematron']]:
                if resource not in package['files']:
                    raise ValueError(f'Unpinned profile resource: {resource}')
    fixture_root = root / 'tests/fixtures/upstream/xrechnung'
    fixtures = json.loads((fixture_root.parent / 'manifest.json').read_text())
    fixture_count = 0
    for name, expected in fixtures['files'].items():
        path = (fixture_root / name).resolve()
        if not path.is_relative_to(fixture_root.resolve()):
            raise ValueError(f'Unsafe fixture path: {name}')
        if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
            raise ValueError(f'Fixture hash mismatch: {name}')
        fixture_count += 1
    factur_root = root / 'tests/fixtures/upstream/factur-x'
    for name, record in json.loads((factur_root / 'provenance.json').read_text())['files'].items():
        path = (factur_root / name).resolve()
        if not path.is_relative_to(factur_root.resolve()):
            raise ValueError(f'Unsafe Factur-X fixture path: {name}')
        if hashlib.sha256(path.read_bytes()).hexdigest() != record['sha256']:
            raise ValueError(f'Factur-X fixture hash mismatch: {name}')
        fixture_count += 1
    print(json.dumps({'ruleResources': checked, 'fixtureResources': fixture_count, 'status': 'verified'}))
except (KeyError, OSError, ValueError) as error:
    print(str(error), file=sys.stderr)
    sys.exit(1)
