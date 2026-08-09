#!/bin/bash
# Concurrency + queue + respawn verification
OUT=/tmp/phase2
rm -rf $OUT && mkdir -p $OUT

fire() { # $1 = name, $2 = prompt
  (time curl -s -m 300 -X POST http://127.0.0.1:3000/v1/chat/completions \
    -H "Content-Type: application/json" \
    -d "{\"messages\":[{\"role\":\"user\",\"content\":\"$2\"}],\"max_tokens\":120}" \
    -o $OUT/$1.out -w "wall:%{time_total}s http:%{http_code}" > $OUT/$1.time 2>&1) &
}

echo "=== firing 3 concurrent chats ==="
fire c1 "Write a 6-line poem about the ocean."
fire c2 "Write a 6-line poem about winter."
fire c3 "Write a 6-line poem about mountains."
sleep 3
echo "llama instances mid-flight (expect 3): $(pgrep -c llama-server)"
pgrep -a llama-server | grep -o "port [0-9]*" | sort
echo "=== firing 4th concurrent (should queue, NOT spawn a 4th instance) ==="
fire c4 "Write a 6-line poem about the desert."
sleep 3
echo "llama instances with 4th in flight (expect still 3): $(pgrep -c llama-server)"
echo "=== waiting for all ==="
wait
for c in c1 c2 c3 c4; do
  echo "$c: $(cat $OUT/$c.time) -> $(python3 -c "import json; d=json.load(open('$OUT/$c.out')); print(d.get('choices',[{}])[0].get('message',{}).get('content','')[:40])" 2>/dev/null || echo FAIL)"
done
echo "=== RAM after 3-instance run ==="
free -h | head -2
