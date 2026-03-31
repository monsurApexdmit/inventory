#!/bin/bash

set -e

echo "🚀 Setting up Laravel 12 Project"
echo "=================================="

# Build Docker images
echo "📦 Building Docker images..."
docker compose build

# Start containers
echo "🐳 Starting containers..."
docker compose up -d

# Wait for services
echo "⏳ Waiting for services to be ready..."
sleep 10

# Create Laravel project directly in the working directory
echo "🎨 Creating Laravel 12 project..."
docker compose exec -T app composer create-project laravel/laravel /tmp/laravel-new
docker compose exec -T app bash -c "cp -r /tmp/laravel-new/. /var/www/ && rm -rf /tmp/laravel-new"

# Generate app key
echo "🔑 Generating application key..."
docker compose exec app php artisan key:generate

# Create .env file
echo "⚙️  Setting up environment..."
docker compose exec app bash -c "cp .env.example .env"

# Update .env for Docker
docker compose exec app bash -c "sed -i 's/DB_HOST=127.0.0.1/DB_HOST=mysql/' .env && sed -i 's/DB_DATABASE=laravel/DB_DATABASE=laravel/' .env && sed -i 's/DB_USERNAME=root/DB_USERNAME=laravel/' .env && sed -i 's/DB_PASSWORD=/DB_PASSWORD=laravel_password/' .env"

# Run migrations
echo "🗄️  Running migrations..."
docker compose exec app php artisan migrate

# Create storage link
echo "🔗 Creating storage symlink..."
docker compose exec app php artisan storage:link

# Clear cache
echo "🧹 Clearing caches..."
docker compose exec app php artisan cache:clear && docker compose exec app php artisan config:clear && docker compose exec app php artisan route:clear

echo ""
echo "✅ Setup complete!"
echo ""
echo "🌐 Application is ready at: http://localhost:8005"
echo "📊 Database: localhost:3306 (user: laravel, password: laravel_password)"
echo "🔴 Redis: localhost:6379"
echo ""
echo "📁 Project location: /home/monsur/Documents/Go/inventory-laravel"
echo ""
echo "🎯 Next steps:"
echo "  1. cd inventory-laravel"
echo "  2. Customize your application"
echo "  3. Run: docker compose exec app bash (to enter container)"
echo ""
