#!/bin/bash

# Source environment variables from saved file
if [ -f /app/docker-env.sh ]; then
  source /app/docker-env.sh
else
  echo "$(date) - ERROR: Environment file not found!" >> /config/data/auto-import-cron.log
  exit 1
fi

# Output environment for debugging
echo "$(date) - Running auto-import with environment:" >> /config/data/auto-import-cron.log
env | grep -E '^(PLEX_|AUTO_IMPORT_)' >> /config/data/auto-import-cron.log

# Change to the correct directory
cd /app/www/public || exit

# Run the PHP script
php include/auto-import.php >> /config/data/auto-import-cron.log 2>&1
