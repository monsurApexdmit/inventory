# Quick Start - Login Credentials

**Status:** ✅ All test users created and verified

---

## 🎯 Test Credentials - Ready to Use

### Admin User
- **Email:** `admin@example.com`
- **Password:** `password123`
- **Status:** ✅ Working

### Manager User
- **Email:** `manager@example.com`
- **Password:** `password123`
- **Status:** ✅ Working

### Staff User
- **Email:** `staff@example.com`
- **Password:** `password123`
- **Status:** ✅ Working

### Viewer User
- **Email:** `viewer@example.com`
- **Password:** `password123`
- **Status:** ✅ Working

---

## 📝 How to Login

### Using cURL

```bash
curl -X POST http://localhost:8005/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "password123"
  }'
```

### Response

```json
{
  "message": "Login successful",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expires": "2026-04-02T12:54:54+00:00"
}
```

---

## 🔑 Using the Token

After login, use the token for authenticated requests:

```bash
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

**Example with real token:**

```bash
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0OjgwMDUvYXBpL2xvZ2luIiwiaWF0IjoxNzc1MDQ4MDk0LCJleHAiOjE3NzUxMzQ0OTQsIm5iZiI6MTc3NTA0ODA5NCwianRpIjoiVG9vYTR3bW95QU9WSUtNbiIsInN1YiI6IjYiLCJwcnYiOiIyM2JkNWM4OTQ5ZjYwMGFkYjM5ZTcwMWM0MDA4NzJkYjdhNTk3NmY3In0.ETymEwnZ5Ze6FQyGTSjeY83CLIJ4pSvyMinXmPnpxms"
```

---

## 🧪 Test Various Endpoints

### Get Current User Profile

```bash
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."

curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer $TOKEN"
```

### List Products

```bash
curl -X GET http://localhost:8005/api/products \
  -H "Authorization: Bearer $TOKEN"
```

### List Customers

```bash
curl -X GET http://localhost:8005/api/customers \
  -H "Authorization: Bearer $TOKEN"
```

### List Staff Members

```bash
curl -X GET http://localhost:8005/api/staff \
  -H "Authorization: Bearer $TOKEN"
```

### List Vendors

```bash
curl -X GET http://localhost:8005/api/vendors \
  -H "Authorization: Bearer $TOKEN"
```

---

## 📊 Database Information

**Host:** localhost:3306  
**Database:** inventory_laravel  
**Username:** root  
**Password:** root  

**MySQL Connection:**

```bash
mysql -h localhost -u root -proot inventory_laravel
```

**Check users:**

```sql
SELECT id, email, username, created_at FROM users WHERE email LIKE '%@example.com';
```

---

## 🚀 Sample API Test Sequence

1. **Login to get token**
   ```bash
   curl -X POST http://localhost:8005/api/login \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@example.com","password":"password123"}'
   ```

2. **Save token to variable**
   ```bash
   TOKEN="your_token_here"
   ```

3. **Get current user**
   ```bash
   curl -X GET http://localhost:8005/api/auth/me \
     -H "Authorization: Bearer $TOKEN"
   ```

4. **List all products**
   ```bash
   curl -X GET http://localhost:8005/api/products \
     -H "Authorization: Bearer $TOKEN"
   ```

5. **Create new customer**
   ```bash
   curl -X POST http://localhost:8005/api/customers \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "name": "John Doe",
       "email": "john@example.com",
       "phone": "555-1234"
     }'
   ```

---

## 🛠️ Troubleshooting

### Login Returns "Invalid email or password"

- ✅ Verify email and password are correct
- ✅ Check database: `SELECT * FROM users WHERE email='admin@example.com';`
- ✅ Ensure users table has data

### Token Expired Error

- Generate new token by logging in again
- Token expires after 24 hours

### Connection Refused

- Ensure Laravel server is running: `docker compose up -d`
- Check port 8005 is accessible: `curl http://localhost:8005`

---

## 📚 Additional Resources

- **Full API Documentation:** See [API_ROUTES.md](API_ROUTES.md)
- **Complete Credentials Guide:** See [LOGIN_CREDENTIALS.md](LOGIN_CREDENTIALS.md)
- **API Endpoints:** 149+ endpoints available

---

## ✅ Verification Checklist

- [x] Users created in database
- [x] All 4 test users verified
- [x] Login endpoint working
- [x] JWT tokens generating correctly
- [x] Token expiration set (24 hours)
- [x] Database connected

---

**Last Updated:** 2026-04-01  
**Status:** ✅ Ready for Testing
