#!/usr/bin/env bash
set -e
sudo -n ssh -i /root/.ssh/ashat_agent_id_rsa -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/etc/ashat-ai/known_hosts opc@129.213.94.124 'sudo -n systemctl restart ashat-neural-host.service; sleep 6; cd /home/opc/Projects/ashatneuralhost-master && UPDATE_MODELS=1 bash scripts/seed_slave.sh opc@150.136.208.93 /home/opc/Projects/ashatneuralhost-slave 8082 && UPDATE_MODELS=1 bash scripts/seed_slave.sh opc@129.213.147.225 /home/opc/Projects/ashatneuralhost-slave 8088'
