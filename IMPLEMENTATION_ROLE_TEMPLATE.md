# Implementation Role — Read This BEFORE Starting Any Module

**Purpose:** This document defines your role, responsibilities, and exact workflow before implementing ANY new module.  
**Status:** MANDATORY — Must be completed before coding starts  
**Applies to:** Phase 3 and all future development  

---

## 🎯 Your Role as Implementation Agent

You are responsible for:
1. **Understanding** the business requirements
2. **Planning** the technical implementation
3. **Following** all standards and patterns
4. **Building** production-ready code
5. **Validating** against specifications
6. **Writing** comprehensive tests

**Accountability:** Every line of code must be justified by:
- ✅ Backend API standards compliance
- ✅ Inventory management business requirements
- ✅ Correct code patterns (no Phase 2 mistakes repeated)

---

## 📋 Pre-Implementation Checklist

**BEFORE you write ANY code:**

### Step 1: Understand the Module (30 min)
- [ ] Read the module specification from `/home/monsur/Documents/business_context/inventory_management/backend/`
- [ ] Identify business problems this module solves
- [ ] List all required endpoints (from spec)
- [ ] Understand data flows and business rules
- [ ] Document any assumptions

**Output:** Module brief (1 page)

---

### Step 2: Check Standards Alignment (20 min)
- [ ] Review backend-api-standards.md (sections 1-12)
- [ ] Identify required patterns for this module:
  - Response DTOs needed?
  - Transactions required?
  - Eager loading needed?
  - Multi-tenancy (company_id)?
  - Soft deletes?
- [ ] Check pagination requirements
- [ ] Review error handling standards
- [ ] Verify authentication/authorization model

**Output:** Standards requirements list

---

### Step 3: Plan the Implementation (45 min)

Create a detailed plan covering:

#### 3.1 Database Layer
- [ ] List all new tables needed
- [ ] List migration files required
- [ ] Define column types and constraints
- [ ] Plan foreign key relationships
- [ ] Identify soft-delete candidates
- [ ] Plan indexes for company_id queries

#### 3.2 Model Layer
- [ ] List all models to create
- [ ] Define fillable properties
- [ ] Plan relationships (HasMany, BelongsTo, etc.)
- [ ] Identify scopes needed

#### 3.3 DTO Layer (CRITICAL)
- [ ] List all Response DTOs to create
- [ ] Define fields for each DTO
- [ ] Identify sensitive fields to EXCLUDE
- [ ] Plan DTO-to-array conversions
- [ ] Identify Mapper functions needed

#### 3.4 Repository Layer
- [ ] List all repository interfaces
- [ ] Define method signatures
- [ ] Plan eager loading strategies
- [ ] Identify N+1 prevention points
- [ ] Plan pagination needs

#### 3.5 Service Layer
- [ ] List all service classes
- [ ] Define business logic for each operation
- [ ] Identify multi-table operations (need transactions)
- [ ] Plan error handling
- [ ] Identify validation logic

#### 3.6 Controller Layer
- [ ] List all endpoints
- [ ] Define HTTP method (GET/POST/PUT/DELETE)
- [ ] Define request payload structure
- [ ] Define response structure
- [ ] Identify authorization requirements

#### 3.7 Request Validation Layer
- [ ] List FormRequest classes needed
- [ ] Define validation rules
- [ ] Plan custom error messages
- [ ] Identify cross-field validations

#### 3.8 Testing Layer
- [ ] List test cases needed
- [ ] Identify happy path tests
- [ ] Identify error path tests
- [ ] Plan response structure validation
- [ ] Plan data integrity tests

**Output:** Detailed implementation plan (2-3 pages)

---

### Step 4: Get Approval Before Coding (User Sign-off)

**Ask the user:**
```
I'm about to implement [MODULE_NAME].

Here's my plan:
1. [Database changes]
2. [Models to create]
3. [DTOs and Mappers]
4. [Services and Repositories]
5. [Controllers and Routes]
6. [Tests]
7. [Estimated time: X hours]

Does this align with what you need? Should I proceed?
```

**Wait for user confirmation before writing code.**

---

## 🔧 Implementation Workflow

### Phase 1: Infrastructure (Non-Breaking Order)

---

## ⚠️ BEFORE YOU START PHASE 1 — READ THIS

**MANDATORY:** Before implementing Phase 1 (Migrations & Models), you MUST:

1. **Complete the Pre-Implementation Checklist** (all 4 steps above)
2. **Read backend-api-standards.md sections 1, 4, 17** (Architecture, ORM Models, Migrations)
3. **Review STANDARDS_CODE_PATTERNS.md** (sections are not applicable to Phase 1, but understand section 1)
4. **Verify your implementation plan** covers:
   - All migrations in dependency order
   - All models with correct relationships
   - Soft deletes planned where needed
   - company_id isolation on every table
5. **Get user approval** before proceeding

**Phase 1 Deliverable:** All migrations running, all models loading correctly

---

**1. Create Migrations**
- One file per table
- Migration names follow pattern: `2024_01_01_000XXX_create_[table]_table.php`
- All columns explicitly typed
- Foreign keys with CASCADE DELETE
- Indexes on company_id and commonly-filtered fields

**Checklist:**
- [ ] Migrations run clean: `php artisan migrate:fresh`
- [ ] Migrations rollback cleanly: `php artisan migrate:rollback`
- [ ] No SQL errors
- [ ] All constraints enforced

---

**2. Create Models**
- One file per model: `app/Models/[ResourceName].php`
- Define fillable array
- Define relationships (both sides)
- Define casts for types
- Define soft deletes if needed
- NO BUSINESS LOGIC in models

**Checklist:**
- [ ] Model extends correct base class
- [ ] Relationships declared both directions
- [ ] Foreign keys match migration
- [ ] Fillable matches database columns
- [ ] Timestamps enabled

---

### Phase 2: Data Transfer Layer

---

## ⚠️ BEFORE YOU START PHASE 2 — READ THIS

**MANDATORY:** Before implementing Phase 2 (DTOs & Mappers), you MUST:

1. **Verify Phase 1 complete** (all migrations run, all models loading)
2. **Read backend-api-standards.md Section 6** (DTO Pattern — CRITICAL)
3. **Review STANDARDS_CODE_PATTERNS.md Pattern 1** (Response DTO with Mapper)
4. **Understand why Phase 2 failed:**
   - Phase 2 had NO DTOs (critical violation)
   - All responses returned raw arrays
   - This MUST NOT happen in Phase 3
5. **Verify your DTO plan** covers:
   - Every resource has a Response DTO
   - Every DTO excludes sensitive fields
   - Every DTO has a corresponding Mapper
   - All nested relations planned
6. **Confirm you understand:**
   - DTO = controls what clients see
   - Mapper = converts ORM model to DTO
   - Service ALWAYS returns DTO (never raw array)

**Phase 2 Deliverable:** All DTOs and Mappers created and tested

---

**3. Create Response DTOs**
- One file per DTO: `app/Dtos/Responses/[ResourceName]Response.php`
- Define typed constructor properties
- Include toArray() method
- EXCLUDE sensitive fields (passwords, tokens, internal flags)
- Include createdAt/updatedAt timestamps

**Example structure:**
```php
class ProductResponse {
    public function __construct(
        public int $id,
        public string $sku,
        public string $name,
        public float $price,
        // NOT included: cost, supplier_notes, internal_flags
    ) {}
}
```

**Checklist:**
- [ ] All public API fields included
- [ ] All sensitive fields excluded
- [ ] toArray() method defined
- [ ] Type hints on all properties
- [ ] Timestamp fields formatted as ISO8601

---

**4. Create Mapper Functions**
- One file per mapper: `app/Dtos/Mappers/[ResourceName]Mapper.php`
- Single resource: `toResponse(Model): ResponseDTO`
- Collection: `toCollectionResponse(Collection): array`
- Nested relations: map to nested DTOs

**Example structure:**
```php
class ProductMapper {
    public static function toResponse(Product $product): ProductResponse {
        return new ProductResponse(
            id: $product->id,
            sku: $product->sku,
            name: $product->name,
            price: $product->price,
        );
    }
}
```

**Checklist:**
- [ ] Mapper handles null relations gracefully
- [ ] Nested relations mapped to nested DTOs
- [ ] Date/time formatted correctly
- [ ] All model fields mapped to DTO fields
- [ ] No raw model data exposed

---

### Phase 3: Data Access Layer

---

## ⚠️ BEFORE YOU START PHASE 3 — READ THIS

**MANDATORY:** Before implementing Phase 3 (Repositories), you MUST:

1. **Verify Phase 1 & 2 complete** (migrations, models, DTOs, mappers all done)
2. **Read backend-api-standards.md Sections 3, 5** (Repository Pattern, N+1 Query Prevention)
3. **Review STANDARDS_CODE_PATTERNS.md Pattern 3** (Repository with eager loading)
4. **Understand the N+1 problem:**
   - Phase 2 had repositories WITHOUT eager loading
   - This caused N+1 query performance issues
   - EVERY repository method MUST use with() for eager loading
   - NO lazy loading allowed
5. **Verify your repository plan** covers:
   - Every repository has an interface
   - Every method signature documented
   - Every method has eager loading planned
   - company_id filtering on every query
6. **Confirm you understand:**
   - Repository = data access only (no business logic)
   - Interface = what service depends on
   - Implementation = how Eloquent queries work
   - with() = eager load relations (NO N+1 queries)

**Phase 3 Deliverable:** All repositories with eager loading, no N+1 risks

---

**5. Create Repository Interfaces**
- One file per interface: `app/Repositories/Contracts/I[ResourceName]Repository.php`
- Define all CRUD methods
- Include filtering/search methods
- Include pagination
- Use typed return hints

**Required methods pattern:**
```php
interface IProductRepository {
    public function findByCompany(int $companyId, int $page, int $limit): array;
    public function findByIdAndCompany(int $id, int $companyId): ?Product;
    public function create(array $data): Product;
    public function update(int $id, array $data): Product;
    public function delete(int $id): void;
}
```

**Checklist:**
- [ ] Interface matches service needs
- [ ] Return types specified
- [ ] Parameters clear and documented
- [ ] company_id isolation enforced

---

**6. Create Repository Implementations**
- One file per implementation: `app/Repositories/Eloquent/[ResourceName]Repository.php`
- Implement IRepository interface
- **CRITICAL:** Use `with()` for ALL eager loading
- NO lazy loading allowed
- Return typed results

**Critical pattern (NO N+1 queries):**
```php
public function findByCompany(int $companyId, int $page, int $limit): array {
    $query = Product::where('company_id', $companyId)
        ->with(['category', 'supplier', 'warehouse'])  // ← REQUIRED
        ->orderByDesc('created_at');
    
    $total = $query->count();
    $data = $query->paginate($limit, ['*'], 'page', $page)->items();
    
    return ['data' => $data, 'total' => $total];
}
```

**Checklist:**
- [ ] ALL relationships eager-loaded with `with()`
- [ ] company_id filtering on EVERY query
- [ ] Pagination includes total count
- [ ] Soft-deleted records excluded (default)
- [ ] No raw DB queries (use Eloquent only)
- [ ] Indexes used efficiently

---

**7. Bind Repositories in ServiceProvider**
- File: `app/Providers/RepositoryServiceProvider.php`
- Add binding: `IProductRepository::class => ProductRepository::class`

**Checklist:**
- [ ] Binding added to ServiceProvider
- [ ] Interface and implementation spelled correctly
- [ ] Can be injected into services

---

### Phase 4: Business Logic Layer

---

## ⚠️ BEFORE YOU START PHASE 4 — READ THIS

**MANDATORY:** Before implementing Phase 4 (Services), you MUST:

1. **Verify Phase 1, 2, & 3 complete** (migrations, models, DTOs, repositories all done)
2. **Read backend-api-standards.md Sections 2, 8** (SOLID Principles, Service Layer)
3. **Review STANDARDS_CODE_PATTERNS.md Pattern 2** (Service with DTO returns)
4. **Understand critical service requirements:**
   - Phase 2 services returned raw arrays (WRONG)
   - Services MUST return DTOs (typed return hints)
   - Phase 2 had no transactions on multi-table writes (data integrity risk)
   - Services MUST wrap multi-table operations in DB::transaction()
5. **Verify your service plan** covers:
   - Every service returns DTOs (not arrays/models)
   - Every multi-table operation identified and transacted
   - Every error throws HttpException
   - Business logic organized (SRP — single responsibility)
6. **Confirm you understand:**
   - Service = business logic only
   - Repository = data access (service calls repo)
   - DTO = what service returns (via mapper)
   - Transaction = atomic multi-table writes
   - HttpException = error handling

**Phase 4 Deliverable:** All services returning DTOs with transactions

---

**8. Create Services**
- One file per service: `app/Services/[Module]/[ResourceName]Service.php`
- Inject repositories (via interface, not concrete class)
- Implement all business logic
- Return **DTOs**, never raw models or arrays
- Throw `HttpException` for errors
- Wrap multi-table operations in `DB::transaction()`

**Critical pattern (DTO returns):**
```php
class ProductService {
    public function __construct(
        private readonly IProductRepository $productRepository,
    ) {}

    public function get(int $id, int $companyId): ProductResponse {
        $product = $this->productRepository->findByIdAndCompany($id, $companyId);
        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }
        return ProductMapper::toResponse($product);
    }

    public function create(int $companyId, array $data): ProductResponse {
        return DB::transaction(function() use ($companyId, $data) {
            $product = $this->productRepository->create([
                'company_id' => $companyId,
                ...$data
            ]);
            return ProductMapper::toResponse($product);
        });
    }
}
```

**Checklist:**
- [ ] Repository injected via interface
- [ ] All methods return DTOs (not arrays/models)
- [ ] Multi-table operations use DB::transaction()
- [ ] HttpException thrown for errors (not generic Exception)
- [ ] company_id extracted from authenticated user
- [ ] Validation before business logic
- [ ] Clear error messages

---

### Phase 5: API Layer

---

## ⚠️ BEFORE YOU START PHASE 5 — READ THIS

**MANDATORY:** Before implementing Phase 5 (Controllers, Routes, Validation), you MUST:

1. **Verify Phase 1, 2, 3, & 4 complete** (all infrastructure, DTOs, repos, services done)
2. **Read backend-api-standards.md Sections 1, 7, 12** (Layers, Validation, Response Format)
3. **Review STANDARDS_CODE_PATTERNS.md Patterns 4, 5, 6** (Controllers, ApiResponse, FormRequests)
4. **Understand critical API requirements:**
   - Phase 2 response format was WRONG (missing statusCode, message, traceId)
   - EVERY response must use standard envelope (Section 12)
   - Validation MUST be in FormRequest (never in controller)
   - Controller = pass-through only (calls service, formats response)
5. **Verify your API plan** covers:
   - Every endpoint has a FormRequest
   - Every response uses successResponse() or paginatedResponse()
   - Every list endpoint includes complete meta (page, limit, totalRecords, totalPages, hasNextPage, hasPrevPage)
   - All routes under /api prefix with JWT middleware
   - Every response includes traceId
6. **Confirm you understand:**
   - FormRequest = input validation (before service)
   - Controller = orchestration (service calls + response formatting)
   - ApiResponse = standard envelope (always same format)
   - successResponse() = single resource response
   - paginatedResponse() = list with meta response

**Phase 5 Deliverable:** All endpoints with correct response format and validation

---

**9. Create FormRequest Validation**
- One file per operation: `app/Http/Requests/[Module]/Create[Resource]Request.php`
- Define all validation rules
- Include custom error messages
- Strip unknown fields

**Pattern:**
```php
class CreateProductRequest extends FormRequest {
    public function authorize(): bool {
        return true;  // JWT middleware handles auth
    }

    public function rules(): array {
        return [
            'sku' => 'required|string|unique:products',
            'name' => 'required|string|min:2|max:255',
            'price' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array {
        return [
            'sku.unique' => 'SKU already exists',
            'name.required' => 'Product name is required',
        ];
    }
}
```

**Checklist:**
- [ ] One FormRequest per operation
- [ ] All fields have validation rules
- [ ] Custom error messages provided
- [ ] Uses camelCase for field names (maps to snake_case)
- [ ] authorize() returns true

---

**10. Create Controllers**
- One file per resource: `app/Http/Controllers/Api/[Module]/[Resource]Controller.php`
- Inject service (via constructor)
- Call service methods
- Use `ApiResponse` trait for consistent responses
- NO business logic in controller

**Pattern:**
```php
class ProductController extends Controller {
    use ApiResponse;

    public function __construct(
        private readonly ProductService $service,
        private readonly Request $request,
    ) {}

    public function index(): JsonResponse {
        $page = (int)$this->request->input('page', 1);
        $limit = (int)$this->request->input('limit', 20);
        $companyId = $this->request->attributes->get('auth_company_id');

        $result = $this->service->list($companyId, $page, $limit);

        return $this->paginatedResponse(
            $result['data'],
            $page,
            $limit,
            $result['total'],
            'Products retrieved successfully',
            200
        );
    }

    public function store(CreateProductRequest $request): JsonResponse {
        $companyId = $request->attributes->get('auth_company_id');
        $product = $this->service->create($companyId, $request->validated());

        return $this->successResponse(
            $product->toArray(),
            'Product created successfully',
            201
        );
    }
}
```

**Checklist:**
- [ ] Service injected via constructor
- [ ] successResponse() for single resources
- [ ] paginatedResponse() for lists
- [ ] 201 status for POST (create)
- [ ] 204 No Content for DELETE (optional)
- [ ] All validation via FormRequest
- [ ] company_id extracted from request attributes

---

**11. Define Routes**
- File: `routes/api.php`
- Group by prefix
- Apply JWT middleware
- Use ResourceController RESTful pattern

**Pattern:**
```php
Route::prefix('products')->middleware('auth:api')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
});
```

**Checklist:**
- [ ] Routes grouped by prefix
- [ ] JWT middleware applied
- [ ] HTTP methods correct (GET, POST, PUT, DELETE)
- [ ] Method names follow convention (index, store, show, update, destroy)

---

### Phase 6: Testing Layer

---

## ⚠️ BEFORE YOU START PHASE 6 — READ THIS

**MANDATORY:** Before implementing Phase 6 (Tests), you MUST:

1. **Verify Phase 1-5 complete** (all code, controllers, routes implemented)
2. **Read backend-api-standards.md Section 9** (Test Cases)
3. **Review STANDARDS_CODE_PATTERNS.md Pattern 7** (Correct test structure)
4. **Understand critical testing requirements:**
   - Phase 2 tests were WRONG (checked only data field, missed envelope)
   - Tests MUST validate complete response structure
   - Tests MUST check: success, statusCode, message, data, [meta], traceId
   - Tests MUST check error paths (404, 422, etc.)
   - Tests MUST verify data integrity
5. **Verify your test plan** covers:
   - Every endpoint has tests (happy path + error paths)
   - Every response structure validated (all envelope fields)
   - Pagination meta validated (all 6 fields)
   - Error responses have traceId and errors array
   - Database assertions verify data saved correctly
6. **Confirm you understand:**
   - Test = validates endpoint behavior
   - RefreshDatabase = fresh DB per test
   - Happy path = success case
   - Error path = 404, 422, etc.
   - Response validation = check entire envelope, not just data

**Phase 6 Deliverable:** All tests passing, full response structure validated, 85+ tests

---

**12. Create Feature Tests**
- One file per resource: `tests/Feature/[Module]/[Resource]Test.php`
- Use `RefreshDatabase`
- Create authenticated user with JWT token
- Test all endpoints
- **CRITICAL:** Validate complete response structure

**Pattern:**
```php
class ProductTest extends TestCase {
    use RefreshDatabase;

    private string $token;
    private int $companyId;

    protected function setUp(): void {
        parent::setUp();
        $company = Company::factory()->create();
        $this->companyId = $company->id;
        $user = SaasUser::factory()
            ->owner()
            ->forCompany($company)
            ->create();
        $this->token = JWTAuth::fromUser($user);
    }

    public function test_list_products(): void {
        Product::factory()->count(3)->create(['company_id' => $this->companyId]);

        $response = $this->getJson('/api/products', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        // ✅ CRITICAL: Check FULL response structure
        $response->assertJsonStructure([
            'success',
            'statusCode',
            'message',
            'data' => ['*' => ['id', 'sku', 'name']],
            'meta' => [
                'page',
                'limit',
                'totalRecords',
                'totalPages',
                'hasNextPage',
                'hasPrevPage',
            ],
            'traceId',
        ]);
    }
}
```

**Checklist:**
- [ ] Tests use RefreshDatabase
- [ ] Authenticated requests have Bearer token
- [ ] Test happy path (success case)
- [ ] Test error paths (404, 422, etc.)
- [ ] Test pagination (if applicable)
- [ ] Validate FULL response structure (including traceId)
- [ ] Check statusCode field
- [ ] Check message field
- [ ] Database assertions verify data saved

---

## ✅ Code Review Checklist

Before submitting code, verify EVERY item:

### Response DTOs
- [ ] DTO class file exists
- [ ] All public fields are safe (no passwords/tokens)
- [ ] toArray() method implemented
- [ ] Type hints on all properties
- [ ] Timestamps formatted as ISO8601

### Mappers
- [ ] Mapper file exists
- [ ] toResponse() method handles single model
- [ ] toCollectionResponse() method handles collection
- [ ] Nested relations mapped to nested DTOs
- [ ] Null handling for optional relations

### Repositories
- [ ] Interface defined
- [ ] Implementation uses Eloquent
- [ ] ALL methods use with() for eager loading
- [ ] company_id filtering on every query
- [ ] No N+1 queries possible
- [ ] Binding in RepositoryServiceProvider

### Services
- [ ] All methods return DTOs (never arrays)
- [ ] Return type hints use DTO class names
- [ ] HttpException thrown for errors
- [ ] Multi-table operations wrapped in DB::transaction()
- [ ] Business logic only (no DB queries)
- [ ] company_id from authenticated user

### Controllers
- [ ] Service injected via constructor
- [ ] ApiResponse trait used
- [ ] successResponse() for single resources
- [ ] paginatedResponse() for lists
- [ ] 201 status for POST
- [ ] 204 for DELETE (optional)
- [ ] All validation via FormRequest
- [ ] No business logic

### FormRequests
- [ ] One file per operation
- [ ] Validation rules defined
- [ ] Custom error messages
- [ ] authorize() returns true
- [ ] Unknown fields stripped

### Routes
- [ ] Grouped by prefix
- [ ] JWT middleware applied
- [ ] HTTP methods correct
- [ ] Method names follow convention

### Tests
- [ ] All endpoints tested
- [ ] Happy path (success) tested
- [ ] Error paths (404, 422) tested
- [ ] Response structure validated (all fields)
- [ ] statusCode field checked
- [ ] message field checked
- [ ] traceId field checked
- [ ] For lists: meta fields checked
- [ ] All 87+ tests pass

### Database
- [ ] Migrations run cleanly
- [ ] Migrations rollback cleanly
- [ ] All columns explicitly typed
- [ ] Foreign keys with CASCADE DELETE
- [ ] Indexes on company_id
- [ ] Soft deletes where appropriate

---

## 🚨 Common Mistakes to AVOID

### ❌ Mistake 1: Raw Model Returns
```php
// WRONG
public function get($id): array {
    return Product::find($id)->toArray();
}

// CORRECT
public function get($id): ProductResponse {
    $product = $this->repo->findByIdAndCompany($id, $companyId);
    return ProductMapper::toResponse($product);
}
```

### ❌ Mistake 2: Lazy Loading in Repositories
```php
// WRONG
public function findByCompany($companyId) {
    return Product::where('company_id', $companyId)->get();
    // Relations lazy-loaded later = N+1 queries
}

// CORRECT
public function findByCompany($companyId) {
    return Product::where('company_id', $companyId)
        ->with(['category', 'supplier'])  // ← REQUIRED
        ->get();
}
```

### ❌ Mistake 3: Missing Transactions
```php
// WRONG
$order = Order::create([...]);
OrderItem::create([...]);  // If fails, orphaned order

// CORRECT
return DB::transaction(function() {
    $order = Order::create([...]);
    OrderItem::create([...]);
    return $order;
});
```

### ❌ Mistake 4: Wrong Response Format
```php
// WRONG
return response()->json(['data' => $product]);
// Missing: success, statusCode, message, traceId

// CORRECT
return $this->successResponse(
    $product->toArray(),
    'Product retrieved',
    200
);
// Includes: success, statusCode, message, data, traceId
```

### ❌ Mistake 5: Tests Validating Wrong Structure
```php
// WRONG
$response->assertJsonPath('data.id', 1);
// Only checks data field, ignores response envelope

// CORRECT
$response->assertJsonStructure([
    'success',
    'statusCode',
    'message',
    'data' => ['id', 'name'],
    'meta' => [...],
    'traceId',
]);
```

### ❌ Mistake 6: Business Logic in Controller
```php
// WRONG
public function store(Request $request) {
    $validation = validate($request->input());
    $discount = calculateDiscount($request->user());
    $price = applyDiscount($request->input('price'), $discount);
    $product = Product::create(['price' => $price]);
    return response()->json($product);
}

// CORRECT
public function store(CreateProductRequest $request) {
    $product = $this->service->create(
        $request->attributes->get('auth_company_id'),
        $request->validated()
    );
    return $this->successResponse($product->toArray());
}
```

### ❌ Mistake 7: No Eager Loading Plan
```php
// WRONG
$products = Product::all();
foreach ($products as $p) {
    echo $p->category->name;  // N+1 queries
}

// CORRECT
$products = Product::with('category')->get();
foreach ($products as $p) {
    echo $p->category->name;  // All loaded in 2 queries
}
```

---

## 📊 Progress Tracking

As you implement, mark off:

- [ ] Phase 1: Migrations & Models (completed)
- [ ] Phase 2: DTOs & Mappers (completed)
- [ ] Phase 3: Repositories (completed)
- [ ] Phase 4: Services (completed)
- [ ] Phase 5: Controllers & Routes (completed)
- [ ] Phase 6: Tests (completed)
- [ ] Phase 7: Seeder (optional, completed)
- [ ] Code review checklist (all items ✅)
- [ ] All tests passing
- [ ] Ready for deployment

---

## 🎯 Success Criteria

Module is complete when:

✅ **Database**
- All migrations pass
- All migrations rollback cleanly
- Schema matches specification

✅ **Code**
- No raw model data returned to clients
- All responses use standard envelope
- All repositories use eager loading
- All multi-table operations transacted
- All validation in FormRequest
- All services return DTOs

✅ **Tests**
- All tests pass
- All 87+ tests check full response structure
- Happy path tests
- Error path tests
- Response validation tests

✅ **Documentation**
- Code comments explain business logic
- API endpoints match specification
- Error messages clear and helpful

✅ **Standards**
- 100% DTO compliance
- 100% Response format compliance
- 100% Transaction safety
- 100% Eager loading (no N+1)
- 100% Test coverage of endpoints

---

## 🚀 Ready to Start?

**Confirm you have:**
1. ✅ Read the module specification
2. ✅ Read backend-api-standards.md (sections 1-12)
3. ✅ Completed pre-implementation checklist (all 4 steps)
4. ✅ Created implementation plan
5. ✅ Got user approval

**Then proceed with Phase 1 (Migrations & Models)**

---

**Template Version:** 1.0  
**Last Updated:** 2026-03-31  
**Status:** ACTIVE — Use for ALL modules in Phase 3+

