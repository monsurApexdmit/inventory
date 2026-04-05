# Transfer API Responses - Product ID 9 Scenario

**Product**: new product (ID: 9)  
**Location 1 (Main)**: Has both variants (10 units total)  
**Location 2 (Second Warehouse)**: Empty  

---

## Before Any Transfer

### API: GET /api/transfers/products?location_id=1

**Response**: Product 9 available for transfer
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 9,
      "name": "new product",
      "sku": "sdfasdf",
      "stock": 10,
      "location_id": 1,
      "variants": [
        {
          "id": 25,
          "name": "S / 42",
          "sku": "...",
          "stock": 5
        },
        {
          "id": 26,
          "name": "M / 42",
          "sku": "...",
          "stock": 5
        }
      ]
    }
    // ... other products
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 3
    }
  }
}
```

**Edit List Shows**:
```
Product 9: new product
├─ Total Stock: 10
├─ Variant S/42: 5 units ✓ Can transfer
└─ Variant M/42: 5 units ✓ Can transfer
```

---

### API: GET /api/transfers/products?location_id=2

**Response**: Product 9 NOT in this location
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    // Product 9 is NOT here (no stock)
    // Only other products with stock in Location 2
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 2
    }
  }
}
```

**Edit List Shows**:
```
Product 9 is NOT in the Location 2 list (no stock there)
```

---

## After Transferring Variant S/42 (5 units) to Location 2

### Step 1: Create Transfer Request

```
POST /api/transfers
Content-Type: application/json

{
  "productId": 9,
  "variantId": 25,
  "fromLocationId": 1,
  "toLocationId": 2,
  "quantity": 5,
  "status": "Completed"
}
```

**Response**: Transfer created successfully
```json
{
  "success": true,
  "message": "Transfer created successfully",
  "data": {
    "id": 1,
    "companyId": 11,
    "productId": 9,
    "variantId": 25,
    "fromLocationId": 1,
    "toLocationId": 2,
    "quantity": 5,
    "status": "Completed",
    "stockDeducted": true,
    "createdAt": "2026-04-05T12:00:00Z"
  }
}
```

### Step 2: Query Location 1 Transfer List Again

**API: GET /api/transfers/products?location_id=1**

**Response**: Product 9 still shows but with reduced stock
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 9,
      "name": "new product",
      "sku": "sdfasdf",
      "stock": 5,              // ← CHANGED from 10 to 5
      "location_id": 1,
      "variants": [
        {
          "id": 25,
          "name": "S / 42",
          "sku": "...",
          "stock": 0            // ← CHANGED from 5 to 0
        },
        {
          "id": 26,
          "name": "M / 42",
          "sku": "...",
          "stock": 5            // ← UNCHANGED
        }
      ]
    }
    // ... other products
  ]
}
```

**Edit List Shows**:
```
Product 9: new product
├─ Total Stock: 5 (was 10)     ← Decreased
├─ Variant S/42: 0 units (was 5)  ← Transferred!
└─ Variant M/42: 5 units       ← Still available for transfer
```

### Step 3: Query Location 2 Transfer List Again

**API: GET /api/transfers/products?location_id=2**

**Response**: Product 9 now appears in Location 2!
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 9,
      "name": "new product",
      "sku": "sdfasdf",
      "stock": 5,              // ← NEW: Product now in Loc 2
      "location_id": 2,
      "variants": [
        {
          "id": 25,
          "name": "S / 42",
          "sku": "...",
          "stock": 5            // ← The transferred variant!
        }
        // Note: M/42 is NOT here (wasn't transferred)
      ]
    }
    // ... other products
  ]
}
```

**Edit List Shows** (Location 2):
```
Product 9: new product
├─ Total Stock: 5 (was 0)
└─ Variant S/42: 5 units (just arrived!)
```

---

## After Transferring Variant M/42 (5 units) to Location 2

### Step 1: Create Second Transfer Request

```
POST /api/transfers
Content-Type: application/json

{
  "productId": 9,
  "variantId": 26,
  "fromLocationId": 1,
  "toLocationId": 2,
  "quantity": 5,
  "status": "Completed"
}
```

### Step 2: Query Location 1 Transfer List

**API: GET /api/transfers/products?location_id=1**

**Response**: Product 9 DISAPPEARS (no stock left)
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    // ❌ Product 9 is NOT in this response!
    // (stock = 0, so filtered out)
    // Only other products with available stock
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 1               // ← Reduced from 3
    }
  }
}
```

**Edit List Shows** (Location 1):
```
Product 9 is GONE! (no stock to transfer)
```

**Database State** (Location 1):
```
Variant 25 (S/42): 0 units (all transferred)
Variant 26 (M/42): 0 units (all transferred)
Product Total: 0 units
```

### Step 3: Query Location 2 Transfer List

**API: GET /api/transfers/products?location_id=2**

**Response**: Product 9 now has FULL stock
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 9,
      "name": "new product",
      "sku": "sdfasdf",
      "stock": 10,             // ← CHANGED from 5 to 10
      "location_id": 2,
      "variants": [
        {
          "id": 25,
          "name": "S / 42",
          "sku": "...",
          "stock": 5
        },
        {
          "id": 26,
          "name": "M / 42",
          "sku": "...",
          "stock": 5           // ← Just arrived!
        }
      ]
    }
    // ... other products
  ]
}
```

**Edit List Shows** (Location 2):
```
Product 9: new product
├─ Total Stock: 10 (was 5)     ← Increased
├─ Variant S/42: 5 units       ← Already here
└─ Variant M/42: 5 units       ← Just arrived!
```

**Database State** (Location 2):
```
Variant 25 (S/42): 5 units
Variant 26 (M/42): 5 units
Product Total: 10 units
```

---

## Key API Behavior

### Products Filtering Logic

Products appear in the transfer list **IF AND ONLY IF**:
```
stock > 0
AND
has_variants_with_stock > 0
```

### Before Transfer
```
Location 1: stock=10 → ✅ SHOWS
Location 2: stock=0  → ❌ HIDDEN
```

### After 1st Transfer (S/42 only)
```
Location 1: stock=5  → ✅ STILL SHOWS (M/42 available)
Location 2: stock=5  → ✅ NOW SHOWS (S/42 arrived)
```

### After 2nd Transfer (Both transferred)
```
Location 1: stock=0  → ❌ HIDDEN (no more stock)
Location 2: stock=10 → ✅ SHOWS (both variants present)
```

---

## Expected Edit List Behavior

### Timeline Summary

**BEFORE**:
```
Location 1 Edit List:
  ├─ Product 9: 10 units
  │  ├─ S/42: 5 units ✓
  │  └─ M/42: 5 units ✓

Location 2 Edit List:
  └─ [Empty or other products]
```

**AFTER 1st Transfer**:
```
Location 1 Edit List:
  ├─ Product 9: 5 units
  │  ├─ S/42: 0 units ✗
  │  └─ M/42: 5 units ✓

Location 2 Edit List:
  ├─ Product 9: 5 units
  │  ├─ S/42: 5 units ✓
  │  └─ [M/42: NOT LISTED]
```

**AFTER ALL TRANSFERS**:
```
Location 1 Edit List:
  └─ [Product 9 GONE - no stock]

Location 2 Edit List:
  ├─ Product 9: 10 units
  │  ├─ S/42: 5 units ✓
  │  └─ M/42: 5 units ✓
```

---

## Database Changes

### variant_inventory Table

**Before**:
```
| variant_id | location_id | quantity |
|------------|-------------|----------|
| 25         | 1           | 5        |
| 26         | 1           | 5        |
```

**After 1st Transfer**:
```
| variant_id | location_id | quantity |
|------------|-------------|----------|
| 25         | 1           | 0        | ← Deducted
| 26         | 1           | 5        | ← Unchanged
| 25         | 2           | 5        | ← Added (new row)
```

**After 2nd Transfer**:
```
| variant_id | location_id | quantity |
|------------|-------------|----------|
| 25         | 1           | 0        | ← Still 0
| 26         | 1           | 0        | ← Deducted
| 25         | 2           | 5        | ← Unchanged
| 26         | 2           | 5        | ← Added (new row)
```

---

## Summary Response

| Aspect | State | Status |
|--------|-------|--------|
| **Location 1 - Before** | Shows Product 9 (10 units) | ✅ Visible |
| **Location 1 - After partial** | Shows Product 9 (5 units) | ✅ Visible |
| **Location 1 - After all** | Product 9 gone | ❌ Hidden |
| **Location 2 - Before** | No Product 9 | ❌ Hidden |
| **Location 2 - After partial** | Shows Product 9 (5 units) | ✅ Visible |
| **Location 2 - After all** | Shows Product 9 (10 units) | ✅ Visible |

---

**Key Takeaway**: Products dynamically appear and disappear from the edit/transfer list based on available stock at each location. Stock is never lost - it moves from one location to another! 🎯
