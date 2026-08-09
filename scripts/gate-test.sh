#!/bin/bash
BASE="https://127.0.0.1"
HOST="Host: www.agpstudios.org"
# Fresh login for the gate user
JAR2=/tmp/phase3-cookies2.txt
rm -f $JAR2
curl -sk -c $JAR2 "$BASE/login" -H "$HOST" -o /tmp/gate-login.html
CSRF=$(grep -o 'name="_csrf" value="[^"]*"' /tmp/gate-login.html | head -1 | sed 's/.*value="//;s/"//')
UN2=$(sudo mariadb ashathub -N -e "SELECT username FROM users WHERE username LIKE 'p3gate%' ORDER BY created_at DESC LIMIT 1;")
curl -sk -b $JAR2 -c $JAR2 "$BASE/login" -H "$HOST" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "_csrf=$CSRF" \
  --data-urlencode "username=$UN2" \
  --data-urlencode "password=TestPass123!" -o /dev/null
# Get page + csrf in same session
curl -sk -b $JAR2 -c $JAR2 "$BASE/chat" -H "$HOST" -o /tmp/gate-chat.html
CSRF2=$(grep -o 'content="[a-f0-9]\{64\}"' /tmp/gate-chat.html | head -1 | sed 's/content="//;s/"//')
echo "=== pipeline for no-docs user (expect build_locked error event) ==="
curl -sk -b $JAR2 -H "$HOST" -H "X-Requested-With: XMLHttpRequest" -H "X-CSRF-Token: $CSRF2" -H "Content-Type: application/json" \
  -N -m 20 "$BASE/api/build/pipeline/" -X POST -d '{"spec":"# Test\n\nBuild something tiny."}' | head -c 400
echo
echo "=== their file list (expect empty) ==="
curl -sk -b $JAR2 -H "$HOST" -H "X-Requested-With: XMLHttpRequest" -H "X-CSRF-Token: $CSRF2" "$BASE/api/files/" | head -c 200; echo
