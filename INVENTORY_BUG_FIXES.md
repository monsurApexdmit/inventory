# Inventory Module Bug Fixes — Complete

## Summary

Fixed 3 related bugs in the inventory management system caused by inconsistent handling of simple products in two states:
- **State A**: Never transferred (stock in `products` table, no `product_variants` row)
- **State B**: Transferred at least once (stock in `variant_inventory` via virtual "Default" variant in `product_variants`)

---

## Bug 1: Inventory List Shows Both Parent Product AND Variants

**File Modified:** `app/Services/Inventory/InventoryService.php`  
**Method:** `getSimpleProductInventory()` (lines 72–106)

### Problem
No exclusion of products that have variants. A State B product appeared in BOTH:
- Simple query (showing stale `products.stock`)
- Variant query (showing current `variant_inventory` data)

### Solution
Added `whereNotExists` subquery to exclude products with any non-deleted variant:

```php
->whereNotExists(function ($sub) {
    $sub->select(DB::raw(1))
        ->from('product_variants as pv_check')
        ->whereColumn('pv_check.product_id', 'p.id')
        ->whereNull('pv_check.deleted_at');
})
```

### Result
✅ Inventory list now shows **only variant products** when variants exist  
✅ Parent products with variants no longer appear  
✅ Simple products without variants still appear correctly

---

## Bug 2: Transfer Page Doesn't Show Variants

**File Modified:** `app/Repositories/Eloquent/StockTransferRepository.php`  
**Method:** `getProductsByLocation()` (lines 74–104) — Complete rewrite

### Problem
- Only queried `products` table, never joined to `product_variants` or `variant_inventory`
- Never returned `variants` array, so frontend's `flattenLocationProducts()` always showed parent product
- Missed variant-based stock tracking used by transfer system

### Solution
Two-query approach with PHP grouping:

**Query 1:** Variants with inventory at location
```sql
SELECT p.id, p.name, p.sku, pv.id AS variant_id, pv.name, pv.sku, vi.quantity
FROM product_variants pv
JOIN products p ON pv.product_id = p.id
JOIN variant_inventory vi ON vi.variant_id = pv.id
WHERE p.company_id = ? AND vi.location_id = ? AND vi.quantity > 0
```

**Query 2:** State A simple products (never transferred)
```sql
SELECT p.id, p.name, p.sku, p.stock
FROM products p
WHERE p.company_id = ? AND p.location_id = ? AND p.stock > 0
  AND NOT EXISTS (SELECT 1 FROM product_variants WHERE product_id = p.id)
```

**Grouping Logic:**
- If product has only one variant named `'Default'` → emit as flat product (simple product after transfer)
- Otherwise → emit with `variants` array (true variant products)
- Append State A simple products as flat items

### Result
✅ Transfer page now shows individual variants for variant products  
✅ Simple products appear as single flat items  
✅ Frontend's `flattenLocationProducts()` now works correctly with `variants` array  
✅ Consistent with inventory list behavior

---

## Bug 3: After Transfer, Destination Warehouse Stock Not Visible

**Status:** ✅ **Auto-fixed by Bug 1**

### Why
After a simple product transfer:
1. `transferSimpleProductStock` creates virtual "Default" variant + `variant_inventory` rows for source AND destination
2. Bug 1 fix excludes parent product from simple query (it now has a variant)
3. `getVariantProductInventory` picks up both location rows
4. Both source and destination appear correctly with correct quantities

### Verification
- Stock is reduced from source warehouse ✅
- Stock is added to destination warehouse ✅
- Both visible on inventory page ✅

---

## Testing

### New Test Suite
**File:** `tests/Feature/Inventory/InventoryBugFixTest.php`

6 comprehensive tests covering all scenarios:
1. ✅ `test_inventory_excludes_parent_product_with_variants` — Inventory page shows only variants
2. ✅ `test_transfer_page_excludes_parent_product_with_variants` — Transfer page shows variants array
3. ✅ `test_inventory_updates_after_simple_product_transfer` — After transfer, both locations visible
4. ✅ `test_simple_product_without_transfer_appears_correctly` — Simple products still work
5. ✅ `test_transfer_page_shows_simple_products` — Transfer page shows simple products
6. ✅ `test_transfer_page_shows_transferred_simple_product_as_flat` — Transferred simple products appear flat

### Test Results
```
✓ InventoryBugFixTest ........................... 6 passed (36 assertions)
✓ InventoryTest .............................. 11 passed (56 assertions)
✓ StockTransferTest .......................... 17 passed (163 assertions)

Total: 34 tests passed, 0 failed
```

---

## Affected API Endpoints

### Inventory List
**GET** `/api/inventory`

**Response Change:** Parent products with variants no longer appear
```json
{
  "data": [
    {
      "type": "variant",
      "id": 1,
      "productId": 1,
      "productName": "T-Shirt",
      "variantName": "Red",
      "inventory": [
        {"locationId": 1, "locationName": "Warehouse A", "quantity": 10}
      ]
    }
  ]
}
```

### Transfer Page — Get Products by Location
**GET** `/api/transfers/products-by-location/:locationId`

**Response Change:** Now returns `variants` array for variant products
```json
{
  "data": [
    {
      "id": 1,
      "name": "T-Shirt",
      "sku": "TSHIRT",
      "stock": 25,
      "location_id": 1,
      "variants": [
        {
          "id": 101,
          "name": "Red",
          "sku": "TSHIRT-RED",
          "stock": 10
        },
        {
          "id": 102,
          "name": "Blue",
          "sku": "TSHIRT-BLUE",
          "stock": 15
        }
      ]
    },
    {
      "id": 2,
      "name": "Shoes",
      "sku": "SHOES",
      "stock": 50,
      "location_id": 1
    }
  ]
}
```

---

## Frontend Compatibility

**No frontend changes required!** 

The frontend's `flattenLocationProducts()` function was already written correctly:
```typescript
if (product.variants && product.variants.length > 0) {
  // Emit one row per variant
} else {
  // Emit the product as a flat item
}
```

It just wasn't working because the backend never returned the `variants` array. Now it does!

---

## Verification Checklist

- [x] Inventory list shows only variants when a product has variants
- [x] Inventory list shows parent product when it has no variants
- [x] Transfer page product selector shows individual variants
- [x] Transfer page product selector shows simple products as flat items
- [x] After transfer, both source and destination warehouse stock visible
- [x] All existing tests still pass
- [x] New tests verify all three bug fixes
- [x] API response format matches frontend expectations
