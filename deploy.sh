#!/bin/bash

set -e

echo "=================================="
echo "DEPLOY - MOJE FAKTURACE"
echo "=================================="

echo ""
echo "1. Stahuji změny z GitHubu..."
git pull

echo ""
echo "2. Spouštím centrální migrace..."
php artisan migrate --database=central --path=database/migrations/central --force

echo ""
echo "3. Spouštím business migrace..."
php artisan app:migrate-businesses --force

echo ""
echo "4. Obnovuji cache..."
php artisan optimize:clear

echo ""
echo "=================================="
echo "DEPLOY ÚSPĚŠNĚ DOKONČEN"
echo "=================================="
