# Quick Transfer Reference - Product ID 9

## Current State
```
Product: new product (ID: 9)
Location 1 (Main): 10 units total
  ├─ Variant S/42: 5 units
  └─ Variant M/42: 5 units

Location 2 (Second Warehouse): 0 units
  └─ Nothing yet
```

---

## Scenario: Transfer All Stock to Location 2

### Before Transfer
**Edit List - Location 1**:
```
Product 9: new product
├─ Total Stock: 10
├─ Variant S/42: 5 units ← Available to transfer
└─ Variant M/42: 5 units ← Available to transfer
```

**Edit List - Location 2**:
```
[Product 9 is NOT in this list]
```

### Step 1: Transfer S/42 (5 units)
```
From Location 1 → Location 2
```

**After Step 1**:

**Edit List - Location 1**:
```
Product 9: new product
├─ Total Stock: 5  ← Decreased!
├─ Variant S/42: 0 units  ← Transferred!
└─ Variant M/42: 5 units  ← Still here
```

**Edit List - Location 2**:
```
Product 9: new product
├─ Total Stock: 5  ← Just appeared!
├─ Variant S/42: 5 units  ← Received!
└─ [M/42 NOT listed yet]
```

### Step 2: Transfer M/42 (5 units)
```
From Location 1 → Location 2
```

**After Step 2 (FINAL)**:

**Edit List - Location 1**:
```
[Product 9 DISAPPEARS - no stock left!]
```

**Edit List - Location 2**:
```
Product 9: new product
├─ Total Stock: 10  ← Full stock!
├─ Variant S/42: 5 units  ← Already here
└─ Variant M/42: 5 units  ← Just arrived!
```

---

## API Calls

### Before Transfer
```
GET /api/transfers/products?location_id=1
→ Returns: Product 9 with 10 units

GET /api/transfers/products?location_id=2
→ Returns: [Empty - no Product 9]
```

### After Both Transfers
```
GET /api/transfers/products?location_id=1
→ Returns: [Empty - no Product 9, stock=0]

GET /api/transfers/products?location_id=2
→ Returns: Product 9 with 10 units
```

---

## Summary

| Metric | Before | After Step 1 | After Step 2 |
|--------|--------|--------------|--------------|
| **Loc 1 Total** | 10 | 5 | 0 |
| **Loc 1: S/42** | 5 | 0 | 0 |
| **Loc 1: M/42** | 5 | 5 | 0 |
| **Loc 2 Total** | 0 | 5 | 10 |
| **Loc 2: S/42** | 0 | 5 | 5 |
| **Loc 2: M/42** | 0 | 0 | 5 |
| **In Loc 1 List?** | ✅ YES | ✅ YES | ❌ NO |
| **In Loc 2 List?** | ❌ NO | ✅ YES | ✅ YES |

---

## Answer to Your Question

**"If I transfer Product ID 9 from Location 1 to Location 2, what will show in the edit list?"**

**Answer**: 
- ✅ **Product 9 stays in Location 1 list** (while you're transferring)
  - Shows decreasing stock as each variant is transferred
  - Eventually disappears when all stock is transferred

- ✅ **Product 9 appears in Location 2 list** (as transfer completes)
  - Shows increasing stock as variants arrive
  - Shows both variants once all transfers complete

---

**Key Point**: Products move between locations in the edit list based on stock availability. It's always there, just appears/disappears based on where the stock is! 🎯
