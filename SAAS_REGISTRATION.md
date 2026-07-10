# SaaS Registration & Login Flow

**Endpoint Base:** `http://localhost:8005/api/auth`

---

## 1️⃣ Sign Up - Create New SaaS Account

### Endpoint
```
POST /auth/signup
```

### Request Body
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john.doe@company.com",
  "password": "SecurePassword123!",
  "password_confirmation": "SecurePassword123!",
  "company_name": "Acme Corporation"
}
```

### cURL Example
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

### Success Response (201)
```json
{
  "success": true,
  "message": "Registration successful. Please verify your email.",
  "data": {
    "user": {
      "id": 7,
      "email": "john.doe@company.com",
      "first_name": "John",
      "last_name": "Doe"
    },
    "company": {
      "id": 2,
      "name": "Acme Corporation",
      "status": "trial"
    },
    "verification_sent": true
  }
}
```

---

## 2️⃣ Verify Email

After signup, user receives verification email with code.

### Endpoint
```
POST /auth/verify-email
```

### Request Body
```json
{
  "email": "john.doe@company.com",
  "verification_code": "ABC123XYZ"
}
```

### cURL Example
```bash
curl -X POST http://localhost:8005/api/auth/verify-email \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john.doe@company.com",
    "verification_code": "ABC123XYZ"
  }'
```

### Success Response
```json
{
  "success": true,
  "message": "Email verified successfully",
  "data": {
    "email_verified": true,
    "verified_at": "2026-04-01T12:00:00Z"
  }
}
```

---

## 3️⃣ Resend Verification Email

If user didn't receive verification code.

### Endpoint
```
POST /auth/resend-verification
```

### Request Body
```json
{
  "email": "john.doe@company.com"
}
```

### cURL Example
```bash
curl -X POST http://localhost:8005/api/auth/resend-verification \
  -H "Content-Type: application/json" \
  -d '{"email": "john.doe@company.com"}'
```

### Success Response
```json
{
  "success": true,
  "message": "Verification email sent",
  "data": {
    "email": "john.doe@company.com",
    "sent_at": "2026-04-01T12:00:00Z"
  }
}
```

---

## 4️⃣ Login - Get JWT Token

### Endpoint
```
POST /auth/login
```

### Request Body
```json
{
  "email": "john.doe@company.com",
  "password": "SecurePassword123!"
}
```

### cURL Example
```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john.doe@company.com",
    "password": "SecurePassword123!"
  }'
```

### Success Response
```json
{
  "message": "Login successful",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expires": "2026-04-02T12:54:54+00:00",
  "user": {
    "id": 7,
    "email": "john.doe@company.com",
    "first_name": "John",
    "last_name": "Doe",
    "company_id": 2
  }
}
```

---

## 5️⃣ Forgot Password

### Endpoint
```
POST /auth/forgot-password
```

### Request Body
```json
{
  "email": "john.doe@company.com"
}
```

### cURL Example
```bash
curl -X POST http://localhost:8005/api/auth/forgot-password \
  -H "Content-Type: application/json" \
  -d '{"email": "john.doe@company.com"}'
```

### Success Response
```json
{
  "success": true,
  "message": "Password reset link sent to email",
  "data": {
    "email": "john.doe@company.com",
    "reset_link_sent": true
  }
}
```

---

## 6️⃣ Reset Password

User receives reset token via email.

### Endpoint
```
POST /auth/reset-password
```

### Request Body
```json
{
  "token": "RESET_TOKEN_FROM_EMAIL",
  "email": "john.doe@company.com",
  "password": "NewPassword123!",
  "password_confirmation": "NewPassword123!"
}
```

### cURL Example
```bash
curl -X POST http://localhost:8005/api/auth/reset-password \
  -H "Content-Type: application/json" \
  -d '{
    "token": "RESET_TOKEN_FROM_EMAIL",
    "email": "john.doe@company.com",
    "password": "NewPassword123!",
    "password_confirmation": "NewPassword123!"
  }'
```

### Success Response
```json
{
  "success": true,
  "message": "Password reset successful",
  "data": {
    "email": "john.doe@company.com",
    "password_reset": true
  }
}
```

---

## 7️⃣ Accept Team Invitation

Public endpoint - no auth required.

### Endpoint
```
POST /auth/accept-invitation
```

### Request Body
```json
{
  "token": "INVITATION_TOKEN",
  "password": "NewUserPassword123!",
  "password_confirmation": "NewUserPassword123!"
}
```

### cURL Example
```bash
curl -X POST http://localhost:8005/api/auth/accept-invitation \
  -H "Content-Type: application/json" \
  -d '{
    "token": "INVITATION_TOKEN",
    "password": "NewUserPassword123!",
    "password_confirmation": "NewUserPassword123!"
  }'
```

### Success Response
```json
{
  "success": true,
  "message": "Invitation accepted",
  "data": {
    "user": {
      "id": 8,
      "email": "invited@example.com",
      "company_id": 2
    },
    "token": "JWT_TOKEN_HERE",
    "expires": "2026-04-02T12:54:54+00:00"
  }
}
```

---

## 🔐 Protected Endpoints (JWT Required)

### Logout
```
POST /auth/logout
```

```bash
curl -X POST http://localhost:8005/api/auth/logout \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Update Password
```
POST /auth/update-password
```

```bash
curl -X POST http://localhost:8005/api/auth/update-password \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "current_password": "OldPassword123!",
    "new_password": "NewPassword123!",
    "new_password_confirmation": "NewPassword123!"
  }'
```

### Get Current User
```
GET /auth/me
```

```bash
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Success Response
```json
{
  "success": true,
  "data": {
    "id": 7,
    "email": "john.doe@company.com",
    "first_name": "John",
    "last_name": "Doe",
    "company_id": 2,
    "company_name": "Acme Corporation",
    "created_at": "2026-04-01T12:00:00Z"
  }
}
```

---

## 📋 Complete SaaS Registration Flow

### Step 1: User Signs Up
```bash
curl -X POST http://localhost:8005/api/auth/signup \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane.smith@startup.io",
    "password": "StartupPass123!",
    "password_confirmation": "StartupPass123!",
    "company_name": "Tech Startup Inc"
  }'
```

### Step 2: User Verifies Email
```bash
# User checks email, gets verification code
curl -X POST http://localhost:8005/api/auth/verify-email \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane.smith@startup.io",
    "verification_code": "CODE_FROM_EMAIL"
  }'
```

### Step 3: User Logs In
```bash
curl -X POST http://localhost:8005/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane.smith@startup.io",
    "password": "StartupPass123!"
  }'
```

### Step 4: Use Token for API Calls
```bash
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."

# Get user profile
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer $TOKEN"

# Update company profile
curl -X PUT http://localhost:8005/api/auth/company/profile \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Tech Startup Inc",
    "industry": "Software Development",
    "phone": "+1-555-0123"
  }'

# Access protected resources
curl -X GET http://localhost:8005/api/products \
  -H "Authorization: Bearer $TOKEN"
```

### Step 5: User Logs Out
```bash
curl -X POST http://localhost:8005/api/auth/logout \
  -H "Authorization: Bearer $TOKEN"
```

---

## ✅ Test Users (Pre-created)

Already available for immediate testing:

| Email | Password | Type |
|-------|----------|------|
| `admin@example.com` | `password123` | Legacy Admin |
| `manager@example.com` | `password123` | Legacy Manager |
| `staff@example.com` | `password123` | Legacy Staff |
| `viewer@example.com` | `password123` | Legacy Viewer |

---

## 🔄 Authentication Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    SaaS Registration Flow                     │
└─────────────────────────────────────────────────────────────┘

User Registration
  ↓
/auth/signup (email, password, company_name)
  ↓
✉️ Verification Email Sent
  ↓
User Clicks Link / Provides Code
  ↓
/auth/verify-email (email, code)
  ↓
✅ Email Verified
  ↓
/auth/login (email, password)
  ↓
🔑 JWT Token Generated
  ↓
Use Token in Authorization Header for All API Calls
  ↓
Authorization: Bearer TOKEN

┌─────────────────────────────────────────────────────────────┐
│                    Protected Resources                        │
└─────────────────────────────────────────────────────────────┘

/auth/me (GET)
/auth/company/profile (GET/PUT)
/products (GET/POST)
/customers (GET/POST)
/vendors (GET/POST)
... (all protected endpoints)

/auth/logout (POST)
```

---

## 🛡️ Security Features

✅ **Password Hashing** - BCrypt with cost 10  
✅ **Email Verification** - OTP-based  
✅ **JWT Tokens** - HS256 algorithm  
✅ **Token Expiration** - 24 hours  
✅ **Rate Limiting** - 60 requests/minute  
✅ **CORS Protection** - Configurable origins  
✅ **Password Reset** - Secure token-based  

---

## 🚨 Error Responses

### 422 Validation Error
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["Email field is required"],
    "password": ["Password must be at least 8 characters"]
  }
}
```

### 401 Unauthorized
```json
{
  "success": false,
  "message": "Invalid credentials",
  "errors": {
    "email": ["These credentials do not match our records"]
  }
}
```

### 409 Conflict (Email exists)
```json
{
  "success": false,
  "message": "Email already registered",
  "errors": {
    "email": ["This email is already in use"]
  }
}
```

---

## 💾 Database Tables

### saas_users
- id
- email
- first_name
- last_name
- password (hashed)
- company_id
- email_verified_at
- created_at
- updated_at

### companies
- id
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

### email_verifications
- email
- token
- created_at

### password_resets
- email
- token
- created_at

---

## 📚 Next Steps

1. **Create SaaS User** - Use signup endpoint
2. **Verify Email** - Check email for code
3. **Login** - Get JWT token
4. **Use API** - Include token in requests
5. **Manage Account** - Update profile, password, settings

---

**Version:** 1.0  
**Last Updated:** 2026-04-01
