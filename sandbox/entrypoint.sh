#!/bin/sh
set -e

cd /app

echo "Installing inclitoleo/dbmysql via Composer (path repository /package)..."
composer install --no-interaction --no-progress

if [ ! -f vendor/inclitoleo/dbmysql/composer.json ]; then
  echo "Composer did not install inclitoleo/dbmysql into vendor/."
  exit 1
fi

echo "Waiting for MySQL..."
i=0
until php -r 'try { new PDO("mysql:host=" . getenv("MYSQL_HOST") . ";port=" . getenv("MYSQL_PORT") . ";dbname=" . getenv("MYSQL_DATABASE"), getenv("MYSQL_USER"), getenv("MYSQL_PASSWORD")); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }'; do
  i=$((i + 1))
  if [ "$i" -ge 30 ]; then
    echo "MySQL did not become ready."
    exit 1
  fi
  sleep 2
done

echo "Running CRUD smoke test..."
php crud.php

echo "CRUD lab UI: http://localhost:8088"
exec php -S 0.0.0.0:8080 -t /app/public
