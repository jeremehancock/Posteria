#!/bin/bash
set -e

# Start cron service
service cron start
echo "Started cron service for auto-import"

# Make sure the directories exist and have correct permissions
for dir in movies tv-shows tv-seasons collections; do
    directory="/var/www/html/posters/$dir"
    mkdir -p "$directory"
    chown -R www-data:www-data "$directory"
    chmod -R 775 "$directory"
done

# Create data directory for logs if it doesn't exist
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data
chmod -R 775 /var/www/html/data

# Start Apache in foreground
apache2-foreground
