# Inventory API Quick Reference

**Status**: ✅ All bugs fixed and tested  
**Last Updated**: 2026-04-05

---

## Overview

This document provides quick reference for the inventory module endpoints after the bug fixes.

---

## Endpoint 1: Get Inventory (Inventory Page)

### Request
```bash
GET /api/inventory?page=1&per_page=100&search=shirt&location_id=1
Authorization: Bearer {token}
```

### Query Parameters
- `page` (int, default: 1) - Page number
- `per_page` (int, default: 10, max: 100) - Items per page
- `limit` (int, default: 10) - Legacy parameter, use per_page instead
- `search` (string, optional) - Search by product/variant name or SKU
- `location_id` (int, optional) - Filter by specific location

### Response: Variant Product
```json
{
  "success": true,
  "message": "Inventory retrieved successfully",
  "data": [
    {
      "type": "variant",
      "id": 1,
      "productId": 1,
      "productName": "T-Shirt",
      "variantName": "Red",
      "sku": "TSHIRT-RED",
      "barcode": null,
      "stock": 25,
      "inventory": [
        {
          "locationId": 1,
          "locationName": "Warehouse A",
          "quantity": 15
        },
        {
          "locationId": 2,
          "locationName": "Warehouse B",
          "quantity": 10
        }
      ]
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 15
    }
  }
}
```

### Response: Simple Product (State A - Never Transferred)
```json
{
  "success": true,
  "message": "Inventory retrieved successfully",
  "data": [
    {
      "type": "product",
      "id": 5,
      "productId": 5,
      "productName": "Shoes",
      "variantName": "",
      "sku": "SHOES-001",
      "barcode": null,
      "stock": 50,
      "inventory": [
        {
          "locationId": 1,
          "locationName": "Warehouse A",
          "quantity": 50
        }
      ]
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 1
    }
  }
}
```

### Response: Simple Product (State B - Transferred)
```json
{
  "success": true,
  "message": "Inventory retrieved successfully",
  "data": [
    {
      "type": "variant",
      "id": 101,
      "productId": 5,
      "productName": "Shoes",
      "variantName": "Default",
      "sku": "SHOES-001",
      "barcode": null,
      "stock": 50,
      "inventory": [
        {
          "locationId": 1,
          "locationName": "Warehouse A",
          "quantity": 30
        },
        {
          "locationId": 2,
          "locationName": "Warehouse B",
          "quantity": 20
        }
      ]
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 1
    }
  }
}
```

---

## Endpoint 2: Get Products by Location (Transfer Page)

### Request
```bash
GET /api/transfers/products-by-location/1?search=shirt&page=1&per_page=20
Authorization: Bearer {token}
```

### URL Parameters
- `locationId` (int, required) - Location ID to fetch products from

### Query Parameters
- `search` (string, optional) - Search by product name or SKU
- `sort_by` (string, default: 'name') - Field to sort by (name, sku, stock)
- `sort_dir` (string, default: 'asc') - Sort direction (asc, desc)
- `page` (int, default: 1) - Page number
- `per_page` (int, default: 20) - Items per page

### Response: Variant Product
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 1,
      "name": "Jeans",
      "sku": "JEANS",
      "stock": 45,
      "location_id": 1,
      "variants": [
        {
          "id": 10,
          "name": "32",
          "sku": "JEANS-32",
          "stock": 30
        },
        {
          "id": 11,
          "name": "34",
          "sku": "JEANS-34",
          "stock": 15
        }
      ]
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1
    }
  }
}
```

### Response: Simple Product (No Variants Key)
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 5,
      "name": "Shoes",
      "sku": "SHOES-001",
      "stock": 50,
      "location_id": 1
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1
    }
  }
}
```

---

## Key Differences Between Endpoints

### Inventory Endpoint (GET /api/inventory)
**Purpose**: Display inventory dashboard  
**Shows**: Individual variants and simple products  
**Groups**: By variant (not by product)  
**Includes**: Location breakdown for each item  
**Type Field**: 'variant' or 'product'  

### Transfer Page Endpoint (GET /api/transfers/products-by-location/{id})
**Purpose**: Select products for transfer  
**Shows**: Products (optionally with variants)  
**Groups**: By product (variants nested)  
**Includes**: Only location-specific stock  
**Variants Key**: Only for products with multiple variants (no 'Default' variant alone)  

---

## Understanding Variant Products vs Simple Products

### True Variant Products
```
Product: Jeans (ID: 1)
├─ Variant: 32 (ID: 10)
├─ Variant: 34 (ID: 11)
└─ Variant: 36 (ID: 12)
```

**Inventory API**: Shows 3 separate items (one per variant)
```json
[
  { "type": "variant", "variantName": "32", ... },
  { "type": "variant", "variantName": "34", ... },
  { "type": "variant", "variantName": "36", ... }
]
```

**Transfer Page API**: Shows product with variants array
```json
[
  {
    "id": 1,
    "name": "Jeans",
    "variants": [
      { "id": 10, "name": "32", ... },
      { "id": 11, "name": "34", ... },
      { "id": 12, "name": "36", ... }
    ]
  }
]
```

### Simple Products (State A - Never Transferred)
```
Product: Shoes (ID: 5)
├─ No variants
└─ Stock: 50 at Location 1
```

**Inventory API**: Shows as type='product'
```json
[
  { "type": "product", "productName": "Shoes", "variantName": "", ... }
]
```

**Transfer Page API**: Shows without variants key
```json
[
  { "id": 5, "name": "Shoes", "stock": 50 }
]
```

### Simple Products (State B - Transferred Once)
```
Product: Shoes (ID: 5)
├─ Virtual Variant: Default (ID: 101)
├─ Stock at Location 1: 30
└─ Stock at Location 2: 20
```

**Inventory API**: Shows as type='variant' with variantName='Default'
```json
[
  { "type": "variant", "productName": "Shoes", "variantName": "Default", "stock": 50, "inventory": [...] }
]
```

**Transfer Page API**: Shows as flat product (no variants key, because only variant is Default)
```json
[
  { "id": 5, "name": "Shoes", "stock": 30, "location_id": 1 }
]
```

---

## Common Scenarios

### Scenario 1: Create Product with Variants
1. Create product (no variants initially)
2. Add 2-3 variants
3. API returns variants in inventory list

**Inventory API**: Shows 2-3 variant items  
**Transfer Page API**: Shows product with variants array

### Scenario 2: Create Simple Product (Never Transferred)
1. Create product with stock but no variants
2. Assign to a location

**Inventory API**: Shows as type='product'  
**Transfer Page API**: Shows without variants key

### Scenario 3: Transfer Simple Product Between Locations
1. Create simple product at Location 1 with 50 units
2. Transfer 20 units to Location 2

**After Transfer**:
- Virtual `ProductVariant(name='Default')` created
- `variant_inventory` rows created for both locations
- Inventory shows: 30 at Loc1, 20 at Loc2
- Transfer page shows: flat product (no variants key)

### Scenario 4: Multi-location Inventory
1. Product with variants exists
2. Has inventory at multiple locations
3. Each location can have different variant quantities

**Inventory API**: Shows single item per variant with all location quantities  
**Transfer Page API**: Location-specific - shows only variants at that location

---

## Testing the Endpoints

### Test 1: Get Inventory
```bash
curl -X GET "http://localhost:8005/api/inventory?page=1&per_page=100" \
  -H "Authorization: Bearer {token}"
```

**Expected**: 
- Variants shown separately
- No parent products if variants exist
- Location breakdown included

### Test 2: Get Products by Location
```bash
curl -X GET "http://localhost:8005/api/transfers/products-by-location/1?page=1&per_page=20" \
  -H "Authorization: Bearer {token}"
```

**Expected**:
- Variant products include variants array
- Simple products exclude variants key
- Only products with stock at that location

### Test 3: Search
```bash
curl -X GET "http://localhost:8005/api/inventory?search=shirt" \
  -H "Authorization: Bearer {token}"
```

**Expected**:
- Results filtered by product/variant name or SKU
- Pagination respects total count

### Test 4: Location Filter
```bash
curl -X GET "http://localhost:8005/api/inventory?location_id=1" \
  -H "Authorization: Bearer {token}"
```

**Expected**:
- Only inventory for Location 1
- Total count reflects filtered results

---

## Field Descriptions

### Inventory Item Fields

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | 'product' or 'variant' |
| `id` | int | Variant ID (for variants) or Product ID (for products) |
| `productId` | int | Product ID |
| `productName` | string | Product name |
| `variantName` | string | Variant name (empty for products) |
| `sku` | string | Product/variant SKU |
| `barcode` | string | Product/variant barcode |
| `stock` | int | Total stock across all locations |
| `inventory` | array | Per-location breakdown |

### Inventory Location Fields

| Field | Type | Description |
|-------|------|-------------|
| `locationId` | int | Location ID |
| `locationName` | string | Location name |
| `quantity` | int | Stock quantity at this location |

### Transfer Product Fields (Variant)

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Product ID |
| `name` | string | Product name |
| `sku` | string | Product SKU |
| `stock` | int | Total stock across all variants |
| `location_id` | int | Location ID |
| `variants` | array | Array of variant objects (for multi-variant products) |

### Transfer Product Fields (Simple)

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Product ID |
| `name` | string | Product name |
| `sku` | string | Product SKU |
| `stock` | int | Stock quantity at location |
| `location_id` | int | Location ID |

---

## Error Responses

### Missing Authorization
```json
{
  "success": false,
  "message": "Unauthorized",
  "status": 401
}
```

### Invalid Location ID
```json
{
  "success": false,
  "message": "Not Found",
  "status": 404
}
```

### Invalid Parameters
```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "per_page": ["The per_page field must be less than or equal to 100."]
  },
  "status": 422
}
```

---

## Notes

- All timestamps are in UTC (ISO 8601 format)
- Pagination starts at page 1
- `per_page` is capped at 100 items maximum
- Search is case-insensitive
- Company isolation enforced via JWT token
- All responses wrapped in success/error envelope
- Meta information includes pagination details

---

## Next Steps

1. Test the endpoints with your specific data
2. Verify pagination works with large datasets
3. Confirm search functionality filters correctly
4. Test location-specific queries
5. Verify transfer page shows variants correctly

---

**For Issues**: Check the test file at `tests/Feature/Inventory/InventoryBugFixTest.php` for working examples.
