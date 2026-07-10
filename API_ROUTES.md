# Inventory Management System - Complete API Routes

**Base URL:** `http://localhost:8005/api`

**Last Updated:** 2026-04-01

---

## Table of Contents

1. [Authentication](#authentication)
2. [SaaS Auth](#saas-auth)
3. [Staff Roles](#staff-roles)
4. [Company Profile & Settings](#company-profile--settings)
5. [Billing](#billing)
6. [Team & Invitations](#team--invitations)
7. [Users](#users)
8. [Staff](#staff)
9. [Categories](#categories)
10. [Attributes](#attributes)
11. [Locations](#locations)
12. [Settings](#settings)
13. [Products](#products)
14. [Vendors](#vendors)
15. [Vendor Returns](#vendor-returns)
16. [Customers](#customers)
17. [Customer Returns](#customer-returns)
18. [Shipping Addresses](#shipping-addresses)
19. [Shipments](#shipments)
20. [Salary Payments](#salary-payments)

---

## Authentication

### Legacy Auth (Public)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/login` | Login with email and password (legacy) |
| `POST` | `/logout` | Logout current user (JWT required) |

**Example Request:**
```bash
curl -X POST http://localhost:8005/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'
```

---

## SaaS Auth

### Public Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/signup` | Register new user |
| `POST` | `/auth/verify-email` | Verify email address |
| `POST` | `/auth/resend-verification` | Resend verification email |
| `POST` | `/auth/login` | Login user |
| `POST` | `/auth/forgot-password` | Request password reset |
| `POST` | `/auth/reset-password` | Reset password with token |
| `POST` | `/auth/accept-invitation` | Accept team invitation |

### Protected Endpoints (JWT Required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/logout` | Logout authenticated user |
| `POST` | `/auth/update-password` | Update current user password |
| `GET` | `/auth/me` | Get current authenticated user |

**Example Request:**
```bash
curl -X GET http://localhost:8005/api/auth/me \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Staff Roles

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/staff-roles` | List all staff roles |
| `POST` | `/staff-roles` | Create new staff role |
| `GET` | `/staff-roles/{id}` | Get staff role by ID |
| `PUT` | `/staff-roles/{id}` | Update staff role |
| `DELETE` | `/staff-roles/{id}` | Delete staff role |

**Example Requests:**
```bash
# List staff roles
curl -X GET http://localhost:8005/api/staff-roles \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create staff role
curl -X POST http://localhost:8005/api/staff-roles \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Manager","permissions":["read","write"]}'

# Get staff role
curl -X GET http://localhost:8005/api/staff-roles/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update staff role
curl -X PUT http://localhost:8005/api/staff-roles/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Senior Manager"}'

# Delete staff role
curl -X DELETE http://localhost:8005/api/staff-roles/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Company Profile & Settings

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/auth/company/profile` | Get company profile |
| `PUT` | `/auth/company/profile` | Update company profile |
| `GET` | `/auth/company/status` | Get company status |
| `GET` | `/auth/company/settings` | Get company settings |
| `PUT` | `/auth/company/settings` | Update/create company settings |

**Example Requests:**
```bash
# Get company profile
curl -X GET http://localhost:8005/api/auth/company/profile \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update company profile
curl -X PUT http://localhost:8005/api/auth/company/profile \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"My Company","industry":"Retail","phone":"+1234567890"}'

# Get company status
curl -X GET http://localhost:8005/api/auth/company/status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get company settings
curl -X GET http://localhost:8005/api/auth/company/settings \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update company settings
curl -X PUT http://localhost:8005/api/auth/company/settings \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"timezone":"UTC","language":"en"}'
```

---

## Billing

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/billing/plans` | Get available billing plans |
| `GET` | `/billing/subscription` | Get current subscription |
| `GET` | `/billing/payments` | Get payment history |
| `POST` | `/billing/renew` | Renew subscription |
| `POST` | `/billing/cancel` | Cancel subscription |
| `POST` | `/billing/upgrade` | Upgrade subscription plan |
| `POST` | `/billing/create-subscription` | Create new subscription |
| `GET` | `/billing/contact` | Get billing contact info |
| `PUT` | `/billing/contact` | Update/create billing contact |

**Example Requests:**
```bash
# Get billing plans
curl -X GET http://localhost:8005/api/billing/plans \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get current subscription
curl -X GET http://localhost:8005/api/billing/subscription \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get payment history
curl -X GET http://localhost:8005/api/billing/payments \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Upgrade subscription
curl -X POST http://localhost:8005/api/billing/upgrade \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"plan_id":2}'

# Cancel subscription
curl -X POST http://localhost:8005/api/billing/cancel \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update billing contact
curl -X PUT http://localhost:8005/api/billing/contact \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"John Doe","email":"billing@example.com","tax_id":"12345"}'
```

---

## Team & Invitations

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/auth/team` | List team members |
| `POST` | `/auth/team/invite` | Invite new team member |
| `PUT` | `/auth/team/{userId}/role` | Update team member role |
| `DELETE` | `/auth/team/{userId}` | Remove team member |
| `POST` | `/auth/team/{invitationId}/resend-invitation` | Resend invitation |

**Example Requests:**
```bash
# List team members
curl -X GET http://localhost:8005/api/auth/team \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Invite team member
curl -X POST http://localhost:8005/api/auth/team/invite \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email":"newuser@example.com","role":"manager"}'

# Update team member role
curl -X PUT http://localhost:8005/api/auth/team/2/role \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"role":"admin"}'

# Remove team member
curl -X DELETE http://localhost:8005/api/auth/team/2 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Resend invitation
curl -X POST http://localhost:8005/api/auth/team/5/resend-invitation \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Users

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/users` | List all users |
| `POST` | `/users` | Create new user |
| `GET` | `/users/{id}` | Get user by ID |
| `PUT` | `/users/{id}` | Update user |
| `DELETE` | `/users/{id}` | Delete user |

**Example Requests:**
```bash
# List users
curl -X GET http://localhost:8005/api/users \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create user
curl -X POST http://localhost:8005/api/users \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email":"newuser@example.com","username":"newuser","password":"password123"}'

# Get user
curl -X GET http://localhost:8005/api/users/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update user
curl -X PUT http://localhost:8005/api/users/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email":"updated@example.com","address":"123 Main St"}'

# Delete user
curl -X DELETE http://localhost:8005/api/users/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Staff

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/staff` | List all staff members |
| `POST` | `/staff` | Create new staff member |
| `GET` | `/staff/{id}` | Get staff member by ID |
| `PUT` | `/staff/{id}` | Update staff member |
| `DELETE` | `/staff/{id}` | Delete staff member |

**Example Requests:**
```bash
# List staff
curl -X GET http://localhost:8005/api/staff \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create staff member
curl -X POST http://localhost:8005/api/staff \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"John Doe",
    "email":"john@example.com",
    "contact":"555-1234",
    "joining_date":"2024-01-15",
    "role":"Manager",
    "salary":50000
  }'

# Get staff member
curl -X GET http://localhost:8005/api/staff/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update staff member
curl -X PUT http://localhost:8005/api/staff/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"salary":55000,"role":"Senior Manager"}'

# Delete staff member
curl -X DELETE http://localhost:8005/api/staff/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Categories

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/categories` | List all categories (paginated) |
| `GET` | `/categories/simple` | Get simple category list |
| `GET` | `/categories/stats` | Get category statistics |
| `POST` | `/categories` | Create new category |
| `POST` | `/categories/bulk-delete` | Delete multiple categories |
| `GET` | `/categories/{id}` | Get category by ID |
| `PUT` | `/categories/{id}` | Update category |
| `PATCH` | `/categories/{id}/toggle-status` | Toggle category status |
| `DELETE` | `/categories/{id}` | Delete category |

**Example Requests:**
```bash
# List categories
curl -X GET "http://localhost:8005/api/categories?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get simple categories
curl -X GET http://localhost:8005/api/categories/simple \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get category stats
curl -X GET http://localhost:8005/api/categories/stats \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create category
curl -X POST http://localhost:8005/api/categories \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"category_name":"Electronics","status":true}'

# Get category
curl -X GET http://localhost:8005/api/categories/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update category
curl -X PUT http://localhost:8005/api/categories/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"category_name":"Consumer Electronics"}'

# Toggle category status
curl -X PATCH http://localhost:8005/api/categories/1/toggle-status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Delete category
curl -X DELETE http://localhost:8005/api/categories/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Bulk delete categories
curl -X POST http://localhost:8005/api/categories/bulk-delete \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"ids":[1,2,3]}'
```

---

## Attributes

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/attributes` | List all attributes (paginated) |
| `GET` | `/attributes/simple` | Get simple attribute list |
| `GET` | `/attributes/stats` | Get attribute statistics |
| `POST` | `/attributes` | Create new attribute |
| `POST` | `/attributes/bulk-delete` | Delete multiple attributes |
| `GET` | `/attributes/{id}` | Get attribute by ID |
| `PUT` | `/attributes/{id}` | Update attribute |
| `PATCH` | `/attributes/{id}/toggle-status` | Toggle attribute status |
| `DELETE` | `/attributes/{id}` | Delete attribute |

**Example Requests:**
```bash
# List attributes
curl -X GET "http://localhost:8005/api/attributes?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get simple attributes
curl -X GET http://localhost:8005/api/attributes/simple \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get attribute stats
curl -X GET http://localhost:8005/api/attributes/stats \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create attribute
curl -X POST http://localhost:8005/api/attributes \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Color","display_name":"Color","option_type":"select"}'

# Get attribute
curl -X GET http://localhost:8005/api/attributes/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update attribute
curl -X PUT http://localhost:8005/api/attributes/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"display_name":"Product Color"}'

# Toggle attribute status
curl -X PATCH http://localhost:8005/api/attributes/1/toggle-status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Delete attribute
curl -X DELETE http://localhost:8005/api/attributes/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Locations

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/locations` | List all locations |
| `POST` | `/locations` | Create new location |
| `GET` | `/locations/{id}` | Get location by ID |
| `PUT` | `/locations/{id}` | Update location |
| `DELETE` | `/locations/{id}` | Delete location |

**Example Requests:**
```bash
# List locations
curl -X GET http://localhost:8005/api/locations \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create location
curl -X POST http://localhost:8005/api/locations \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Main Warehouse","address":"123 Warehouse St","contact_person":"Alice"}'

# Get location
curl -X GET http://localhost:8005/api/locations/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update location
curl -X PUT http://localhost:8005/api/locations/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Central Warehouse"}'

# Delete location
curl -X DELETE http://localhost:8005/api/locations/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Settings

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/settings` | Get all settings |
| `PUT` | `/settings/{section}` | Update settings section |
| `POST` | `/settings/upload-logo` | Upload company logo |
| `POST` | `/settings/upload-banner` | Upload company banner |

**Example Requests:**
```bash
# Get all settings
curl -X GET http://localhost:8005/api/settings \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update settings section
curl -X PUT http://localhost:8005/api/settings/general \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"timezone":"UTC","language":"en","currency":"USD"}'

# Upload logo
curl -X POST http://localhost:8005/api/settings/upload-logo \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -F "logo=@/path/to/logo.png"

# Upload banner
curl -X POST http://localhost:8005/api/settings/upload-banner \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -F "banner=@/path/to/banner.png"
```

---

## Products

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/products` | List all products (paginated) |
| `POST` | `/products` | Create new product |
| `GET` | `/products/{id}` | Get product by ID |
| `PUT` | `/products/{id}` | Update product |
| `PATCH` | `/products/{id}/status` | Update product status |
| `DELETE` | `/products/{id}` | Delete product |

**Example Requests:**
```bash
# List products
curl -X GET "http://localhost:8005/api/products?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create product
curl -X POST http://localhost:8005/api/products \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"Laptop",
    "category_id":1,
    "price":999.99,
    "sale_price":899.99,
    "cost_price":600,
    "stock":50,
    "description":"High-performance laptop",
    "published":true
  }'

# Get product
curl -X GET http://localhost:8005/api/products/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update product
curl -X PUT http://localhost:8005/api/products/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"price":1099.99,"stock":45}'

# Update product status
curl -X PATCH http://localhost:8005/api/products/1/status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"published":false}'

# Delete product
curl -X DELETE http://localhost:8005/api/products/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Vendors

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/vendors` | List all vendors (paginated) |
| `POST` | `/vendors` | Create new vendor |
| `GET` | `/vendors/{id}` | Get vendor by ID |
| `PUT` | `/vendors/{id}` | Update vendor |
| `DELETE` | `/vendors/{id}` | Delete vendor |

**Example Requests:**
```bash
# List vendors
curl -X GET "http://localhost:8005/api/vendors?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create vendor
curl -X POST http://localhost:8005/api/vendors \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"Tech Supplies Inc",
    "email":"contact@techsupplies.com",
    "phone":"555-0123",
    "country":"USA",
    "address":"456 Industrial Park"
  }'

# Get vendor
curl -X GET http://localhost:8005/api/vendors/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update vendor
curl -X PUT http://localhost:8005/api/vendors/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email":"newemail@techsupplies.com"}'

# Delete vendor
curl -X DELETE http://localhost:8005/api/vendors/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Vendor Returns

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/vendor-returns` | List all vendor returns (paginated) |
| `POST` | `/vendor-returns` | Create new vendor return |
| `GET` | `/vendor-returns/stats` | Get vendor return statistics |
| `GET` | `/vendor-returns/{id}` | Get vendor return by ID |
| `PUT` | `/vendor-returns/{id}` | Update vendor return |
| `PATCH` | `/vendor-returns/{id}/status` | Update return status |
| `DELETE` | `/vendor-returns/{id}` | Delete vendor return |
| `GET` | `/vendor-returns/vendor/{vendorId}` | Get returns by vendor |

**Example Requests:**
```bash
# List vendor returns
curl -X GET "http://localhost:8005/api/vendor-returns?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create vendor return
curl -X POST http://localhost:8005/api/vendor-returns \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "vendor_id":1,
    "return_date":"2024-01-15",
    "credit_type":"refund",
    "notes":"Defective items"
  }'

# Get vendor return stats
curl -X GET http://localhost:8005/api/vendor-returns/stats \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get vendor return
curl -X GET http://localhost:8005/api/vendor-returns/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update vendor return
curl -X PUT http://localhost:8005/api/vendor-returns/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"notes":"Items received and approved"}'

# Update return status
curl -X PATCH http://localhost:8005/api/vendor-returns/1/status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"completed"}'

# Get returns by vendor
curl -X GET http://localhost:8005/api/vendor-returns/vendor/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Delete vendor return
curl -X DELETE http://localhost:8005/api/vendor-returns/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Customers

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/customers` | List all customers (paginated) |
| `POST` | `/customers` | Create new customer |
| `GET` | `/customers/{id}` | Get customer by ID |
| `PUT` | `/customers/{id}` | Update customer |
| `DELETE` | `/customers/{id}` | Delete customer |

**Example Requests:**
```bash
# List customers
curl -X GET "http://localhost:8005/api/customers?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create customer
curl -X POST http://localhost:8005/api/customers \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"Jane Smith",
    "email":"jane@example.com",
    "phone":"555-9876",
    "address":"789 Oak Avenue",
    "city":"Springfield"
  }'

# Get customer
curl -X GET http://localhost:8005/api/customers/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update customer
curl -X PUT http://localhost:8005/api/customers/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"phone":"555-1111"}'

# Delete customer
curl -X DELETE http://localhost:8005/api/customers/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Customer Returns

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/customer-returns` | List all customer returns (paginated) |
| `POST` | `/customer-returns` | Create new customer return |
| `GET` | `/customer-returns/stats` | Get customer return statistics |
| `GET` | `/customer-returns/{id}` | Get customer return by ID |
| `PUT` | `/customer-returns/{id}` | Update customer return |
| `POST` | `/customer-returns/{id}/approve` | Approve return request |
| `POST` | `/customer-returns/{id}/reject` | Reject return request |
| `DELETE` | `/customer-returns/{id}` | Delete customer return |
| `GET` | `/customer-returns/customer/{customerId}` | Get returns by customer |

**Example Requests:**
```bash
# List customer returns
curl -X GET "http://localhost:8005/api/customer-returns?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create customer return
curl -X POST http://localhost:8005/api/customer-returns \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id":1,
    "request_date":"2024-01-15",
    "reason":"Product defective",
    "status":"pending"
  }'

# Get customer return stats
curl -X GET http://localhost:8005/api/customer-returns/stats \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get customer return
curl -X GET http://localhost:8005/api/customer-returns/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update customer return
curl -X PUT http://localhost:8005/api/customer-returns/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"notes":"Replacement sent"}'

# Approve return request
curl -X POST http://localhost:8005/api/customer-returns/1/approve \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Reject return request
curl -X POST http://localhost:8005/api/customer-returns/1/reject \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Outside return window"}'

# Get returns by customer
curl -X GET http://localhost:8005/api/customer-returns/customer/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Delete customer return
curl -X DELETE http://localhost:8005/api/customer-returns/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Shipping Addresses

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/shipping-addresses` | List all shipping addresses |
| `POST` | `/shipping-addresses` | Create new shipping address |
| `GET` | `/shipping-addresses/{id}` | Get shipping address by ID |
| `PUT` | `/shipping-addresses/{id}` | Update shipping address |
| `PATCH` | `/shipping-addresses/{id}/set-default` | Set as default address |
| `DELETE` | `/shipping-addresses/{id}` | Delete shipping address |

**Example Requests:**
```bash
# List shipping addresses
curl -X GET http://localhost:8005/api/shipping-addresses \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create shipping address
curl -X POST http://localhost:8005/api/shipping-addresses \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address_line_1":"123 Main St",
    "city":"Springfield",
    "state":"IL",
    "postal_code":"62701",
    "country":"USA",
    "is_default":true
  }'

# Get shipping address
curl -X GET http://localhost:8005/api/shipping-addresses/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update shipping address
curl -X PUT http://localhost:8005/api/shipping-addresses/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"address_line_1":"456 Oak Ave"}'

# Set as default
curl -X PATCH http://localhost:8005/api/shipping-addresses/1/set-default \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Delete shipping address
curl -X DELETE http://localhost:8005/api/shipping-addresses/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Shipments

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/shipments` | List all shipments (paginated) |
| `POST` | `/shipments` | Create new shipment |
| `GET` | `/shipments/stats` | Get shipment statistics |
| `GET` | `/shipments/{id}` | Get shipment by ID |
| `PATCH` | `/shipments/{id}/status` | Update shipment status |
| `POST` | `/shipments/{id}/tracking` | Add tracking information |

### Public Endpoint (No Auth Required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/track/{trackingNumber}` | Track shipment publicly |

**Example Requests:**
```bash
# List shipments
curl -X GET "http://localhost:8005/api/shipments?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create shipment
curl -X POST http://localhost:8005/api/shipments \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "sell_id":1,
    "address_id":1,
    "weight":2.5,
    "status":"pending"
  }'

# Get shipment stats
curl -X GET http://localhost:8005/api/shipments/stats \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Get shipment
curl -X GET http://localhost:8005/api/shipments/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update shipment status
curl -X PATCH http://localhost:8005/api/shipments/1/status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"shipped"}'

# Add tracking information
curl -X POST http://localhost:8005/api/shipments/1/tracking \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "tracking_number":"TRACK123456789",
    "carrier":"FedEx",
    "status":"in_transit"
  }'

# Track shipment publicly (no auth needed)
curl -X GET http://localhost:8005/api/track/TRACK123456789
```

---

## Salary Payments

**Protected Endpoint (JWT Required)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/salary-payments` | List all salary payments (paginated) |
| `POST` | `/salary-payments` | Create new salary payment |
| `GET` | `/salary-payments/{id}` | Get salary payment by ID |
| `PUT` | `/salary-payments/{id}` | Update salary payment |
| `DELETE` | `/salary-payments/{id}` | Delete salary payment |

**Example Requests:**
```bash
# List salary payments
curl -X GET "http://localhost:8005/api/salary-payments?page=1&limit=15" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Create salary payment
curl -X POST http://localhost:8005/api/salary-payments \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "staff_id":1,
    "amount":5000,
    "month":"2024-01",
    "status":"paid",
    "payment_date":"2024-01-31"
  }'

# Get salary payment
curl -X GET http://localhost:8005/api/salary-payments/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Update salary payment
curl -X PUT http://localhost:8005/api/salary-payments/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"paid"}'

# Delete salary payment
curl -X DELETE http://localhost:8005/api/salary-payments/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

---

## Authentication & Headers

### Required Headers

For all **protected endpoints**, include the JWT token:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Common Response Format

**Success Response (200):**
```json
{
  "success": true,
  "message": "Operation successful",
  "data": {
    "id": 1,
    "name": "Example"
  }
}
```

**Paginated Response (200):**
```json
{
  "success": true,
  "message": "Data retrieved successfully",
  "data": {
    "data": [
      { "id": 1, "name": "Item 1" },
      { "id": 2, "name": "Item 2" }
    ],
    "current_page": 1,
    "per_page": 15,
    "last_page": 5,
    "total": 73
  }
}
```

**Error Response (400/500):**
```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

---

## Status Codes

| Code | Meaning |
|------|---------|
| `200` | OK - Request succeeded |
| `201` | Created - Resource created successfully |
| `204` | No Content - Successful deletion |
| `400` | Bad Request - Invalid input |
| `401` | Unauthorized - Missing/invalid token |
| `403` | Forbidden - Insufficient permissions |
| `404` | Not Found - Resource not found |
| `409` | Conflict - Resource already exists |
| `422` | Unprocessable Entity - Validation error |
| `500` | Internal Server Error |

---

## Query Parameters

### Pagination

```
?page=1&limit=15
```

### Filtering

```
?search=keyword
?status=active
?published=1
```

### Sorting

```
?sort=name
?sort=-created_at (descending)
```

---

## Total API Statistics

- **Total Endpoints:** 149+
- **Protected Endpoints:** ~140
- **Public Endpoints:** ~9
- **Modules:** 18
- **HTTP Methods:** GET, POST, PUT, PATCH, DELETE
- **Authentication:** JWT Bearer Token

---

**Generated:** 2026-04-01  
**Version:** 1.0
