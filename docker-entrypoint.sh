#!/bin/bash
set -e

# Check if cron is already running and stop it if needed
if [ -f /var/run/crond.pid ]; then
    echo "Removing stale cron PID file..."
    rm -f /var/run/crond.pid
fi

# Save environment variables to a file that will be accessible to cron jobs
env | grep -E '^(PLEX_|AUTO_IMPORT_|AUTH_)' > /var/www/html/include/docker-env.sh
chmod 600 /var/www/html/include/docker-env.sh
sed -i 's/^/export /' /var/www/html/include/docker-env.sh

# Create auto-import.sh script
cat > /var/www/html/include/auto-import.sh << 'EOL'
#!/bin/bash

# Set path
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Change to the correct directory
cd /var/www/html

# Source environment variables from saved file
if [ -f /var/www/html/include/docker-env.sh ]; then
  source /var/www/html/include/docker-env.sh
else
  echo "$(date) - ERROR: Environment file not found!" >> /var/www/html/data/auto-import-cron.log
fi

# Output environment for debugging
echo "$(date) - Running auto-import with environment:" >> /var/www/html/data/auto-import-cron.log
env | grep -E '^(PLEX_|AUTO_IMPORT_)' >> /var/www/html/data/auto-import-cron.log

# Run the PHP script
/usr/local/bin/php include/auto-import.php >> /var/www/html/data/auto-import-cron.log 2>&1
EOL

# Make script executable
chmod +x /var/www/html/include/auto-import.sh

# Add the job directly to root's crontab with correct formatting
echo "*/5 * * * * /var/www/html/include/auto-import.sh" | crontab -

# Setup proper crontab file as a backup method with correct formatting
echo "# Posteria auto-import cron job" > /etc/cron.d/posteria-autoimport
echo "*/5 * * * * root /var/www/html/include/auto-import.sh" >> /etc/cron.d/posteria-autoimport
chmod 0644 /etc/cron.d/posteria-autoimport

# Display crontab for verification
echo "Installed crontab:"
crontab -l

# Start cron service properly
echo "Starting cron service..."
cron -f &
CRON_PID=$!
echo "Cron started with PID: $CRON_PID"

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
