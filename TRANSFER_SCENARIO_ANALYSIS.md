# Stock Transfer Scenario Analysis - Product ID 9

**Product**: new product (ID: 9)  
**Variants**: 2 variants (S/42, M/42, both stock=5 each)  
**Current Location**: Location 1 (Main)  
**Proposed Transfer**: Location 1 → Location 2 (Second Wire House)

---

## Current State (Before Transfer)

### Product Details
```
ID: 9
Name: new product
SKU: sdfasdf
Current Location: 1 (Main warehouse)
Total Stock: 10 units
```

### Variant Breakdown
```
Variant 1: S / 42
  └─ Stock: 5 units
  └─ Location 1 (Main): 5 units ✓

Variant 2: M / 42
  └─ Stock: 5 units
  └─ Location 1 (Main): 5 units ✓

Total: 10 units in Location 1
```

### Transfer List API Response (GET /api/transfers/products?location_id=1)
```json
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
```

---

## Transfer Scenarios

### Scenario A: Transfer Variant "S / 42" (5 units) from Loc 1 → Loc 2

**Transfer Request**:
```json
{
  "productId": 9,
  "variantId": 25,
  "fromLocationId": 1,
  "toLocationId": 2,
  "quantity": 5
}
```

**After Transfer - Inventory State**:
```
Variant 25 (S / 42):
  ├─ Location 1 (Main): 0 units        ← Deducted
  └─ Location 2 (Second Warehouse): 5 units  ← Added

Variant 26 (M / 42):
  └─ Location 1 (Main): 5 units        ← Unchanged

Total in Location 1: 5 units
Total in Location 2: 5 units
```

**Edit/Transfer List API Response for Location 1**:
```json
{
  "id": 9,
  "name": "new product",
  "sku": "sdfasdf",
  "stock": 5,              // ← Changed from 10 to 5
  "location_id": 1,
  "variants": [
    {
      "id": 25,
      "name": "S / 42",
      "sku": "...",
      "stock": 0            // ← Changed from 5 to 0
    },
    {
      "id": 26,
      "name": "M / 42",
      "sku": "...",
      "stock": 5            // ← Unchanged
    }
  ]
}
```

**What Shows in Edit List**:
- Product still appears in Location 1 (has 5 units left)
- Variant "S / 42" now shows 0 stock
- Variant "M / 42" still shows 5 stock
- Can transfer more of "M / 42" if needed

---

### Scenario B: Transfer ALL Stock (Both Variants) from Loc 1 → Loc 2

**Transfer Requests**:
```
1. Transfer Variant 25 (S / 42): 5 units, Loc 1 → Loc 2
2. Transfer Variant 26 (M / 42): 5 units, Loc 1 → Loc 2
```

**After Both Transfers - Inventory State**:
```
Variant 25 (S / 42):
  ├─ Location 1 (Main): 0 units
  └─ Location 2 (Second Warehouse): 5 units

Variant 26 (M / 42):
  ├─ Location 1 (Main): 0 units
  └─ Location 2 (Second Warehouse): 5 units

Total in Location 1: 0 units
Total in Location 2: 10 units
```

**Edit/Transfer List API Response for Location 1**:
```json
{
  "id": 9,
  "name": "new product",
  "sku": "sdfasdf",
  "stock": 0,              // ← Total stock is 0
  "location_id": 1,
  "variants": [
    {
      "id": 25,
      "name": "S / 42",
      "sku": "...",
      "stock": 0            // ← All stock transferred
    },
    {
      "id": 26,
      "name": "M / 42",
      "sku": "...",
      "stock": 0            // ← All stock transferred
    }
  ]
}
```

**What Shows in Edit List**:
- Product DISAPPEARS from Location 1 transfer list (because stock = 0)
- Product NOW APPEARS in Location 2 transfer list with 10 units
- All variants show 0 stock in Location 1
- Cannot transfer more from Location 1

---

## API Response Behavior

### Key Points:

1. **Products with stock > 0 appear** in the transfer list for their location
2. **Products with stock = 0 disappear** from the transfer list
3. **Variants show individual stock** amounts per variant
4. **Total stock = sum of all variant quantities**

### Database Changes During Transfer:

```
Before Transfer:
variant_inventory table:
┌─────────┬────────────────┬───────────┬──────────┐
│ id      │ variant_id     │ location_id │ quantity │
├─────────┼────────────────┼───────────┼──────────┤
│ 1       │ 25 (S/42)      │ 1         │ 5        │
│ 2       │ 26 (M/42)      │ 1         │ 5        │
└─────────┴────────────────┴───────────┴──────────┘

After Transfer (Variant 25 only):
┌─────────┬────────────────┬───────────┬──────────┐
│ id      │ variant_id     │ location_id │ quantity │
├─────────┼────────────────┼───────────┼──────────┤
│ 1       │ 25 (S/42)      │ 1         │ 0        │ ← Deducted
│ 2       │ 26 (M/42)      │ 1         │ 5        │
│ 3       │ 25 (S/42)      │ 2         │ 5        │ ← Added (or updated)
└─────────┴────────────────┴───────────┴──────────┘
```

---

## Scenario Summary Table

| Aspect | Before Transfer | After Variant 25 Transfer | After All Transfer |
|--------|-----------------|---------------------------|------------------|
| **Location 1 Total Stock** | 10 | 5 | 0 |
| **Variant 25 (S/42)** | 5 | 0 | 0 |
| **Variant 26 (M/42)** | 5 | 5 | 0 |
| **Appears in Loc 1 List?** | ✅ Yes | ✅ Yes (5 left) | ❌ No (0 stock) |
| **Appears in Loc 2 List?** | ❌ No | ❌ No (partial) | ✅ Yes (10 units) |

---

## Step-by-Step Transfer Process

### Step 1: View Transfer List for Location 1
```
GET /api/transfers/products?location_id=1

Response includes:
  - Product 9 with 10 units total
  - Variant 25: 5 units available
  - Variant 26: 5 units available
```

### Step 2: Create Transfer Request
```
POST /api/transfers

Body:
{
  "productId": 9,
  "variantId": 25,
  "fromLocationId": 1,
  "toLocationId": 2,
  "quantity": 5
}

Backend Operations:
  1. Check: Variant 25 has 5 units in Location 1 ✓
  2. Deduct: variant_inventory[Var25, Loc1] -= 5
  3. Add: variant_inventory[Var25, Loc2] += 5
  4. Create: stock_transfer record
  5. Response: Transfer successful
```

### Step 3: View Updated Transfer List for Location 1
```
GET /api/transfers/products?location_id=1

Response now shows:
  - Product 9 with 5 units total (down from 10)
  - Variant 25: 0 units (down from 5)
  - Variant 26: 5 units (unchanged)
```

### Step 4: Transfer Remaining Stock
```
POST /api/transfers

Body:
{
  "productId": 9,
  "variantId": 26,
  "fromLocationId": 1,
  "toLocationId": 2,
  "quantity": 5
}
```

### Step 5: Final Transfer List for Location 1
```
GET /api/transfers/products?location_id=1

Product 9 DISAPPEARS from list (stock = 0)
```

### Step 6: View Transfer List for Location 2
```
GET /api/transfers/products?location_id=2

Response now shows:
  - Product 9 with 10 units total
  - Variant 25: 5 units
  - Variant 26: 5 units
```

---

## Important Notes

### ✅ What Happens:
1. **Inventory moves** from Location 1 to Location 2
2. **Variants track separately** - each variant's stock updated independently
3. **Product stock = sum** of all variant stocks at that location
4. **Edit list updates dynamically** - shows only products with available stock
5. **Full transferability** - Can transfer any amount up to available stock

### ❌ Cannot Happen:
- ❌ Cannot transfer stock that doesn't exist
- ❌ Cannot transfer to same location (validation prevents this)
- ❌ Cannot transfer product (only variants individually)
- ❌ Stock doesn't disappear - it moves to another location

---

## Real-World Scenario

**Company**: Startup Inc (11)  
**Locations**: Main (1), Second Warehouse (2)

**Situation**:
```
Product 9 (new product) in Main warehouse:
  ├─ Variant S/42: 5 units in Main
  └─ Variant M/42: 5 units in Main

New warehouse (Loc 2) is opening, need to stock items there.

Decision: Transfer all of Product 9 to the new warehouse
```

**Process**:
1. Go to Main warehouse transfer list → See Product 9 with 10 units
2. Click transfer → Select Variant S/42 → 5 units
3. Transfer → Product 9 now shows 5 units in Main list
4. Click transfer again → Select Variant M/42 → 5 units
5. Transfer → Product 9 DISAPPEARS from Main list (0 units)
6. Check Location 2 transfer list → Product 9 appears with 10 units
7. Done! ✅

---

## API Response Format

When listing products available for transfer in Location 1:

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

---

## Summary

**Before Transfer**: Product shows in Location 1 with both variants available (10 units total)

**During Transfer**: Product stock decreases as variants are transferred one by one

**After All Transfers**: Product disappears from Location 1 list (stock = 0), appears in Location 2 list (stock = 10)

**Each variant transfers independently** but collectively affect the product's total stock in that location.

---

**Status**: Clear scenario analysis ✅  
**Ready to transfer**: Yes, product is properly set up with location-specific inventory
