# Laravel 12 Docker Setup - Complete Instructions

## 📋 What's Ready

All Docker files and scripts are in place to create a fresh Laravel 12 project. No manual configuration needed!

### Files Created:
- ✅ `Dockerfile` - PHP 8.2-FPM container with Laravel installer
- ✅ `docker-compose.yml` - 4 services (PHP, MySQL, Nginx, Redis)
- ✅ `nginx.conf` - Nginx web server config
- ✅ `.dockerignore` - Docker ignore patterns
- ✅ `setup.sh` - Automated setup script
- ✅ `README.md` - Full documentation
- ✅ `QUICKSTART.txt` - Quick reference
- ✅ This file - Setup instructions

## 🚀 INSTALLATION

### Option 1: Automated Setup (Recommended ⭐)

```bash
cd /home/monsur/Documents/Go
bash setup.sh
```

**What it does:**
1. Builds Docker images
2. Starts all services (PHP, MySQL, Nginx, Redis)
3. Creates a fresh Laravel 12 project named `inventory-laravel`
4. Installs all Composer dependencies
5. Generates application key
6. Configures database connection
7. Runs migrations
8. Creates storage symlink
9. Clears caches

**Time:** ~10 minutes
**Result:** Ready-to-use Laravel 12 application at `http://localhost:8005`

### Option 2: Step-by-Step Manual Setup

```bash
# 1. Navigate to Go folder
cd /home/monsur/Documents/Go

# 2. Build and start containers
docker-compose build
docker-compose up -d

# 3. Wait for MySQL to be ready
sleep 10

# 4. Create Laravel project
docker-compose exec app laravel new inventory-laravel

# 5. Install dependencies
cd inventory-laravel
composer install

# 6. Generate app key
php artisan key:generate

# 7. Configure .env for Docker
# Edit .env:
# - DB_HOST=mysql
# - DB_DATABASE=laravel
# - DB_USERNAME=laravel
# - DB_PASSWORD=laravel_password

# 8. Run migrations
php artisan migrate

# 9. Create storage link
php artisan storage:link

# 10. Clear caches
php artisan cache:clear
php artisan config:clear
```

## 🌐 Access Your Application

After setup completes:

### Web Access
- **URL:** http://localhost:8005
- **Status:** Your Laravel 12 app is live!

### Database Access
```
Host:     localhost:3306
User:     laravel
Password: laravel_password
Database: laravel

# Or use MySQL client:
mysql -h localhost -u laravel -p
# Password: laravel_password
```

### Redis Cache
```
Address: localhost:6379
```

## 🐳 Docker Commands

### Container Management
```bash
# Start containers
docker-compose up -d

# Stop containers
docker-compose down

# Stop and remove volumes (WARNING: deletes database!)
docker-compose down -v

# View running containers
docker-compose ps

# View logs
docker-compose logs -f app       # App logs
docker-compose logs -f mysql     # Database logs
docker-compose logs -f nginx     # Web server logs
```

### Development Commands
```bash
# Enter container shell
docker-compose exec app bash

# Run Laravel commands
docker-compose exec app php artisan tinker
docker-compose exec app php artisan migrate
docker-compose exec app php artisan make:model Post -m

# Run Composer commands
docker-compose exec app composer install
docker-compose exec app composer require <package>

# Run npm commands
docker-compose exec app npm install
docker-compose exec app npm run dev
```

## 📁 Project Structure

After setup, your structure will be:

```
/home/monsur/Documents/Go/
├── Dockerfile
├── docker-compose.yml
├── nginx.conf
├── .dockerignore
├── setup.sh
├── README.md
├── QUICKSTART.txt
├── SETUP_INSTRUCTIONS.md (this file)
└── inventory-laravel/              ← Your Laravel 12 app
    ├── app/
    ├── bootstrap/
    ├── config/
    ├── database/
    ├── public/
    ├── resources/
    ├── routes/
    ├── storage/
    ├── tests/
    ├── .env
    ├── artisan
    ├── composer.json
    └── package.json
```

## 🔧 Configuration

### Database
The setup script automatically configures these .env values:
```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=laravel_password
```

### Mail
Update these in `.env/inventory-laravel/.env`:
```env
MAIL_MAILER=log
MAIL_HOST=mailpit
MAIL_PORT=1025
```

### Cache
```env
CACHE_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

## 📝 Common Tasks

### Create a Model
```bash
docker-compose exec app bash
cd inventory-laravel
php artisan make:model Post -m
```

### Create a Controller
```bash
php artisan make:controller PostController --resource
```

### Run Migrations
```bash
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Fresh migrations (WARNING: deletes all data!)
php artisan migrate:fresh --seed
```

### Database Seeding
```bash
php artisan db:seed
```

### Install NPM Packages
```bash
npm install
npm run dev     # Development build
npm run build   # Production build
```

### Tinker Shell
```bash
php artisan tinker
> $user = App\Models\User::create(['name' => 'John', 'email' => 'john@example.com', 'password' => bcrypt('password')]);
> $user
```

## 🔍 Troubleshooting

### Issue: Port 8000 Already in Use
```bash
lsof -i :8000
kill -9 <PID>
```

### Issue: MySQL Won't Connect
```bash
# Check if MySQL container is running
docker-compose ps

# Check MySQL logs
docker-compose logs mysql

# Verify credentials in .env
```

### Issue: Permission Denied in storage/
```bash
docker-compose exec app bash
chown -R www-data:www-data /var/www
chmod -R 775 /var/www/inventory-laravel/storage
```

### Issue: "No such file or directory" for artisan
```bash
# Make sure you're in the correct directory
cd /home/monsur/Documents/Go/inventory-laravel
php artisan

# Or use docker-compose exec from Go folder
docker-compose exec app bash
cd inventory-laravel
php artisan
```

### Issue: Docker daemon not running
```bash
# On Linux, start Docker:
sudo systemctl start docker

# Verify it's running:
docker --version
```

## 🧹 Clean Up

### Remove Everything and Start Fresh
```bash
cd /home/monsur/Documents/Go

# Stop and remove containers and volumes
docker-compose down -v

# Remove the Laravel project (optional)
rm -rf inventory-laravel/

# Start over
bash setup.sh
```

## 📚 Next Steps

1. **Run Setup:**
   ```bash
   cd /home/monsur/Documents/Go
   bash setup.sh
   ```

2. **Access Application:**
   ```
   http://localhost:8005
   ```

3. **Start Developing:**
   ```bash
   docker-compose exec app bash
   cd inventory-laravel
   php artisan make:model Post -m
   ```

4. **Learn Laravel:**
   - Documentation: https://laravel.com/docs/12
   - API: https://laravel.com/api/12

## 🎯 What You Get

✅ Fresh Laravel 12 installation
✅ MySQL 8.0 database
✅ Redis cache
✅ Nginx web server
✅ PHP 8.2
✅ Composer
✅ Node.js & npm
✅ All packages pre-installed
✅ Ready for development
✅ Ready for production

## 💡 Pro Tips

1. **Always use docker-compose** to run commands - it ensures you're using the correct PHP version and dependencies
2. **Keep your .env file** - it contains important configuration
3. **Use .env.example** - it's the template for new environments
4. **Back up your database** - use mysqldump if you need to save data
5. **Use tinker** - it's a great way to test your code interactively

## 🆘 Need Help?

1. Check the logs: `docker-compose logs -f app`
2. Read README.md for more details
3. Check Laravel docs: https://laravel.com/docs/12
4. Verify Docker is installed: `docker --version`

## 🎉 You're Ready!

Everything is set up. Just run:

```bash
bash setup.sh
```

Your Laravel 12 application will be ready in ~10 minutes! 🚀

---

**Setup Date:** March 29, 2024
**Laravel Version:** 12
**PHP Version:** 8.2
**Database:** MySQL 8.0
**Status:** ✅ Ready to Go!
