# Coupon Update API Guide

## Quick Rule for Frontend

**IF editing a coupon:**
- **Has image upload?** → Use `PUT /api/coupons/{id}/with-image` (form-data)
- **No image?** → Use `PUT /api/coupons/{id}` (JSON)

## Endpoint Comparison

| Scenario | Endpoint | Content-Type | Request Format |
|----------|----------|--------------|-----------------|
| Edit fields only (no image) | `PUT /api/coupons/{id}` | `application/json` | Raw JSON |
| Upload new image | `PUT /api/coupons/{id}/with-image` | `multipart/form-data` | Form data with file |
| Upload image + edit fields | `PUT /api/coupons/{id}/with-image` | `multipart/form-data` | Form data with file + fields |

## Example Payloads

### Option 1: Update fields only
```
PUT /api/coupons/4
Content-Type: application/json

{
  "campaign_name": "Summer Sale",
  "code": "SUMMER20",
  "discount": 20,
  "type": "percentage",
  "start_date": "2026-01-01",
  "end_date": "2026-01-31"
}
```

### Option 2: Upload image only
```
PUT /api/coupons/4/with-image
Content-Type: multipart/form-data

Form fields:
- image: <file>
```

### Option 3: Upload image + update fields
```
PUT /api/coupons/4/with-image
Content-Type: multipart/form-data

Form fields:
- image: <file>
- campaign_name: "Summer Sale"
- code: "SUMMER20"
- discount: 20
- type: "percentage"
- start_date: "2026-01-01"
- end_date: "2026-01-31"
```

## Decision Tree (for AI/Frontend)

```
User wants to edit coupon?
├── Is image being uploaded?
│   ├── YES → Use `/with-image` endpoint (form-data)
│   └── NO → Use regular endpoint (JSON)
└── Send request with appropriate format
```

## Date Format

Always use `YYYY-MM-DD` format for dates in both endpoints:
- ✅ Correct: `"2026-01-31"`
- ❌ Wrong: `"2026-01-31T23:59:59Z"`
