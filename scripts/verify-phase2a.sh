#!/bin/bash
# Phase 2 verification
echo "=== restart service ==="
sudo systemctl restart ashat-ai
sleep 5
echo "=== llama processes after start (expect 1: min instance on :3001) ==="
pgrep -a llama-server
echo "=== wait for instance 0 to load ==="
for i in $(seq 1 60); do
  sleep 2
  CODE=$(curl -s -m 2 -o /dev/null -w "%{http_code}" http://127.0.0.1:3001/health 2>/dev/null)
  if [ "$CODE" = "200" ]; then echo "instance :3001 ready after ~$((i*2))s"; break; fi
done
echo "=== single chat through the gateway (local path) ==="
curl -s -m 120 -X POST http://127.0.0.1:3000/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{"messages":[{"role":"user","content":"Answer with exactly: pool works"}],"max_tokens":32}' | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('choices',[{}])[0].get('message',{}).get('content'))"
echo "=== instance count after single chat (expect 1) ==="
pgrep -c llama-server
echo "=== RAM before concurrency test ==="
free -h | head -2
