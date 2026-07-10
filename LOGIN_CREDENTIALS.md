# Login Credentials - Inventory Management System

**Generated:** 2026-04-01

---

## Quick Start

### Base URL
```
http://localhost:8005/api
```

### Login Endpoint
```
POST /auth/login
```

---

## Test Users

### 1. Admin/Owner User (SaaS)

**Email:** `admin@example.com`  
**Password:** `password123`  
**Role:** Owner/Admin  
**Company:** Demo Company  

**Login Request:**
```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "password123"
  }'
```

**Success Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "email": "admin@example.com",
      "name": "Admin User",
      "company_id": 1
    },
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 86400
  }
}
```

---

### 2. Manager User

**Email:** `manager@example.com`  
**Password:** `password123`  
**Role:** Manager  
**Company:** Demo Company  

**Login Request:**
```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "manager@example.com",
    "password": "password123"
  }'
```

---

### 3. Staff User

**Email:** `staff@example.com`  
**Password:** `password123`  
**Role:** Staff  
**Company:** Demo Company  

**Login Request:**
```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "staff@example.com",
    "password": "password123"
  }'
```

---

### 4. Viewer/Guest User

**Email:** `viewer@example.com`  
**Password:** `password123`  
**Role:** Viewer  
**Company:** Demo Company  

**Login Request:**
```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "viewer@example.com",
    "password": "password123"
  }'
```

---

## Database Credentials

### MySQL Database

**Host:** `central_mysql` (or `localhost`)  
**Port:** `3306`  
**Database:** `inventory_laravel`  
**Username:** `root`  
**Password:** `root`  

**Connection String:**
```
mysql://root:root@localhost:3306/inventory_laravel
```

**Command Line Access:**
```bash
mysql -h localhost -u root -proot inventory_laravel
```

---

## Application Credentials

### Laravel Environment

**File:** `.env`

```
APP_NAME="Inventory System"
APP_ENV=local
APP_KEY=base64:YOUR_APP_KEY_HERE
APP_DEBUG=true
APP_URL=http://localhost:8005

DB_CONNECTION=mysql
DB_HOST=central_mysql
DB_PORT=3306
DB_DATABASE=inventory_laravel
DB_USERNAME=root
DB_PASSWORD=root

JWT_SECRET=your_jwt_secret_key_here
JWT_ALGORITHM=HS256
JWT_EXPIRES_IN=86400
```

---

## JWT Token Usage

### Getting a Token

After login, you'll receive a JWT token:

```json
{
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkFkbWluIFVzZXIiLCJpYXQiOjE2NzY4MDAwMDB9.TJVA95OrM7E2cBab30RMHrHDcEfxjoYZgeFONFh7HgQ"
}
```

### Using the Token

Add to Authorization header:

```bash
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

### Token Expiration

**Default expiration:** 24 hours (86400 seconds)

Refresh token or re-login if expired.

---

## Testing Different Endpoints

### With Admin Token

```bash
# Get current user info
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer ADMIN_TOKEN"

# List all staff
curl -X GET http://localhost:8005/api/staff \
  -H "Authorization: Bearer ADMIN_TOKEN"

# List all products
curl -X GET http://localhost:8005/api/products \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Get company profile
curl -X GET http://localhost:8005/api/auth/company/profile \
  -H "Authorization: Bearer ADMIN_TOKEN"

# List billing subscription
curl -X GET http://localhost:8005/api/billing/subscription \
  -H "Authorization: Bearer ADMIN_TOKEN"
```

---

## Create New User (Admin Only)

### Via API

```bash
curl -X POST http://localhost:8005/api/users \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "newuser@example.com",
    "username": "newuser",
    "password": "SecurePassword123!",
    "role_id": 3
  }'
```

### Via Database

```sql
INSERT INTO users (email, username, password, role_id, created_at, updated_at) 
VALUES (
  'newuser@example.com',
  'newuser',
  '$2y$10$HASH_OF_PASSWORD',
  3,
  NOW(),
  NOW()
);
```

---

## Reset Password

### Via Email (Forgot Password Flow)

```bash
# Step 1: Request password reset
curl -X POST http://localhost:8005/api/auth/forgot-password \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@example.com"}'

# Step 2: Reset with token (from email)
curl -X POST http://localhost:8005/api/auth/reset-password \
  -H "Content-Type: application/json" \
  -d '{
    "token": "RESET_TOKEN_FROM_EMAIL",
    "email": "admin@example.com",
    "password": "NewPassword123!",
    "password_confirmation": "NewPassword123!"
  }'
```

### Via Authenticated Request (Change Own Password)

```bash
curl -X POST http://localhost:8005/api/auth/update-password \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "current_password": "password123",
    "new_password": "NewPassword123!",
    "new_password_confirmation": "NewPassword123!"
  }'
```

---

## SaaS Registration

### Sign Up New Account

```bash
curl -X POST http://localhost:8005/api/auth/signup \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@company.com",
    "password": "SecurePassword123!",
    "password_confirmation": "SecurePassword123!",
    "company_name": "Acme Corporation"
  }'
```

### Verify Email

```bash
curl -X POST http://localhost:8005/api/auth/verify-email \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john.doe@company.com",
    "verification_code": "CODE_FROM_EMAIL"
  }'
```

### Resend Verification

```bash
curl -X POST http://localhost:8005/api/auth/resend-verification \
  -H "Content-Type: application/json" \
  -d '{"email": "john.doe@company.com"}'
```

---

## Team Invitations

### Invite Team Member (Admin Only)

```bash
curl -X POST http://localhost:8005/api/auth/team/invite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "newmember@example.com",
    "role": "manager"
  }'
```

### Accept Invitation (Public)

```bash
curl -X POST http://localhost:8005/api/auth/accept-invitation \
  -H "Content-Type: application/json" \
  -d '{
    "token": "INVITATION_TOKEN",
    "password": "NewUserPassword123!",
    "password_confirmation": "NewUserPassword123!"
  }'
```

---

## Demo Data Access

### Sample Companies

| ID | Name | Status |
|----|------|--------|
| 1 | Demo Company | active |
| 2 | Test Company | trial |

### Sample Products

| ID | Name | Price | Stock |
|----|------|-------|-------|
| 1 | Laptop | $999.99 | 50 |
| 2 | Desktop | $1,299.99 | 30 |
| 3 | Monitor | $299.99 | 75 |
| 4 | Keyboard | $79.99 | 200 |
| 5 | Mouse | $49.99 | 300 |

### Sample Vendors

| ID | Name | Email | Phone |
|----|------|-------|-------|
| 1 | Tech Supplies Inc | contact@techsupplies.com | 555-0101 |
| 2 | Electronics Plus | sales@elecplus.com | 555-0102 |

### Sample Customers

| ID | Name | Email | Phone |
|----|------|-------|-------|
| 1 | Alice Johnson | alice@example.com | 555-1001 |
| 2 | Bob Smith | bob@example.com | 555-1002 |
| 3 | Carol White | carol@example.com | 555-1003 |

---

## API Rate Limiting

**Default Rate Limit:** 60 requests per minute per IP

**Headers in Response:**
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1234567890
```

---

## Common Error Responses

### 401 Unauthorized (Missing/Invalid Token)

```json
{
  "success": false,
  "message": "Unauthorized",
  "errors": {
    "token": ["Invalid or expired token"]
  }
}
```

**Fix:** Include valid JWT token in Authorization header

### 422 Validation Error

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["Email is required"],
    "password": ["Password must be at least 8 characters"]
  }
}
```

**Fix:** Check required fields and validation rules

### 404 Not Found

```json
{
  "success": false,
  "message": "Resource not found"
}
```

**Fix:** Verify resource ID and endpoint path

---

## Testing Tools

### Postman Collection

**File:** `postman_collection.json`

Import in Postman:
1. Open Postman
2. Click "Import"
3. Select the collection file
4. Set `{{base_url}}` variable to `http://localhost:8005/api`
5. Set `{{token}}` variable after login

### cURL

All examples in this document use cURL. Install:

```bash
# macOS
brew install curl

# Ubuntu/Debian
sudo apt-get install curl

# Windows
choco install curl
```

### Thunder Client (VS Code)

Install extension and import collection for quick testing.

---

## Security Notes

⚠️ **Important:**

1. **Never share JWT tokens** - Treat them like passwords
2. **Use HTTPS in production** - Not just HTTP
3. **Rotate secrets regularly** - Change JWT_SECRET in production
4. **Use strong passwords** - Minimum 8 characters with mixed case
5. **Enable email verification** - Verify users before granting access
6. **Implement 2FA** - For admin accounts
7. **Rate limiting** - Protect against brute force attacks
8. **CORS configuration** - Only allow trusted origins

---

## Environment Setup

### Development

```bash
# Clone repository
git clone <repo-url>
cd inventory-laravel

# Install dependencies
composer install
npm install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# Seed demo data
php artisan db:seed

# Start development server
php artisan serve --host=localhost --port=8005

# In another terminal, compile assets
npm run dev
```

### Docker Setup

```bash
# Start containers
docker-compose up -d

# Run migrations
docker-compose exec app php artisan migrate

# Seed data
docker-compose exec app php artisan db:seed

# Access application
http://localhost:8005
```

---

## Troubleshooting

### Can't Login

1. **Check credentials** - Verify email and password are correct
2. **Check database** - Ensure user exists in database
3. **Check JWT** - Ensure JWT_SECRET is set in .env
4. **Check email** - If email not verified, verify first

### Token Expired

**Error:** `Token has expired`

**Solution:** Get new token by logging in again

### Connection Refused

**Error:** `Connection refused`

**Solution:**
1. Check if server is running: `php artisan serve`
2. Check port 8005 is not in use
3. Check database connection in .env

### Database Error

**Error:** `Could not find driver`

**Solution:**
1. Install MySQL: `brew install mysql` (macOS) or `apt-get install mysql-server` (Linux)
2. Ensure MySQL is running
3. Check DB credentials in .env

---

## Support

**Documentation:** http://localhost:8005/docs (if available)  
**API Collection:** See `API_ROUTES.md`  
**Issues:** Report bugs in GitHub issues

---

**Last Updated:** 2026-04-01  
**Version:** 1.0.0
