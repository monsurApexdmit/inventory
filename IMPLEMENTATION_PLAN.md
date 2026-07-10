# Inventory Management — Backend Implementation Plan

> **Stack:** Laravel (PHP) · MySQL · JWT Auth  
> **Architecture Standard:** `backend-api-standards.md` v1.2  
> **Layer Order:** `Route → Controller → Service → Repository → Database/ORM`  
> **Every module must include:** Migration · Model · Interface · Repository · Service · FormRequest · Resource · Feature Tests

---

## Architectural Rules (Non-Negotiable)

| Rule | Detail |
|---|---|
| Layering | Controller never imports Repository. Service never touches Request/Response objects. |
| Repository Pattern | ALL DB queries live inside Repository classes only. |
| Dependency Injection | Never use `new` inside a Service or Controller for infrastructure classes. Bind via `AppServiceProvider`. |
| Models | UUID or auto-increment PKs · `created_at` / `updated_at` on all tables · Soft deletes (`deleted_at`) on primary tables · No business logic inside models. |
| N+1 Prevention | All list endpoints must eager-load relations. Use `with()` explicitly. |
| API Response | All responses use the standard envelope: `{ success, message, data, meta? }` |
| Validation | All input validated in `FormRequest` classes. Never validate in Controller or Service. |
| Naming | snake_case tables · PascalCase classes · camelCase JSON response keys |
| Tests | Feature tests hit the real database. No mocked DB. One test class per module. |
| Pagination | Default 20 per page. All list endpoints support `page`, `per_page`, `search`, `sort_by`, `sort_dir`. |
| Security | `company_id` always extracted from JWT — never from request body. |

---

## Folder Structure (Target)

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/V1/{Module}/
│   ├── Requests/
│   │   └── {Module}/
│   └── Resources/
│       └── {Module}/
├── Services/
│   └── {Module}/
├── Repositories/
│   ├── Contracts/        ← Interfaces
│   └── Eloquent/         ← Concrete implementations
├── Models/
└── Providers/
    └── RepositoryServiceProvider.php

routes/
└── api.php   ← versioned under /api/v1/

database/
└── migrations/
```

---

## Shared Infrastructure Checklist

Before any module work begins, the following must exist and be complete:

- [ ] `ApiResponse` trait — standard `{ success, message, data, meta }` envelope
- [ ] `BaseRepositoryInterface` — `findById`, `findAll`, `create`, `update`, `delete`
- [ ] `BaseRepository` abstract class implementing the interface via Eloquent
- [ ] JWT middleware — validates HS256 token, checks in-memory blacklist, injects `user_id` / `company_id` / `email` into request
- [ ] Global exception handler — `ValidationException` (422), `AuthenticationException` (401), `ModelNotFoundException` (404), generic 500
- [ ] `RepositoryServiceProvider` — binds all interfaces to concrete classes
- [ ] Pagination helper — reusable paginate-with-meta method
- [ ] `FilterScope` trait — reusable search/sort/filter query scopes

---

## Phase 1 — Foundation & Authentication

---

### ⚠️ BEFORE YOU START PHASE 1 — MANDATORY READ

**You MUST complete these steps before implementing Phase 1:**

1. ✅ **Read:** `backend-api-standards.md` sections 1, 4, 17
   - Section 1: Architectural Layers
   - Section 4: ORM Models & Relation Design
   - Section 17: Database Migrations

2. ✅ **Read:** `/home/monsur/Documents/Go/inventory-laravel/IMPLEMENTATION_ROLE_TEMPLATE.md`
   - Pre-Implementation Checklist (all 4 steps)
   - Phase 1 section with detailed instructions

3. ✅ **Verify:** Implementation plan includes
   - [ ] All migrations in dependency order
   - [ ] All models with correct relationships
   - [ ] Soft deletes planned
   - [ ] company_id isolation on every table
   - [ ] Foreign keys with CASCADE DELETE

4. ✅ **Get:** User approval before proceeding

**Phase 1 Deliverable:** All migrations running, all models loading, all relationships working correctly

---

**Goal:** Project skeleton + both auth systems (Legacy + SaaS) fully working.  
**Dependency:** Nothing. This is the starting point.

### 1.1 Infrastructure Setup

| Task | Detail |
|---|---|
| Directory scaffold | Create all folders per target structure above |
| `ApiResponse` trait | `success($data, $message, $meta)` and `error($message, $code, $errors)` methods |
| Base Repository | `BaseRepositoryInterface` + `BaseRepository` with Eloquent implementation |
| Exception Handler | Override `app/Exceptions/Handler.php` for all standard error shapes |
| `RepositoryServiceProvider` | Register in `config/app.php` providers array |
| `.env` config | `JWT_SECRET`, `MAIL_*`, `DB_*`, `APP_URL` |

### 1.2 Auth — Legacy System

**Table:** `users`  
**Endpoints:**

| Method | Path | Description |
|---|---|---|
| POST | `/api/v1/login` | Legacy login — returns JWT with `user_id` |
| POST | `/api/v1/logout` | Blacklist token |

**Files to create:**

```
app/Models/User.php
app/Repositories/Contracts/IUserRepository.php
app/Repositories/Eloquent/UserRepository.php
app/Services/Auth/LegacyAuthService.php
app/Http/Controllers/Api/V1/Auth/LegacyAuthController.php
app/Http/Requests/Auth/LegacyLoginRequest.php
database/migrations/xxxx_create_users_table.php
```

**Model fields:** `id` · `name` · `username` · `email` · `password` (hidden) · `role_id` · `is_active` · `created_at` · `updated_at` · `deleted_at`

### 1.3 Auth — SaaS System

**Tables:** `saas_users`, `companies`  
**Endpoints:**

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/auth/signup` | Public | Create company + owner user (status: unverified) |
| GET | `/api/v1/auth/verify-email` | Public | Verify email via token → activate → issue JWT |
| POST | `/api/v1/auth/resend-verification` | Public | Resend verification email |
| POST | `/api/v1/auth/login` | Public | SaaS login (blocked if unverified) |
| POST | `/api/v1/auth/logout` | Bearer | Blacklist token |
| POST | `/api/v1/auth/forgot-password` | Public | Send reset link via email |
| POST | `/api/v1/auth/reset-password` | Public | Reset password via token |
| POST | `/api/v1/auth/update-password` | Bearer | Change own password (requires current password) |
| GET | `/api/v1/auth/me` | Bearer | Current user + company info |

**Files to create:**

```
app/Models/SaasUser.php
app/Models/Company.php
app/Repositories/Contracts/ISaasUserRepository.php
app/Repositories/Contracts/ICompanyRepository.php
app/Repositories/Eloquent/SaasUserRepository.php
app/Repositories/Eloquent/CompanyRepository.php
app/Services/Auth/SaasAuthService.php
app/Http/Controllers/Api/V1/Auth/SaasAuthController.php
app/Http/Requests/Auth/SaasSignupRequest.php
app/Http/Requests/Auth/SaasLoginRequest.php
app/Http/Requests/Auth/ForgotPasswordRequest.php
app/Http/Requests/Auth/ResetPasswordRequest.php
app/Http/Requests/Auth/UpdatePasswordRequest.php
app/Http/Resources/Auth/AuthUserResource.php
database/migrations/xxxx_create_companies_table.php
database/migrations/xxxx_create_saas_users_table.php
```

**JWT structure:** SaaS token carries `user_id`, `company_id`, `email`. HS256. 24h expiry.  
**Token blacklist:** In-memory array (resets on restart). Checked by JWT middleware on every protected request.

---

## Phase 2 — SaaS Core (Multi-Tenancy)

---

### ⚠️ BEFORE YOU START PHASE 2 — MANDATORY READ

**You MUST complete these steps before implementing Phase 2:**

1. ✅ **Read:** `backend-api-standards.md` Section 6 (DTO Pattern — CRITICAL)
   - Understand Response DTO pattern (data transfer objects)
   - Why Phase 2 failed: All endpoints returned raw arrays (WRONG)

2. ✅ **Read:** `/home/monsur/Documents/Go/inventory-laravel/STANDARDS_CODE_PATTERNS.md`
   - Pattern 1: Response DTO + Mapper (copy/paste example)
   - Pattern 2: Service returning DTOs (not arrays)

3. ✅ **Review:** `/home/monsur/Documents/Go/inventory-laravel/PHASE2_STANDARDS_COMPLIANCE_AUDIT.md`
   - Learn why Phase 2 failed (critical violations)
   - See before/after examples

4. ✅ **Verify:** Implementation plan includes
   - [ ] Response DTO for every resource (10+ DTOs needed)
   - [ ] Mapper function for every DTO
   - [ ] Services return DTOs (never arrays/models)
   - [ ] All sensitive fields excluded from DTOs
   - [ ] Database transactions planned for multi-table ops

5. ✅ **Get:** User approval before proceeding

**Phase 2 Deliverable:** All DTOs + Mappers working, all services return DTOs, no raw arrays exposed to clients

---

**Goal:** Company profile, team management, billing, staff roles, legacy users.  
**Dependency:** Phase 1 complete.

### 2.1 SaaS Company

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/saas/company` | Get authenticated company profile |
| PUT | `/api/v1/saas/company` | Partial update (only non-empty fields written) |
| GET | `/api/v1/saas/company/status` | Subscription + user count summary |
| GET | `/api/v1/saas/company/settings` | Company settings (tax, currency, timezone, language) |
| POST | `/api/v1/saas/company/settings` | Create or update company settings (upsert) |

**Files:**

```
app/Services/Saas/CompanyService.php
app/Repositories/Contracts/ICompanySettingsRepository.php
app/Repositories/Eloquent/CompanySettingsRepository.php
app/Http/Controllers/Api/V1/Saas/CompanyController.php
app/Http/Requests/Saas/UpdateCompanyRequest.php
app/Http/Requests/Saas/UpdateCompanySettingsRequest.php
app/Http/Resources/Saas/CompanyResource.php
database/migrations/xxxx_create_company_settings_table.php
```

### 2.2 SaaS Team

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/saas/team` | List team members + capacity metadata |
| POST | `/api/v1/saas/team/invite` | Invite new user (creates invitation record, 7-day expiry) |
| POST | `/api/v1/saas/team/resend-invite` | Resend invitation with fresh token |
| POST | `/api/v1/auth/accept-invitation` | Accept invite via token → create saas_user → issue JWT |
| PATCH | `/api/v1/saas/team/{id}/role` | Update member role (ownership protection) |
| DELETE | `/api/v1/saas/team/{id}` | Remove member (soft delete, ownership protection) |

**Files:**

```
app/Models/Invitation.php
app/Services/Saas/TeamService.php
app/Repositories/Contracts/IInvitationRepository.php
app/Repositories/Eloquent/InvitationRepository.php
app/Http/Controllers/Api/V1/Saas/TeamController.php
app/Http/Requests/Saas/InviteTeamMemberRequest.php
app/Http/Requests/Saas/AcceptInvitationRequest.php
app/Http/Resources/Saas/TeamMemberResource.php
database/migrations/xxxx_create_invitations_table.php
```

### 2.3 SaaS Billing

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/saas/billing/plans` | List all active plans |
| GET | `/api/v1/saas/billing/subscription` | Current company subscription |
| GET | `/api/v1/saas/billing/payments` | Payment history |
| POST | `/api/v1/saas/billing/subscription/renew` | Renew (extend 1 month or 1 year) |
| POST | `/api/v1/saas/billing/subscription/cancel` | Cancel subscription |
| POST | `/api/v1/saas/billing/subscription/upgrade` | Upgrade to new plan |
| GET | `/api/v1/saas/billing/contact` | Billing contact |
| POST | `/api/v1/saas/billing/contact` | Create or update billing contact (upsert) |
| POST | `/api/v1/saas/billing/subscription/trial` | Create trial subscription (dev/seed only) |

**Files:**

```
app/Models/Plan.php
app/Models/Subscription.php
app/Models/PaymentRecord.php
app/Models/BillingContact.php
app/Services/Saas/BillingService.php
app/Repositories/Contracts/IBillingRepository.php
app/Repositories/Eloquent/BillingRepository.php
app/Http/Controllers/Api/V1/Saas/BillingController.php
app/Http/Resources/Saas/PlanResource.php
app/Http/Resources/Saas/SubscriptionResource.php
database/migrations/xxxx_create_plans_table.php
database/migrations/xxxx_create_subscriptions_table.php
database/migrations/xxxx_create_payment_records_table.php
database/migrations/xxxx_create_billing_contacts_table.php
database/seeders/PlanSeeder.php
```

### 2.4 Staff Roles

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/staff-roles` | List roles for company |
| POST | `/api/v1/staff-roles` | Create role with permissions (full replacement) |
| GET | `/api/v1/staff-roles/{id}` | Get role with flat permissions array |
| PUT | `/api/v1/staff-roles/{id}` | Update role + permissions (delete-all + re-insert) |
| DELETE | `/api/v1/staff-roles/{id}` | Delete role (cascades to `role_permissions`) |

**Files:**

```
app/Models/StaffRole.php
app/Models/Permission.php
app/Models/RolePermission.php
app/Services/StaffRole/StaffRoleService.php
app/Repositories/Contracts/IStaffRoleRepository.php
app/Repositories/Eloquent/StaffRoleRepository.php
app/Http/Controllers/Api/V1/StaffRole/StaffRoleController.php
app/Http/Requests/StaffRole/CreateStaffRoleRequest.php
app/Http/Resources/StaffRole/StaffRoleResource.php
database/migrations/xxxx_create_staff_roles_table.php
database/migrations/xxxx_create_permissions_table.php
database/migrations/xxxx_create_role_permissions_table.php
database/seeders/PermissionSeeder.php
```

### 2.5 Legacy Users

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/users` | List all users (system-wide, no company scope) |
| GET | `/api/v1/users/{id}` | Get user by ID |
| POST | `/api/v1/users` | Create user with hashed password |
| PUT | `/api/v1/users/{id}` | Update user fields |
| DELETE | `/api/v1/users/{id}` | Soft delete (204) |

---

## Phase 3 — Master Data

---

### ⚠️ BEFORE YOU START PHASE 3 — MANDATORY READ

**You MUST complete these steps before implementing Phase 3:**

1. ✅ **Read:** `backend-api-standards.md` Sections 3, 5 (Repository Pattern, N+1 Query Prevention)
   - Understand eager loading with with()
   - Why Phase 2 failed: No eager loading = N+1 queries

2. ✅ **Read:** `/home/monsur/Documents/Go/inventory-laravel/STANDARDS_CODE_PATTERNS.md`
   - Pattern 3: Repository with eager loading (copy/paste example)
   - See with() usage on every query

3. ✅ **Review:** `/home/monsur/Documents/Go/inventory-laravel/PHASE2_STANDARDS_COMPLIANCE_AUDIT.md`
   - Understand N+1 query issues (section 4 of audit)
   - See before/after examples

4. ✅ **Verify:** Implementation plan includes
   - [ ] Repository interface for each resource
   - [ ] Eloquent repository with with() on ALL methods
   - [ ] company_id filtering on every query
   - [ ] No lazy loading allowed
   - [ ] Pagination includes eager loading

5. ✅ **Get:** User approval before proceeding

**Phase 3 Deliverable:** All repositories with eager loading, no N+1 query risks possible

---

**Goal:** Reference data required by Products and Inventory.  
**Dependency:** Phase 2 complete (company + JWT middleware).

### 3.1 Categories

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/categories` | Paginated list (`?view=tree\|flat\|all`, search, status filter) |
| GET | `/api/v1/categories/simple` | Minimal list for dropdowns |
| GET | `/api/v1/categories/stats` | Root + subcategory counts |
| POST | `/api/v1/categories` | Create category (with optional parent) |
| GET | `/api/v1/categories/{id}` | Get category |
| PUT | `/api/v1/categories/{id}` | Update (max 10 depth levels enforced) |
| DELETE | `/api/v1/categories/{id}` | Soft delete (guard: reject if has children) |
| POST | `/api/v1/categories/bulk-delete` | Bulk soft delete (guard: reject if any has children) |
| PATCH | `/api/v1/categories/{id}/status` | Toggle active/inactive |

**Files:**

```
app/Models/Category.php
app/Services/Category/CategoryService.php
app/Repositories/Contracts/ICategoryRepository.php
app/Repositories/Eloquent/CategoryRepository.php
app/Http/Controllers/Api/V1/Category/CategoryController.php
app/Http/Requests/Category/CreateCategoryRequest.php
app/Http/Requests/Category/UpdateCategoryRequest.php
app/Http/Resources/Category/CategoryResource.php
app/Http/Resources/Category/CategoryTreeResource.php
database/migrations/xxxx_create_categories_table.php
```

### 3.2 Attributes

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/attributes` | Paginated list (search, status filter) |
| GET | `/api/v1/attributes/simple` | Minimal list for dropdowns |
| GET | `/api/v1/attributes/stats` | Dashboard summary counts |
| POST | `/api/v1/attributes` | Create attribute with optional predefined values |
| GET | `/api/v1/attributes/{id}` | Get attribute |
| PUT | `/api/v1/attributes/{id}` | Update attribute + values |
| DELETE | `/api/v1/attributes/{id}` | Soft delete |
| POST | `/api/v1/attributes/bulk-delete` | Bulk soft delete |
| PATCH | `/api/v1/attributes/{id}/status` | Toggle active/inactive |

**Option types:** `text` · `dropdown` · `radio` · `checkbox` · `color` · `size`

**Files:**

```
app/Models/Attribute.php
app/Models/AttributeValue.php
app/Services/Attribute/AttributeService.php
app/Repositories/Contracts/IAttributeRepository.php
app/Repositories/Eloquent/AttributeRepository.php
app/Http/Controllers/Api/V1/Attribute/AttributeController.php
app/Http/Requests/Attribute/CreateAttributeRequest.php
app/Http/Resources/Attribute/AttributeResource.php
database/migrations/xxxx_create_attributes_table.php
database/migrations/xxxx_create_attribute_values_table.php
```

### 3.3 Locations

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/locations` | List all locations for company |
| POST | `/api/v1/locations` | Create location |
| GET | `/api/v1/locations/{id}` | Get location |
| PUT | `/api/v1/locations/{id}` | Update location |
| DELETE | `/api/v1/locations/{id}` | Soft delete |

**Files:**

```
app/Models/Location.php
app/Services/Location/LocationService.php
app/Repositories/Contracts/ILocationRepository.php
app/Repositories/Eloquent/LocationRepository.php
app/Http/Controllers/Api/V1/Location/LocationController.php
app/Http/Requests/Location/CreateLocationRequest.php
app/Http/Resources/Location/LocationResource.php
database/migrations/xxxx_create_locations_table.php
```

### 3.4 Settings

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/settings` | Get all settings sections (auto-create defaults on first call) |
| PUT | `/api/v1/settings/{section}` | Update one section independently (upsert) |
| POST | `/api/v1/settings/logo` | Upload logo (max 5MB) |
| POST | `/api/v1/settings/banner` | Upload banner (max 10MB) |

**Sections:** `general` · `tax` · `shipping` · `payment` · `business` · `regional` · `notifications` · `store_hours`

**Files:**

```
app/Models/Setting.php
app/Services/Setting/SettingService.php
app/Repositories/Contracts/ISettingRepository.php
app/Repositories/Eloquent/SettingRepository.php
app/Http/Controllers/Api/V1/Setting/SettingController.php
app/Http/Resources/Setting/SettingResource.php
database/migrations/xxxx_create_settings_table.php
```

---

## Phase 4 — Products & Inventory

---

### ⚠️ BEFORE YOU START PHASE 4 — MANDATORY READ

**You MUST complete these steps before implementing Phase 4:**

1. ✅ **Read:** `backend-api-standards.md` Sections 2, 8 (SOLID Principles, Service Layer)
   - Understand service layer responsibilities
   - Why Phase 2 failed: Returned arrays instead of DTOs

2. ✅ **Read:** `/home/monsur/Documents/Go/inventory-laravel/STANDARDS_CODE_PATTERNS.md`
   - Pattern 2: Service with DTO returns (copy/paste example)
   - Pattern 4: Controller using ApiResponse trait

3. ✅ **Review:** `/home/monsur/Documents/Go/inventory-laravel/PHASE2_STANDARDS_COMPLIANCE_AUDIT.md`
   - Understand transaction requirements (section 3)
   - See service layer violations and fixes

4. ✅ **Verify:** Implementation plan includes
   - [ ] All services return DTOs (typed return hints)
   - [ ] Multi-table operations identified
   - [ ] DB::transaction() planned for multi-table ops
   - [ ] HttpException thrown for errors
   - [ ] Business logic documented

5. ✅ **Get:** User approval before proceeding

**Phase 4 Deliverable:** All services working, returning DTOs, multi-table ops transacted

---

**Goal:** Product catalog, variant system, inventory view, stock transfers.  
**Dependency:** Phase 3 complete (categories, attributes, locations).

### 4.1 Products

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/products` | Paginated list (filter: category, vendor, location) |
| POST | `/api/v1/products` | Create product (`multipart/form-data` — image + data together) |
| GET | `/api/v1/products/{id}` | Get product with variants and gallery |
| PUT | `/api/v1/products/{id}` | Update product + replace gallery images |
| DELETE | `/api/v1/products/{id}` | Soft delete + cleanup image files from disk |
| PATCH | `/api/v1/products/{id}/status` | Toggle published/unpublished |

**Key rules:**
- Two-phase image commit: save to temp path first → move to final path after DB transaction succeeds → rollback on failure.
- Simple products: `products.stock` managed directly.
- Variant products: `products.stock` = SUM of all `product_variants.stock`. `product_variants.stock` = SUM of `variant_inventory.quantity` for that variant.
- Unique constraints: `(company_id, sku)` and `(company_id, barcode)`.

**Files:**

```
app/Models/Product.php
app/Models/ProductVariant.php
app/Models/ProductImage.php
app/Models/VariantInventory.php
app/Services/Product/ProductService.php
app/Services/Product/ProductImageService.php
app/Repositories/Contracts/IProductRepository.php
app/Repositories/Eloquent/ProductRepository.php
app/Http/Controllers/Api/V1/Product/ProductController.php
app/Http/Requests/Product/CreateProductRequest.php
app/Http/Requests/Product/UpdateProductRequest.php
app/Http/Resources/Product/ProductResource.php
app/Http/Resources/Product/ProductVariantResource.php
database/migrations/xxxx_create_products_table.php
database/migrations/xxxx_create_product_variants_table.php
database/migrations/xxxx_create_product_images_table.php
database/migrations/xxxx_create_variant_inventory_table.php
database/migrations/xxxx_create_product_attributes_table.php  ← pivot
```

### 4.2 Inventory (Read-Only View)

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/inventory` | Paginated flat list — one row per SKU, per-location breakdown (search by name/SKU, filter by location) |

**No dedicated table.** Computed via JOIN of `products` + `product_variants` + `variant_inventory` + `locations`.

**Files:**

```
app/Services/Inventory/InventoryService.php
app/Repositories/Contracts/IInventoryRepository.php
app/Repositories/Eloquent/InventoryRepository.php
app/Http/Controllers/Api/V1/Inventory/InventoryController.php
app/Http/Resources/Inventory/InventoryRowResource.php
```

### 4.3 Stock Transfers

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/stock-transfers` | Paginated list (filter: status, product, location) |
| POST | `/api/v1/stock-transfers` | Create + execute transfer immediately (status = Completed) |
| GET | `/api/v1/stock-transfers/{id}` | Get transfer |
| POST | `/api/v1/stock-transfers/{id}/cancel` | Cancel and reverse stock movement |
| GET | `/api/v1/stock-transfers/location-stock` | Products + variant stock at a specific location |

**Key rules:**
- Source and destination must be different locations.
- Insufficient source stock → 400 error.
- Cancellation fully reverses the stock movement.
- Executes atomically in a DB transaction.

**Files:**

```
app/Models/StockTransfer.php
app/Models/StockTransferItem.php
app/Services/StockTransfer/StockTransferService.php
app/Repositories/Contracts/IStockTransferRepository.php
app/Repositories/Eloquent/StockTransferRepository.php
app/Http/Controllers/Api/V1/StockTransfer/StockTransferController.php
app/Http/Requests/StockTransfer/CreateStockTransferRequest.php
app/Http/Resources/StockTransfer/StockTransferResource.php
database/migrations/xxxx_create_stock_transfers_table.php
database/migrations/xxxx_create_stock_transfer_items_table.php
```

---

## Phase 5 — Vendors & Purchasing

---

### ⚠️ BEFORE YOU START PHASE 5 — MANDATORY READ

**You MUST complete these steps before implementing Phase 5:**

1. ✅ **Read:** `backend-api-standards.md` Sections 1, 7, 12 (Layers, Validation, Response Format)
   - Understand standard response envelope format (CRITICAL)
   - Why Phase 2 failed: Missing statusCode, message, traceId

2. ✅ **Read:** `/home/monsur/Documents/Go/inventory-laravel/STANDARDS_CODE_PATTERNS.md`
   - Pattern 4: Controller with ApiResponse (copy/paste example)
   - Pattern 5: Updated ApiResponse trait (complete code)
   - Pattern 6: FormRequest validation (copy/paste example)

3. ✅ **Review:** `/home/monsur/Documents/Go/inventory-laravel/PHASE2_STANDARDS_COMPLIANCE_AUDIT.md`
   - Understand response format violations (section 2 of audit)
   - See correct response structure with all fields

4. ✅ **Verify:** Implementation plan includes
   - [ ] FormRequest for all operations
   - [ ] Controllers use ApiResponse trait
   - [ ] successResponse() for single resources
   - [ ] paginatedResponse() for lists
   - [ ] All responses include: success, statusCode, message, data, [meta], traceId
   - [ ] Pagination meta complete (6 fields)

5. ✅ **Get:** User approval before proceeding

**Phase 5 Deliverable:** All endpoints with correct response format, all validation in FormRequest

---

**Dependency:** Phase 4 complete (products + inventory).

### 5.1 Vendors

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/vendors` | Paginated list (search: name/email/phone, filter: status) |
| POST | `/api/v1/vendors` | Create vendor + auto-create linked `users` record (role_id=4) |
| GET | `/api/v1/vendors/{id}` | Get vendor with linked user + role |
| PUT | `/api/v1/vendors/{id}` | Update vendor + sync linked user name/email |
| DELETE | `/api/v1/vendors/{id}` | Soft delete + cascade soft delete linked user |

**Status filter values:** `Active` · `Inactive` · `Blocked` · `all`

**Files:**

```
app/Models/Vendor.php
app/Services/Vendor/VendorService.php
app/Repositories/Contracts/IVendorRepository.php
app/Repositories/Eloquent/VendorRepository.php
app/Http/Controllers/Api/V1/Vendor/VendorController.php
app/Http/Requests/Vendor/CreateVendorRequest.php
app/Http/Requests/Vendor/UpdateVendorRequest.php
app/Http/Resources/Vendor/VendorResource.php
database/migrations/xxxx_create_vendors_table.php
```

### 5.2 Vendor Returns

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/vendor-returns` | Paginated list (filter: status, vendor, date range) |
| POST | `/api/v1/vendor-returns` | Create return + immediately deduct inventory (transactional) |
| GET | `/api/v1/vendor-returns/{id}` | Get return with items |
| PUT | `/api/v1/vendor-returns/{id}` | Update return details |
| PATCH | `/api/v1/vendor-returns/{id}/status` | Update status only |
| DELETE | `/api/v1/vendor-returns/{id}` | Soft delete |
| GET | `/api/v1/vendor-returns/stats` | Aggregate statistics |
| GET | `/api/v1/vendors/{vendorId}/returns` | Returns scoped to a vendor |

**Status lifecycle:** `pending → shipped → received_by_vendor → completed`  
`completed_date` auto-set when status becomes `completed`.  
**Credit types:** `refund` · `credit_note` · `replacement`

**Files:**

```
app/Models/VendorReturn.php
app/Models/VendorReturnItem.php
app/Services/VendorReturn/VendorReturnService.php
app/Repositories/Contracts/IVendorReturnRepository.php
app/Repositories/Eloquent/VendorReturnRepository.php
app/Http/Controllers/Api/V1/VendorReturn/VendorReturnController.php
app/Http/Requests/VendorReturn/CreateVendorReturnRequest.php
app/Http/Resources/VendorReturn/VendorReturnResource.php
database/migrations/xxxx_create_vendor_returns_table.php
database/migrations/xxxx_create_vendor_return_items_table.php
```

---

## Phase 6 — Customers & Sales

---

### ⚠️ BEFORE YOU START PHASE 6 — MANDATORY READ

**You MUST complete these steps before implementing Phase 6:**

1. ✅ **Read:** `backend-api-standards.md` Section 9 (Test Cases)
   - Understand test structure and coverage
   - Why Phase 2 failed: Tests only checked data field, missed envelope

2. ✅ **Read:** `/home/monsur/Documents/Go/inventory-laravel/STANDARDS_CODE_PATTERNS.md`
   - Pattern 7: Correct test structure (copy/paste example)
   - See full response validation examples

3. ✅ **Review:** `/home/monsur/Documents/Go/inventory-laravel/PHASE2_STANDARDS_COMPLIANCE_AUDIT.md`
   - Understand testing violations
   - See before/after test examples

4. ✅ **Verify:** Implementation plan includes
   - [ ] Feature tests for all endpoints
   - [ ] Happy path (success) tests
   - [ ] Error path tests (404, 422, etc.)
   - [ ] Full response structure validation
   - [ ] All envelope fields checked (success, statusCode, message, data, meta, traceId)
   - [ ] Database assertions verify data saved
   - [ ] Pagination meta validation (6 fields)

5. ✅ **Get:** User approval before proceeding

**Phase 6 Deliverable:** All tests passing (90+ tests), complete response validation

---

**Dependency:** Phase 5 complete (vendors), Phase 3 (locations), Phase 4 (products).

### 6.1 Customers

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/customers` | Paginated list (search: name/email/phone, filter: status, customerType) |
| POST | `/api/v1/customers` | Create customer + auto-create linked `users` record (role_id=3, password: "changeme") |
| GET | `/api/v1/customers/{id}` | Get customer |
| PUT | `/api/v1/customers/{id}` | Update customer + sync linked user name/email |
| DELETE | `/api/v1/customers/{id}` | Soft delete + cascade soft delete linked user |

**Customer types:** `retail` · `wholesale`

**Files:**

```
app/Models/Customer.php
app/Services/Customer/CustomerService.php
app/Repositories/Contracts/ICustomerRepository.php
app/Repositories/Eloquent/CustomerRepository.php
app/Http/Controllers/Api/V1/Customer/CustomerController.php
app/Http/Requests/Customer/CreateCustomerRequest.php
app/Http/Requests/Customer/UpdateCustomerRequest.php
app/Http/Resources/Customer/CustomerResource.php
database/migrations/xxxx_create_customers_table.php
```

### 6.2 Coupons

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/coupons` | Paginated list |
| POST | `/api/v1/coupons` | Create coupon (with optional image upload) |
| GET | `/api/v1/coupons/{id}` | Get coupon |
| PUT | `/api/v1/coupons/{id}` | Update coupon |
| DELETE | `/api/v1/coupons/{id}` | Soft delete |
| GET | `/api/v1/coupons/{id}/stats` | Usage statistics for a coupon |
| GET | `/api/v1/coupons/lookup/{code}` | **Public** — storefront lookup by code |
| POST | `/api/v1/coupons/validate` | Checkout validation (status, date, minimum, limits, applicability, discount) |

**Discount types:** `percentage` · `fixed` · `free_shipping`

**Files:**

```
app/Models/Coupon.php
app/Models/CouponUsage.php
app/Services/Coupon/CouponService.php
app/Services/Coupon/CouponValidationService.php
app/Repositories/Contracts/ICouponRepository.php
app/Repositories/Eloquent/CouponRepository.php
app/Http/Controllers/Api/V1/Coupon/CouponController.php
app/Http/Requests/Coupon/CreateCouponRequest.php
app/Http/Requests/Coupon/ValidateCouponRequest.php
app/Http/Resources/Coupon/CouponResource.php
database/migrations/xxxx_create_coupons_table.php
database/migrations/xxxx_create_coupon_usages_table.php
```

### 6.3 Orders (Sells)

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/sells` | Paginated list (filter: status, payment_method, date range) |
| POST | `/api/v1/sells` | Create order + transactional stock deduction |
| GET | `/api/v1/sells/{id}` | Get order with items |
| PUT | `/api/v1/sells/{id}` | Update order (does NOT re-deduct/restore stock) |
| DELETE | `/api/v1/sells/{id}` | Soft delete + restore stock |
| PATCH | `/api/v1/sells/{id}/status` | Update status only (order/payment/fulfillment) |
| GET | `/api/v1/sells/stats` | Order statistics |

**Key rules:**
- Model name: `Sell`. Table: `sells`. API path: `/sells/`.
- Invoice number format: `INV-{unix_timestamp}` (auto-generated if not provided).
- `order_time` defaults to current server timestamp.
- Shipping address resolution priority: (1) inline fields → (2) `shippingAddressId` → (3) customer default.
- Stock deduction runs inside the create transaction. `stock_deducted = true` set on success.
- `unit_cost` snapshotted from `product_variants.cost_price` or `products.cost_price` at creation time.
- `sells.total_cost` = SUM(`order_items.total_cost`). `sells.gross_profit` = `sells.amount` - `sells.total_cost`.

**Files:**

```
app/Models/Sell.php
app/Models/OrderItem.php
app/Services/Order/OrderService.php
app/Services/Order/StockDeductionService.php
app/Repositories/Contracts/IOrderRepository.php
app/Repositories/Eloquent/OrderRepository.php
app/Http/Controllers/Api/V1/Order/OrderController.php
app/Http/Requests/Order/CreateOrderRequest.php
app/Http/Requests/Order/UpdateOrderRequest.php
app/Http/Resources/Order/OrderResource.php
app/Http/Resources/Order/OrderItemResource.php
database/migrations/xxxx_create_sells_table.php
database/migrations/xxxx_create_order_items_table.php
```

### 6.4 Customer Returns

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/customer-returns` | Paginated list (filter, search) |
| POST | `/api/v1/customer-returns` | Create return (status: pending, auto-fill names from DB) |
| GET | `/api/v1/customer-returns/{id}` | Get return with items |
| PUT | `/api/v1/customer-returns/{id}` | Update return |
| DELETE | `/api/v1/customer-returns/{id}` | Soft delete |
| POST | `/api/v1/customer-returns/{id}/approve` | Approve → restock inventory |
| POST | `/api/v1/customer-returns/{id}/reject` | Reject return |
| PATCH | `/api/v1/customer-returns/{id}/status` | Move to `completed` |
| GET | `/api/v1/customer-returns/stats` | Aggregate statistics |
| GET | `/api/v1/customers/{customerId}/returns` | Returns scoped to a customer |

**Status lifecycle:** `pending → approved / rejected → completed`  
`return_number` auto-generated if not provided.

**Files:**

```
app/Models/CustomerReturn.php
app/Models/CustomerReturnItem.php
app/Services/CustomerReturn/CustomerReturnService.php
app/Repositories/Contracts/ICustomerReturnRepository.php
app/Repositories/Eloquent/CustomerReturnRepository.php
app/Http/Controllers/Api/V1/CustomerReturn/CustomerReturnController.php
app/Http/Requests/CustomerReturn/CreateCustomerReturnRequest.php
app/Http/Resources/CustomerReturn/CustomerReturnResource.php
database/migrations/xxxx_create_customer_returns_table.php
database/migrations/xxxx_create_customer_return_items_table.php
```

---

## Phase 7 — Shipping & Fulfillment

---

### ⚠️ BEFORE YOU START PHASE 7 — MANDATORY READ

**You MUST complete these steps before implementing Phase 7:**

1. ✅ **Read:** `backend-api-standards.md` Sections 6, 8, 12 (DTOs, Services, Response Format)
   - Review all prior patterns (DTOs, services, responses)
   - Ensure complete understanding before Phase 7

2. ✅ **Read:** Complete implementation role template
   - `/home/monsur/Documents/Go/inventory-laravel/IMPLEMENTATION_ROLE_TEMPLATE.md`
   - Review 49-item code review checklist

3. ✅ **Verify:** All Phase 1-6 requirements met
   - [ ] All DTOs created and working
   - [ ] All services returning DTOs
   - [ ] All repositories with eager loading
   - [ ] All multi-table ops transacted
   - [ ] All responses in standard envelope
   - [ ] All tests validating full structure
   - [ ] Code review checklist: all 49 items ✅

4. ✅ **Apply:** Same patterns to Phase 7
   - DTO for shipping address
   - Mapper function
   - Repository with eager loading
   - Service returning DTO
   - Controller with ApiResponse
   - FormRequest validation
   - Feature tests for all endpoints

5. ✅ **Get:** User approval before proceeding

**Phase 7 Deliverable:** Shipping module following all standards, tests validating responses

---

**Dependency:** Phase 6 complete (customers + orders).

### 7.1 Shipping Addresses

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/shipping/addresses` | List addresses (filter by customer) |
| POST | `/api/v1/shipping/addresses` | Create address |
| GET | `/api/v1/shipping/addresses/{id}` | Get address |
| PUT | `/api/v1/shipping/addresses/{id}` | Update address |
| DELETE | `/api/v1/shipping/addresses/{id}` | Delete address |
| PATCH | `/api/v1/shipping/addresses/{id}/default` | Set as default (unsets other defaults for customer) |

### 7.2 Order Shipments

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/shipping/shipments` | Paginated list (filter, search) |
| POST | `/api/v1/shipping/shipments` | Create shipment + sync order fulfillment status |
| GET | `/api/v1/shipping/shipments/{id}` | Get shipment with tracking history |
| PUT | `/api/v1/shipping/shipments/{id}` | Update shipment |
| DELETE | `/api/v1/shipping/shipments/{id}` | Delete shipment |
| PATCH | `/api/v1/shipping/shipments/{id}/status` | Update status + auto-sync order fulfillment status |
| POST | `/api/v1/shipping/shipments/{id}/tracking` | Append tracking event to history log |
| GET | `/api/v1/shipping/track/{trackingNumber}` | **Public** — track by tracking number |
| GET | `/api/v1/shipping/stats` | Aggregate shipment statistics |

**Files:**

```
app/Models/ShippingAddress.php
app/Models/OrderShipment.php
app/Models/ShipmentTracking.php
app/Services/Shipping/ShippingService.php
app/Services/Shipping/ShipmentTrackingService.php
app/Repositories/Contracts/IShippingRepository.php
app/Repositories/Eloquent/ShippingRepository.php
app/Http/Controllers/Api/V1/Shipping/ShippingAddressController.php
app/Http/Controllers/Api/V1/Shipping/ShipmentController.php
app/Http/Resources/Shipping/ShipmentResource.php
app/Http/Resources/Shipping/ShippingAddressResource.php
database/migrations/xxxx_create_shipping_addresses_table.php
database/migrations/xxxx_create_order_shipments_table.php
database/migrations/xxxx_create_shipment_trackings_table.php
```

---

## Phase 8 — Staff & Payroll

---

### ⚠️ BEFORE YOU START PHASE 8 — MANDATORY READ

**You MUST complete these steps before implementing Phase 8:**

1. ✅ **Review:** All prior phases (1-7) complete
   - All standards applied consistently
   - Code review checklist passing for all modules

2. ✅ **Reference:** Complete pattern documentation
   - `/home/monsur/Documents/Go/inventory-laravel/STANDARDS_CODE_PATTERNS.md`
   - All 7 patterns should be familiar

3. ✅ **Apply:** 6-phase workflow from IMPLEMENTATION_ROLE_TEMPLATE.md
   - Phase 1: Migrations & Models
   - Phase 2: DTOs & Mappers
   - Phase 3: Repositories
   - Phase 4: Services
   - Phase 5: Controllers & Routes
   - Phase 6: Tests

4. ✅ **Verify:** Implementation plan for Phase 8 includes
   - [ ] All DTOs created
   - [ ] All mappers working
   - [ ] All services returning DTOs
   - [ ] All repositories with eager loading
   - [ ] All transactions on multi-table ops
   - [ ] All responses in standard envelope
   - [ ] All tests with full structure validation

5. ✅ **Get:** User approval before proceeding

**Phase 8 Deliverable:** Payroll module standards-compliant, all tests passing

---

**Dependency:** Phase 2 complete (users, staff roles).

### 8.1 Staff

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/staff` | Paginated list (search: name/email/contact, filter: status, role) |
| POST | `/api/v1/staff` | Create staff + auto-create linked `users` record (role_id=5, password: "changeme") |
| GET | `/api/v1/staff/{id}` | Get staff member |
| PUT | `/api/v1/staff/{id}` | Update staff + sync linked user name/email |
| DELETE | `/api/v1/staff/{id}` | Soft delete + cascade soft delete linked user |

**Files:**

```
app/Models/Staff.php
app/Services/Staff/StaffService.php
app/Repositories/Contracts/IStaffRepository.php
app/Repositories/Eloquent/StaffRepository.php
app/Http/Controllers/Api/V1/Staff/StaffController.php
app/Http/Requests/Staff/CreateStaffRequest.php
app/Http/Requests/Staff/UpdateStaffRequest.php
app/Http/Resources/Staff/StaffResource.php
database/migrations/xxxx_create_staff_table.php
```

### 8.2 Salary Payments

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/salary-payments` | Paginated list (filter: staff_id, month, status) ordered by most recent |
| POST | `/api/v1/salary-payments` | Create salary payment record |
| GET | `/api/v1/salary-payments/{id}` | Get record with staff relation |
| PUT | `/api/v1/salary-payments/{id}` | Update payment record |
| DELETE | `/api/v1/salary-payments/{id}` | Delete record |

**Status auto-calculation** (derived, not stored directly from input):
- `Paid` → `paidAmount >= amount`
- `Partial` → `0 < paidAmount < amount`
- `Pending` → `paidAmount = 0`

**Unique constraint:** `(company_id, staff_id, month)` — one record per staff per month.

**Files:**

```
app/Models/SalaryPayment.php
app/Services/SalaryPayment/SalaryPaymentService.php
app/Repositories/Contracts/ISalaryPaymentRepository.php
app/Repositories/Eloquent/SalaryPaymentRepository.php
app/Http/Controllers/Api/V1/SalaryPayment/SalaryPaymentController.php
app/Http/Requests/SalaryPayment/CreateSalaryPaymentRequest.php
app/Http/Resources/SalaryPayment/SalaryPaymentResource.php
database/migrations/xxxx_create_salary_payments_table.php
```

---

## Phase 9 — Quality & Hardening

---

### ⚠️ BEFORE YOU START PHASE 9 — MANDATORY READ

**You MUST complete these steps before implementing Phase 9:**

1. ✅ **Verify:** All Phases 1-8 complete
   - All DTOs created and working
   - All mappers functioning
   - All services returning DTOs
   - All repositories with eager loading
   - All multi-table ops transacted
   - All responses in standard envelope
   - All tests passing (90+ tests)

2. ✅ **Review:** Code review checklist
   - `/home/monsur/Documents/Go/inventory-laravel/IMPLEMENTATION_ROLE_TEMPLATE.md`
   - All 49 items verified ✅

3. ✅ **Run:** Standards compliance audit
   - All endpoints conform to backend-api-standards.md
   - All tests validate full response structure
   - All code follows 6-phase workflow
   - No Phase 2 mistakes repeated

4. ✅ **Test:** Complete system
   - [ ] All 90+ tests passing
   - [ ] No N+1 queries in any endpoint
   - [ ] Response format consistent across all endpoints
   - [ ] DTOs exclude sensitive data
   - [ ] Transactions safe on all multi-table ops

5. ✅ **Get:** Final user approval

**Phase 9 Deliverable:** Production-ready system, fully standards-compliant

---

**Goal:** Tests, caching, queues, security, observability.  
**Dependency:** All phases complete.

| Task | Detail |
|---|---|
| Feature Tests | One test class per module. Tests hit real database. No mocked DB. Use `RefreshDatabase` trait. |
| N+1 Audit | Run `\DB::enableQueryLog()` on all list endpoints. All relations must be eager-loaded. |
| Caching | Cache list endpoints using `Cache::remember()`. Invalidate cache on every write operation for that resource. |
| Queue Jobs | Move email sending (auth verification, invitations, password reset) to queued jobs. |
| Logging | Log all Service-layer business actions (creation, status changes, deletions) via Laravel's Log facade. |
| Rate Limiting | Apply `throttle:60,1` to public endpoints. Apply `throttle:600,1` to authenticated endpoints. |
| Security Audit | Mass assignment: verify all models have `$fillable`. Check for SQL injection via raw queries. Confirm `password` fields are `json:hidden`. |
| API Versioning | All routes under `/api/v1/`. Add `Deprecation` response header on any endpoint being phased out. |
| Soft Delete Audit | Confirm all primary tables use soft delete. Confirm global scopes exclude `deleted_at IS NOT NULL`. |
| Code Review Pass | Run through `backend-api-standards.md` Section 22 checklist on every module. |

---

## Development Sequence Summary

```
Phase 1: Foundation & Auth         ← Start here
    ↓
Phase 2: SaaS Core + Staff Roles
    ↓
Phase 3: Master Data (Categories, Attributes, Locations, Settings)
    ↓
Phase 4: Products, Inventory, Stock Transfers
    ↓
Phase 5: Vendors, Vendor Returns
    ↓
Phase 6: Customers, Coupons, Orders, Customer Returns
    ↓
Phase 7: Shipping & Fulfillment
    ↓
Phase 8: Staff & Salary Payments
    ↓
Phase 9: Quality & Hardening
```

---

## Module Count Summary

| Phase | Modules | Estimated Endpoints |
|---|---|---|
| 1 | Auth (Legacy + SaaS) | 12 |
| 2 | SaaS Company, Team, Billing, Staff Roles, Users | 25 |
| 3 | Categories, Attributes, Locations, Settings | 22 |
| 4 | Products, Inventory, Stock Transfers | 18 |
| 5 | Vendors, Vendor Returns | 14 |
| 6 | Customers, Coupons, Orders, Customer Returns | 30 |
| 7 | Shipping Addresses, Shipments | 18 |
| 8 | Staff, Salary Payments | 10 |
| 9 | Hardening | — |
| **Total** | **20 modules** | **~149 endpoints** |
