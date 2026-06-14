#!/bin/bash
# Run this ON the TigerTech server after SSH'ing in manually:
#   ssh aneudymota.com@aneudymota.com
#   (enter password)
#   bash setup-ssh-key.sh

set -e

mkdir -p ~/.ssh
chmod 700 ~/.ssh

echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGh2uxWk29ykVWAWwv4c+vdbI4OG462JVyhUwWu/PWAc aneudymota.com@tigertech" >> ~/.ssh/authorized_keys

# Remove duplicates if script is run multiple times
sort -u ~/.ssh/authorized_keys -o ~/.ssh/authorized_keys

chmod 600 ~/.ssh/authorized_keys

echo "SSH key installed. You can now connect with:"
echo "  ssh -i ~/.ssh/tigertech_aneudymota aneudymota.com@aneudymota.com"
