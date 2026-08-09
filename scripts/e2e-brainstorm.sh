#!/bin/bash
BASE="https://127.0.0.1"
HOST="Host: www.agpstudios.org"
JAR=/tmp/phase3-cookies.txt
curl -sk -b $JAR -c $JAR "$BASE/chat" -H "$HOST" -o /tmp/chat-page.html
CSRF=$(grep -o 'content="[a-f0-9]\{64\}"' /tmp/chat-page.html | head -1 | sed 's/content="//;s/"//')
AJ=(-sk -b $JAR -H "$HOST" -H "X-Requested-With: XMLHttpRequest" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json")

echo "=== BRAINSTORM (SSE via Omega) — may take 1-3 min ==="
curl "${AJ[@]}" -N -m 400 "$BASE/api/brainstorm/" -X POST -d '{"idea":"A tiny web app that tracks daily water intake with a streak counter."}' -o /tmp/brainstorm-sse.txt -w "http:%{http_code} time:%{time_total}s\n"
echo "--- SSE event summary ---"
grep -c "^event:" /tmp/brainstorm-sse.txt | xargs echo "events:"
grep "^event:" /tmp/brainstorm-sse.txt | sort | uniq -c
echo "--- done payload ---"
python3 -c "
import json
txt = open('/tmp/brainstorm-sse.txt').read()
# find the done event
import re
blocks = re.split(r'\n\n', txt)
for b in blocks:
    if 'event: done' in b:
        data = '\n'.join(l[6:] for l in b.splitlines() if l.startswith('data:'))
        d = json.loads(data)
        print('ok:', d.get('ok'), 'paths:', d.get('paths'))
        print('spec len:', len(d.get('spec','')), '| build len:', len(d.get('build','')))
        print('spec head:', (d.get('spec') or '')[:100].replace(chr(10),' '))
        break
"
