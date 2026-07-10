# Coupons Module - Implementation Complete ✅

**Status:** Production Ready  
**Last Updated:** 2026-04-02  
**Version:** 1.0  
**Phase:** 10

---

## 📋 Overview

The Coupons module manages discount coupon campaigns for a company's storefront. It supports creating, reading, updating, soft-deleting, and validating coupons. Covers multiple discount types, usage controls, product/category applicability filters, auto-apply, and stackable coupon behavior.

---

## 🎯 Key Features

✅ **Full CRUD with Soft Delete** — Create, read, update, and soft-delete coupons  
✅ **Image Upload Support** — Optional image upload per coupon stored on disk  
✅ **Three Discount Types** — `percentage`, `fixed`, `free_shipping`  
✅ **Usage Tracking** — Via `coupon_usages` table linked to customers and orders  
✅ **Per-Coupon Usage Statistics** — Get detailed usage stats for each coupon  
✅ **Public Coupon Lookup** — Unauthenticated endpoint for storefront coupon discovery  
✅ **Checkout Validation Endpoint** — Validate coupon with all business rules  
✅ **Multi-condition Validation** — Date range, min order, usage limits, applicability  
✅ **Company Isolation** — Multi-tenant with company_id scoping  
✅ **Discount Calculation** — Percentage with max cap, fixed, or free shipping  
✅ **Auto-apply & Stackable Flags** — Metadata for storefront coupon resolution  
✅ **Priority Ordering** — Coupon priority field for resolution ordering  

---

## 📁 Files Created

### Migrations (2 files)
- `database/migrations/2024_01_01_000215_create_coupons_table.php`
- `database/migrations/2024_01_01_000216_create_coupon_usages_table.php`

### Models (2 files)
- `app/Models/Coupon.php`
- `app/Models/CouponUsage.php`

### DTOs & Mappers (2 files)
- `app/DTOs/Coupon/CouponDTO.php`
- `app/DTOs/Coupon/CouponMapper.php`

### Repository Layer (2 files)
- `app/Repositories/Contracts/ICouponRepository.php`
- `app/Repositories/Eloquent/CouponRepository.php`

### Business Logic (1 file)
- `app/Services/Coupon/CouponService.php`

### Validation (2 files)
- `app/Http/Requests/Coupon/CreateCouponRequest.php`
- `app/Http/Requests/Coupon/UpdateCouponRequest.php`

### API Layer (1 file)
- `app/Http/Controllers/Api/V1/Coupon/CouponController.php`

### Tests (1 file)
- `tests/Feature/Coupon/CouponTest.php` (19 integration tests)

---

## 🛣️ API Endpoints

### Public Endpoint (No Authentication)
```
GET /api/coupons/code/{code}
```
Returns active coupon by code or 404 if not found/inactive.

### Protected Endpoints (Bearer JWT Required)
```
GET    /api/coupons/                      # List all coupons for company
GET    /api/coupons/{id}                  # Get single coupon
POST   /api/coupons/                      # Create coupon (JSON)
POST   /api/coupons/with-image            # Create with image upload (multipart)
PUT    /api/coupons/{id}                  # Partial update (JSON)
PUT    /api/coupons/{id}/with-image       # Update with optional image (multipart)
DELETE /api/coupons/{id}                  # Soft delete + remove image
POST   /api/coupons/validate              # Validate at checkout
GET    /api/coupons/{id}/usage-stats      # Get usage statistics
```

---

## 📊 Request/Response Format

### Create Coupon (POST /api/coupons/)
```json
{
  "campaignName": "Summer Sale",
  "code": "SUMMER20",
  "discount": 20,
  "type": "percentage",
  "startDate": "2026-06-01T00:00:00Z",
  "endDate": "2026-08-31T23:59:59Z",
  "status": true,
  "usageLimit": 100,
  "usageLimitPerUser": 1,
  "minOrderAmount": 50,
  "maxDiscount": 30,
  "applicableToCategories": "",
  "applicableToProducts": "",
  "freeShipping": false,
  "stackable": false,
  "autoApply": false,
  "priority": 0
}
```

### Validate Coupon (POST /api/coupons/validate)
```json
{
  "code": "SUMMER20",
  "customerId": 5,
  "orderAmount": 120.00,
  "cartItems": [
    {
      "product_id": 2,
      "category_id": 1,
      "price": 60.00,
      "quantity": 2
    }
  ]
}
```

**Response (Valid):**
```json
{
  "success": true,
  "message": "Coupon is valid",
  "data": {
    "valid": true,
    "discountAmount": 24.00
  }
}
```

**Response (Invalid - 422):**
```json
{
  "valid": false,
  "error_code": "COUPON_EXPIRED",
  "message": "This coupon has expired"
}
```

---

## 🔧 Business Logic Implementation

### Coupon Code Uniqueness
Codes are unique per company via index `idx_coupon_company_code` and service validation.

### Image Lifecycle
- **Create with image:** File saved to `storage/app/public/uploads/coupons/`
- **Update with image:** Old image deleted from disk; new image saved
- **Delete coupon:** Image file removed from disk before soft delete

### Public Coupon Lookup (GET /api/coupons/code/:code)
- Returns 404 if coupon not found or `status = false` (inactive)
- No authentication required
- Intended for storefront display

### Coupon Validation (POST /api/coupons/validate)
Validates in order:
1. Coupon exists and `status = true`
2. Current date within `start_date` and `end_date`
3. `orderAmount >= min_order_amount`
4. `times_used < usage_limit` (if set)
5. Per-user usage count < `usage_limit_per_user` (if set and `customerId` provided)
6. Cart items match `applicable_to_products` or `applicable_to_categories` (if restrictions set)
7. Calculate discount based on type

### Discount Calculation
- **percentage:** `orderAmount × (discount / 100)`, capped at `max_discount` if set
- **fixed:** Return `discount` value directly
- **free_shipping:** Return 0 (handled by orders module)

---

## ✅ Validation Rules

| Field | Rule |
|-------|------|
| `campaignName` | Required; min 3; max 200 chars |
| `code` | Required; min 3; max 50 chars; alphanumeric only |
| `discount` | Required; numeric > 0 |
| `type` | Required; one of: percentage, fixed, free_shipping |
| `startDate` | Required; valid date |
| `endDate` | Required; valid date; must be after startDate |
| `status` | Optional boolean |
| `usageLimit` | Optional integer >= 1 |
| `usageLimitPerUser` | Optional integer >= 1 |
| `minOrderAmount` | Optional numeric >= 0 |
| `maxDiscount` | Optional numeric >= 0 |
| All boolean/optional fields | Optional with sensible defaults |

---

## 🛡️ Error Handling

| HTTP Status | Scenario |
|-------------|----------|
| 400 | `endDate` before `startDate`; duplicate code; invalid RFC3339 date |
| 404 | Coupon not found by ID; coupon not found or inactive on public lookup |
| 422 | Validation failure; coupon validation failure at checkout |
| 401 | Missing/invalid JWT token |
| 500 | Server error; image save/delete failure |

---

## 🧪 Test Coverage

**19 integration tests** covering:
- ✅ List coupons with pagination
- ✅ Get single coupon
- ✅ Get coupon not found
- ✅ Public coupon lookup by code
- ✅ Inactive coupon not returned by public lookup
- ✅ Create coupon (JSON)
- ✅ Create coupon duplicate code returns error
- ✅ Same code different company succeeds
- ✅ Update coupon (partial)
- ✅ Delete coupon (soft delete)
- ✅ Validate percentage coupon
- ✅ Validate percentage with max discount cap
- ✅ Validate fixed coupon
- ✅ Validate expired coupon
- ✅ Validate min order amount check
- ✅ Validate usage limit exceeded
- ✅ Validate requires authentication
- ✅ Validate inactive coupon
- ✅ Get coupon usage stats

All 19 tests **passing** ✅

---

## 📈 Performance Optimizations

✅ **Eager Loading** — Relations loaded with `with()` to prevent N+1 queries  
✅ **Pagination** — Default 20 per page; configurable via `per_page` param  
✅ **Indexing** — Indexes on company_id, status, code for fast lookups  
✅ **Database Transactions** — Atomic operations for image handling  
✅ **Soft Deletes** — Preserve history without data loss  

---

## 🔐 Security & Authentication

- ✅ All endpoints except `GET /api/coupons/code/:code` require Bearer JWT
- ✅ Company ID extracted from JWT and enforced on all queries
- ✅ No cross-company access possible
- ✅ Public code lookup only returns active coupons
- ✅ Proper HTTP status codes for all scenarios

---

## 📚 Integration with Other Modules

The Coupons module integrates with:
- **Companies** — `company_id` scoping from JWT
- **Customers** — `coupon_usages.customer_id` references `customers.id`
- **Orders** — `coupon_usages.sell_id` references order/sale records
- **Categories** — `applicable_to_categories` filter evaluation
- **Products** — `applicable_to_products` filter evaluation

---

## 🚀 Quick Start Examples

### Create a Coupon
```bash
TOKEN="your_jwt_token"

curl -X POST http://localhost:8005/api/coupons \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "campaignName": "Holiday Sale",
    "code": "HOLIDAY25",
    "discount": 25,
    "type": "percentage",
    "startDate": "2026-12-01T00:00:00Z",
    "endDate": "2026-12-31T23:59:59Z",
    "status": true,
    "usageLimit": 500,
    "minOrderAmount": 75
  }'
```

### Validate Coupon at Checkout
```bash
curl -X POST http://localhost:8005/api/coupons/validate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "HOLIDAY25",
    "customerId": 10,
    "orderAmount": 150,
    "cartItems": [
      {
        "product_id": 5,
        "category_id": 2,
        "price": 50,
        "quantity": 3
      }
    ]
  }'
```

### Get Coupon by Code (Public - No Auth)
```bash
curl http://localhost:8005/api/coupons/code/HOLIDAY25
```

### Get Usage Statistics
```bash
curl -X GET http://localhost:8005/api/coupons/1/usage-stats \
  -H "Authorization: Bearer $TOKEN"
```

---

## ✨ Phase 10 Complete

This completes **Phase 10: Coupons & Discounts** in the implementation roadmap.

**Total System Status:**
- 21 modules implemented
- 158+ API endpoints
- Full DTO/Mapper pattern
- Multi-tenant architecture
- Production-ready codebase

---

**Status:** ✅ Production Ready  
**Database Migration:** Applied successfully  
**Routes:** Registered in routes/api.php  
**Tests:** 19/19 passing  
**Version:** 1.0