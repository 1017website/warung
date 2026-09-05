"""Run real parallel PHP request processes against warungkita_test, without truncation.
Usage: python tests/Concurrency/run.py
Each run creates a separate audit tenant. Do not run PHPUnit concurrently (it resets the test DB).
"""
import concurrent.futures
import json
import pathlib
import subprocess
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKER = ROOT / 'tests/Concurrency/worker.php'
OUT = ROOT / 'storage/app/concurrency' / time.strftime('%Y%m%d-%H%M%S')
OUT.mkdir(parents=True, exist_ok=True)

def php(*args):
    result = subprocess.run(['php', str(WORKER), *map(str, args)], cwd=ROOT, capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return json.loads(result.stdout)

fixture = php('seed')
fixture_path = OUT / 'fixture.json'
fixture_path.write_text(json.dumps(fixture), encoding='utf-8')
report = {'fixture': fixture, 'scenarios': []}

def burst(name, jobs):
    start = time.time() + 4
    paths = []
    for index, job in enumerate(jobs):
        job.update(user=fixture['user'], start=start)
        path = OUT / f'{name}-{index}.json'
        path.write_text(json.dumps(job), encoding='utf-8')
        paths.append(path)
    with concurrent.futures.ThreadPoolExecutor(max_workers=len(jobs)) as pool:
        responses = list(pool.map(lambda path: php('request', path), paths))
    state = php('state', fixture_path)
    row = {'name': name, 'responses': responses, 'state': state}
    report['scenarios'].append(row)
    (OUT / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(row), flush=True)

def checkout(ids, deposit=False):
    data = {'items': [{'id': pid, 'qty': 1} for pid in ids], 'service_type': 'takeaway', 'paid_amount': 10000 * len(ids)}
    if deposit:
        data.update(member_id=fixture['member'], payment_method='deposit')
    return {'method': 'POST', 'url': '/kasir/checkout', 'data': data}

p = fixture['products']
burst('stock', [checkout([p['stock']]) for _ in range(20)])
burst('deposit', [checkout([p['deposit']], True) for _ in range(12)])
burst('reverse_order', [checkout([p['reverse_a'], p['reverse_b']][::1 if i % 2 else -1]) for i in range(12)])
burst('void', [{'method': 'DELETE', 'url': f"/transaksi/{fixture['transaction']}", 'data': {'reason': 'Audit serentak', 'approval_pin': '1234'}} for _ in range(12)])
burst('adjust', [{'method': 'POST', 'url': '/gudang/adjust', 'data': {'product_id': p['adjust'], 'type': 'adjustment_out', 'quantity': 1, 'notes': 'Audit serentak'}} for _ in range(12)])
expected = {'stock': {200: 10, 422: 10}, 'deposit': {200: 5, 422: 7}, 'reverse_order': {200: 12}, 'void': {302: 1, 422: 11}, 'adjust': {302: 5, 422: 7}}
for row in report['scenarios']:
    counts = {status: sum(r['status'] == status for r in row['responses']) for status in set(r['status'] for r in row['responses'])}
    assert counts == expected[row['name']], (row['name'], counts)
state = report['scenarios'][-1]['state']
assert all(float(q) >= 0 for q in state['stocks'].values()), state
assert state['refunds'] == 1 and float(state['balance']) == 10000, state
assert state['completed'] == 27 and state['movements'] == 45, state
report['passed'] = True
(OUT / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print('REPORT: ' + str(OUT / 'report.json'))
