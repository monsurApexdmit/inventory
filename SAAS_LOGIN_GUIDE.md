# SaaS Login & Registration Guide

**Status:** ✅ **Fully Functional**

**API Base URL:** `http://localhost:8005/api`

---

## 🎯 Quick Overview

The system supports **two authentication methods:**

1. **Legacy Auth** - Simple email/password login
2. **SaaS Auth** - Full registration, email verification, and trial licensing

---

## 🔐 Legacy Authentication (Simple)

### Users Available

| Email | Password | Role |
|-------|----------|------|
| `admin@example.com` | `password123` | Admin |
| `manager@example.com` | `password123` | Manager |
| `staff@example.com` | `password123` | Staff |
| `viewer@example.com` | `password123` | Viewer |

### Login Request

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

## 🚀 SaaS Authentication (Full Flow)

### Step 1️⃣ - User Signs Up

Create a new SaaS account with company details.

**Endpoint:** `POST /auth/signup`

**Required Fields:**
- `companyName` - Company name
- `ownerFullName` - Full name of owner
- `email` - Email address
- `phone` - Phone number
- `password` - Secure password (min 8 chars)

**Optional Fields:**
- `businessType` - Type of business
- `website` - Company website URL
- `country` - Country

**Example Request:**

```bash
curl -X POST http://localhost:8005/api/auth/signup \
  -H "Content-Type: application/json" \
  -d '{
    "companyName": "Tech Startup Inc",
    "ownerFullName": "Jane Smith",
    "email": "jane.smith@startup.io",
    "phone": "+1-555-0123",
    "password": "StartupPass123!",
    "businessType": "Software Development",
    "website": "https://techstartup.io",
    "country": "USA"
  }'
```

**Success Response (201):**

```json
{
  "success": true,
  "message": "Account created successfully. Please check your email to verify your account.",
  "data": {
    "userId": 82,
    "companyId": 12,
    "email": "jane.smith@startup.io",
    "companyName": "Tech Startup Inc",
    "status": "unverified"
  }
}
```

---

### Step 2️⃣ - Email Verification

User receives verification email with token. Verify the email to activate account.

**Endpoint:** `POST /auth/verify-email`

**Request:**

```bash
curl -X POST http://localhost:8005/api/auth/verify-email \
  -H "Content-Type: application/json" \
  -d '{
    "token": "VERIFICATION_TOKEN_FROM_EMAIL"
  }'
```

**Success Response:**

```json
{
  "success": true,
  "message": "Email verified successfully. Trial activated for 10 days.",
  "data": {
    "userId": 82,
    "companyId": 12,
    "email": "jane.smith@startup.io",
    "companyName": "Tech Startup Inc",
    "userRole": "owner",
    "companyStatus": "trial",
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "licenseType": "trial",
    "trialDaysRemaining": 9,
    "company": {
      "id": 12,
      "name": "Tech Startup Inc",
      "status": "trial"
    }
  }
}
```

---

### Step 3️⃣ - Login to SaaS Account

After email verification, user can login.

**Endpoint:** `POST /auth/login`

**Request:**

```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane.smith@startup.io",
    "password": "StartupPass123!"
  }'
```

**Success Response:**

```json
{
  "message": "Login successful",
  "data": {
    "userId": 82,
    "userEmail": "jane.smith@startup.io",
    "companyId": 12,
    "companyName": "Tech Startup Inc",
    "companyStatus": "trial",
    "userRole": "owner",
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "licenseType": "trial",
    "company": {
      "id": 12,
      "name": "Tech Startup Inc",
      "status": "trial"
    }
  }
}
```

---

## 📚 Using the JWT Token

After login, use the token for all protected API calls.

### Add Token to Request Header

```bash
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."

curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer $TOKEN"
```

### Get Current User Profile

```bash
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "success": true,
  "data": {
    "id": 82,
    "email": "jane.smith@startup.io",
    "firstName": "Jane",
    "lastName": "Smith",
    "companyId": 12,
    "companyName": "Tech Startup Inc",
    "role": "owner",
    "emailVerifiedAt": "2026-04-02T10:40:22Z",
    "createdAt": "2026-04-02T10:40:14Z"
  }
}
```

---

## 🔑 Other Authentication Endpoints

### Logout

```bash
curl -X POST http://localhost:8005/api/auth/logout \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Update Password

```bash
curl -X POST http://localhost:8005/api/auth/update-password \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "current_password": "OldPass123!",
    "new_password": "NewPass456!",
    "new_password_confirmation": "NewPass456!"
  }'
```

### Forgot Password

```bash
curl -X POST http://localhost:8005/api/auth/forgot-password \
  -H "Content-Type: application/json" \
  -d '{"email": "jane.smith@startup.io"}'
```

Response:
```json
{
  "message": "If that email is registered, a password reset link has been sent."
}
```

### Reset Password

```bash
curl -X POST http://localhost:8005/api/auth/reset-password \
  -H "Content-Type: application/json" \
  -d '{
    "token": "RESET_TOKEN_FROM_EMAIL",
    "newPassword": "NewPass789!",
    "confirmPassword": "NewPass789!"
  }'
```

### Resend Verification Email

```bash
curl -X POST http://localhost:8005/api/auth/resend-verification \
  -H "Content-Type: application/json" \
  -d '{"email": "jane.smith@startup.io"}'
```

---

## 🧪 Test SaaS Account (Ready to Use)

An account has been created and verified for testing:

**Email:** `jane.smith@startup.io`  
**Password:** `StartupPass123!`  
**Company:** Tech Startup Inc  
**Status:** Trial (9 days remaining)  
**Token:** Available after login

**Quick Test:**

```bash
# 1. Login
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane.smith@startup.io",
    "password": "StartupPass123!"
  }'

# 2. Copy the token from response
# 3. Use token in subsequent requests

# 4. Get user profile
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer TOKEN_HERE"
```

---

## 🏢 Company Profile Management

Once logged in, manage company details.

### Get Company Profile

```bash
curl -X GET http://localhost:8005/api/auth/company/profile \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Update Company Profile

```bash
curl -X PUT http://localhost:8005/api/auth/company/profile \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Tech Startup Inc",
    "industry": "Software Development",
    "phone": "+1-555-0123",
    "email": "contact@techstartup.io",
    "website": "https://techstartup.io",
    "country": "USA",
    "address": "123 Tech Street",
    "businessType": "B2B SaaS"
  }'
```

### Get Company Status

```bash
curl -X GET http://localhost:8005/api/auth/company/status \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "success": true,
  "data": {
    "status": "trial",
    "planType": "trial",
    "trialStartDate": "2026-04-02",
    "trialEndDate": "2026-04-12",
    "daysRemaining": 9,
    "canExtend": true
  }
}
```

### Get Company Settings

```bash
curl -X GET http://localhost:8005/api/auth/company/settings \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Update Company Settings

```bash
curl -X PUT http://localhost:8005/api/auth/company/settings \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "timezone": "America/New_York",
    "language": "en",
    "currency": "USD",
    "dateFormat": "MM/DD/YYYY",
    "notifications": true
  }'
```

---

## 👥 Team Management

### Invite Team Member

```bash
curl -X POST http://localhost:8005/api/auth/team/invite \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "team@example.com",
    "role": "manager"
  }'
```

### List Team Members

```bash
curl -X GET http://localhost:8005/api/auth/team \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Update Team Member Role

```bash
curl -X PUT http://localhost:8005/api/auth/team/2/role \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"role": "admin"}'
```

### Remove Team Member

```bash
curl -X DELETE http://localhost:8005/api/auth/team/2 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 💳 Billing & Licensing

### Get Billing Plans

```bash
curl -X GET http://localhost:8005/api/billing/plans \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Get Current Subscription

```bash
curl -X GET http://localhost:8005/api/billing/subscription \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Get Payment History

```bash
curl -X GET http://localhost:8005/api/billing/payments \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Upgrade Subscription

```bash
curl -X POST http://localhost:8005/api/billing/upgrade \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"plan_id": 2}'
```

---

## 🔄 Complete Sample Workflow

### 1. Create New SaaS Account

```bash
curl -X POST http://localhost:8005/api/auth/signup \
  -H "Content-Type: application/json" \
  -d '{
    "companyName": "New Startup",
    "ownerFullName": "John Doe",
    "email": "john@newstartup.com",
    "phone": "+1-555-1234",
    "password": "SecurePass123!",
    "businessType": "E-commerce",
    "website": "https://newstartup.com",
    "country": "USA"
  }'
```

### 2. Verify Email

```bash
# User gets verification token from email
curl -X POST http://localhost:8005/api/auth/verify-email \
  -H "Content-Type: application/json" \
  -d '{"token": "VERIFICATION_TOKEN"}'
```

### 3. Login

```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@newstartup.com",
    "password": "SecurePass123!"
  }'
```

### 4. Save Token

```bash
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

### 5. Get Current User

```bash
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer $TOKEN"
```

### 6. Update Company Profile

```bash
curl -X PUT http://localhost:8005/api/auth/company/profile \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New Startup Inc",
    "industry": "E-commerce",
    "address": "123 Main St, City"
  }'
```

### 7. Start Using API

```bash
# List products
curl -X GET http://localhost:8005/api/products \
  -H "Authorization: Bearer $TOKEN"

# Create customer
curl -X POST http://localhost:8005/api/customers \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane","email":"jane@example.com","phone":"555-5678"}'

# List vendors
curl -X GET http://localhost:8005/api/vendors \
  -H "Authorization: Bearer $TOKEN"
```

### 8. Logout

```bash
curl -X POST http://localhost:8005/api/auth/logout \
  -H "Authorization: Bearer $TOKEN"
```

---

## 🛡️ Security Features

✅ **Password Hashing** - BCrypt (cost: 10)  
✅ **Email Verification** - Token-based  
✅ **JWT Tokens** - HS256 algorithm  
✅ **Token Expiration** - 24 hours  
✅ **Rate Limiting** - 60 requests/minute  
✅ **HTTPS Ready** - Secure in production  
✅ **Password Reset** - Secure token flow  
✅ **Account Lockout** - After failed attempts  

---

## 📊 Database Schema

### saas_users table
```sql
- id (primary key)
- email (unique)
- first_name
- last_name
- password (hashed)
- company_id (foreign key)
- email_verified_at (nullable timestamp)
- created_at
- updated_at
```

### companies table
```sql
- id (primary key)
- name
- industry
- phone
- email
- website
- country
- address
- status (trial/active/suspended/cancelled)
- created_at
- updated_at
```

### email_verifications table
```sql
- email
- token
- created_at
```

### password_resets table
```sql
- email
- token
- created_at
```

---

## 🚨 Error Responses

### 401 - Unauthorized
```json
{
  "success": false,
  "message": "Invalid credentials",
  "errors": {"email": ["These credentials do not match our records"]}
}
```

### 422 - Validation Error
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["Email must be a valid email address"],
    "password": ["Password must be at least 8 characters"]
  }
}
```

### 409 - Email Already Exists
```json
{
  "success": false,
  "message": "Email already registered",
  "errors": {"email": ["This email is already in use"]}
}
```

---

## 📋 Checklist for Testing

- [ ] Create new SaaS account
- [ ] Verify email with token
- [ ] Login with credentials
- [ ] Get current user profile
- [ ] Update company profile
- [ ] Invite team member
- [ ] Access protected endpoints
- [ ] Update password
- [ ] Logout

---

## 🔗 Related Documentation

- [API Routes Documentation](API_ROUTES.md)
- [Login Credentials Reference](LOGIN_CREDENTIALS.md)
- [Quick Start Guide](QUICK_START_LOGIN.md)
- [SaaS Registration Details](SAAS_REGISTRATION.md)

---

**Status:** ✅ Ready for Production  
**Last Updated:** 2026-04-02  
**Version:** 1.0
