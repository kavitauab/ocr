#!/bin/bash
set -e

MSG=${1:-"deploy"}

echo "Cleaning old dist..."
rm -rf dist/assets dist/api

echo "Building frontend..."
npm run build

echo "Copying API to dist..."
mkdir -p dist/api
cp -r api/* dist/api/
rm -f dist/api/.env

echo "Restoring production .env..."
git checkout dist/api/.env 2>/dev/null || echo "Warning: dist/api/.env not in git"

echo "Copying .htaccess..."
cp .htaccess dist/

echo "Committing and pushing..."
git add -A
git commit -m "$MSG" || echo "Nothing to commit"

if ! git push; then
    echo "Standard push failed, retrying via SSH port 443..."
    GIT_SSH_COMMAND='ssh -o Hostname=ssh.github.com -p 443 -o StrictHostKeyChecking=accept-new' git push
fi

echo "Done!"
