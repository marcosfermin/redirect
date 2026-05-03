#!/bin/bash
set -e

# Wait for database to be ready
until php -r "new mysqli('${DB_HOST}', '${DB_USER}', '${DB_PASS}', '${DB_NAME}');" 2>/dev/null; do
    echo "Waiting for database connection..."
    sleep 2
done

echo "Database connection established"

# Add country column to completions if it doesn't exist
php -r "
\$conn = new mysqli('${DB_HOST}', '${DB_USER}', '${DB_PASS}', '${DB_NAME}');
\$result = \$conn->query(\"SHOW COLUMNS FROM completions LIKE 'country'\");
if (\$result && \$result->num_rows === 0) {
    \$conn->query(\"ALTER TABLE completions ADD COLUMN country VARCHAR(2) DEFAULT NULL AFTER user_agent\");
    echo 'Added country column to completions table' . PHP_EOL;
}
\$conn->close();
" 2>/dev/null || true

# Ensure uploads directory exists with correct permissions
mkdir -p /var/www/html/uploads/thumbs
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads

exec "$@"
