#!/bin/bash
echo "=== killing instance :3002 ==="
PID=$(pgrep -f "port 3002")
echo "killing PID $PID"
kill -9 $PID
sleep 2
echo "instances right after kill (expect 2): $(pgrep -c llama-server)"
echo "waiting for supervision cycle (10s)..."
sleep 15
echo "instances after supervision (expect 3): $(pgrep -c llama-server)"
pgrep -a llama-server | grep -o "port [0-9]*" | sort
echo "=== is :3002 healthy again? ==="
for i in $(seq 1 30); do
  CODE=$(curl -s -m 2 -o /dev/null -w "%{http_code}" http://127.0.0.1:3002/health 2>/dev/null)
  if [ "$CODE" = "200" ]; then echo "respawned :3002 ready after ~$((i*2))s"; break; fi
  sleep 2
done
echo "=== final: one more chat through the gateway ==="
curl -s -m 120 -X POST http://127.0.0.1:3000/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{"messages":[{"role":"user","content":"Reply with exactly: all good"}],"max_tokens":16}' | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('choices',[{}])[0].get('message',{}).get('content'))"
