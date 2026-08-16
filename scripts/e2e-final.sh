#!/bin/bash
BASE="https://127.0.0.1"
HOST="Host: www.agpstudios.org"
JAR=/tmp/phase3-cookies.txt
CSRF=$(grep -o 'content="[a-f0-9]\{64\}"' /tmp/chat-page.html | head -1 | sed 's/content="//;s/"//')
AJ=(-sk -b $JAR -H "$HOST" -H "X-Requested-With: XMLHttpRequest" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json")

echo "=== 1. FILES ON DISK (apache-writable now) ==="
ls -la /var/oled/data/AshatHub/modules/AshatHub/projects/p3test1786239463/ 2>/dev/null
echo "--- Spec.md (first 12 lines) ---"
head -12 /var/oled/data/AshatHub/modules/AshatHub/projects/p3test1786239463/Spec.md 2>/dev/null

echo "=== 2. EXPLORER (/api/files/) ==="
curl "${AJ[@]}" "$BASE/api/files/" | python3 -c "
import json,sys
d=json.load(sys.stdin)
for f in d['files']:
    print(' ', f['path'], '| size:', f.get('size'), '| id:', 'yes' if f.get('id') else 'no', '| generated:', f.get('generated'))
print('usage:', d['usage_bytes'], 'bytes')
"

echo "=== 3. BUILD GATE: user WITHOUT docs -> build_locked ==="
JAR2=/tmp/phase3-cookies2.txt
curl -sk -b $JAR2 -c $JAR2 "$BASE/chat" -H "$HOST" -o /dev/null
CSRF2=$(grep -o 'content="[a-f0-9]\{64\}"' /tmp/chat-page.html | head -1 | sed 's/content="//;s/"//')
OUT=$(curl "${AJ[@]}" -N -m 20 "$BASE/api/build/pipeline/" -X POST -d '{"spec":"# Test\n\nBuild something tiny."}')
echo "$OUT" | grep -o '"message":"[^"]*"' | head -1

echo "=== 4. GATE PASSES for user WITH docs (spec only check — expect progress events, not build_locked) ==="
OUT2=$(curl "${AJ[@]}" -N -m 15 "$BASE/api/build/pipeline/" -X POST -d '{"spec":"# Test\n\nBuild a hello world page."}')
echo "$OUT2" | grep -o 'event: [a-z]*' | sort | uniq -c | head -5
echo "$OUT2" | grep -c "build_locked" | xargs echo "build_locked occurrences (expect 0):"
