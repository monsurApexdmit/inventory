# Orders/Sells API Documentation

**Base URL**: `http://localhost:8005/api`  
**Authentication**: Bearer Token (JWT)  
**Content-Type**: `application/json`

---

## Authentication

All endpoints require a valid JWT token in the `Authorization` header:

```
Authorization: Bearer {jwt_token}
```

The token contains `auth_company_id` which automatically scopes all requests to the user's company.

---

## Error Responses

### Standard Error Format
```json
{
  "success": false,
  "message": "Error description",
  "error": "error_code"
}
```

### HTTP Status Codes
- `400`: Invalid input (validation failure, business logic violation)
- `401`: Unauthorized (missing or invalid token)
- `404`: Not found (resource doesn't exist or outside company scope)
- `409`: Conflict (duplicate invoice, insufficient stock)
- `422`: Unprocessable entity (validation error with details)
- `500`: Server error

---

## Endpoints

### 1. List Orders

**GET** `/sells`

List all orders for the authenticated company with pagination and filtering.

#### Query Parameters
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number for pagination |
| `per_page` | int | 10 | Items per page (max 100) |
| `limit` | int | - | If provided, returns this many items without pagination |
| `search` | string | - | Search by customer name or invoice number |
| `status` | string | - | Filter by order status (Pending, Processing, Delivered) |
| `method` | string | - | Filter by payment method (Cash, Card, Online) |
| `customer_id` | int | - | Filter by customer ID |
| `start_date` | date | - | Filter orders on or after this date (YYYY-MM-DD) |
| `end_date` | date | - | Filter orders on or before this date (YYYY-MM-DD) |

#### Request Example
```bash
curl -X GET "http://localhost:8005/api/sells?page=1&per_page=10&status=Pending" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json"
```

#### Response (200 OK)
```json
{
  "success": true,
  "message": "Sells retrieved successfully",
  "data": [
    {
      "id": 1,
      "invoiceNo": "INV-1712345678",
      "customerName": "John Doe",
      "amount": 99.99,
      "status": "Pending",
      "orderTime": "2026-04-05T10:30:00Z",
      "items": [
        {
          "id": 1,
          "productName": "T-Shirt",
          "quantity": 2,
          "unitPrice": 49.99,
          "totalPrice": 99.98,
          "unitCost": 25.00,
          "totalCost": 50.00
        }
      ]
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 100,
      "last_page": 10
    }
  }
}
```

#### Response with `limit` parameter (no pagination meta)
```json
{
  "success": true,
  "message": "Sells retrieved successfully",
  "data": [...]
}
```

---

### 2. Create Order

**POST** `/sells`

Create a new order with automatic stock deduction.

#### Request Body
```json
{
  "customerName": "John Doe",
  "customerId": 1,
  "amount": 99.99,
  "method": "Card",
  "status": "Pending",
  "paymentStatus": "pending",
  "fulfillmentStatus": "unfulfilled",
  "shippingCost": 10.00,
  "couponId": 1,
  "notes": "Handle with care",
  "items": [
    {
      "productId": 1,
      "variantId": null,
      "productName": "T-Shirt",
      "variantName": null,
      "quantity": 2,
      "unitPrice": 49.99
    }
  ],
  "shippingAddress": {
    "fullName": "John Doe",
    "phone": "+1234567890",
    "email": "john@example.com",
    "addressLine1": "123 Main St",
    "addressLine2": "Apt 4B",
    "city": "New York",
    "state": "NY",
    "postalCode": "10001",
    "country": "USA",
    "type": "delivery"
  }
}
```

#### Shipping Address Options

**Option 1: Inline Address** (in payload above)
```json
{
  "shippingAddress": { ...full address object... }
}
```

**Option 2: Saved Address ID**
```json
{
  "shippingAddressId": 5
}
```

**Option 3: Customer Default** (omit shipping address entirely)
- Uses customer's default shipping address

#### Validation Rules
| Field | Required | Rules |
|-------|----------|-------|
| `customerName` | Yes | String, max 255 |
| `amount` | Yes | Numeric, > 0 |
| `method` | No | One of: Cash, Card, Online |
| `items` | Yes | Array, min 1 item |
| `items[].productId` | Yes | Integer, product must exist |
| `items[].quantity` | Yes | Integer, > 0, sufficient stock available |
| `items[].unitPrice` | Yes | Numeric, > 0 |

#### Request Example
```bash
curl -X POST "http://localhost:8005/api/sells" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "customerName": "Jane Smith",
    "amount": 99.99,
    "method": "Card",
    "items": [{
      "productId": 1,
      "productName": "T-Shirt",
      "quantity": 2,
      "unitPrice": 49.99
    }]
  }'
```

#### Response (201 Created)
```json
{
  "success": true,
  "message": "Sell created successfully",
  "data": {
    "id": 1,
    "invoiceNo": "INV-1712345678",
    "customerName": "Jane Smith",
    "amount": 99.99,
    "status": "Pending",
    "stockDeducted": true,
    "items": [...],
    "createdAt": "2026-04-05T10:35:00Z"
  }
}
```

#### Stock Deduction Logic
- **Simple Products**: Deducted from `products.stock`
- **Variant Products**: Deducted from `variant_inventory` at customer location
- **Fallback**: If variant inventory row missing, uses `product_variants.stock`
- **Transaction**: All-or-nothing (rolls back if any item fails)

#### Error Responses
- `400`: Insufficient stock for product
- `400`: Invalid product or variant ID
- `400`: Coupon not found or invalid
- `422`: Validation error (missing required fields)

---

### 3. Get Order by ID

**GET** `/sells/{id}`

Retrieve a single order with all relationships.

#### Request Example
```bash
curl -X GET "http://localhost:8005/api/sells/1" \
  -H "Authorization: Bearer {token}"
```

#### Response (200 OK)
```json
{
  "success": true,
  "message": "Sell retrieved successfully",
  "data": {
    "id": 1,
    "invoiceNo": "INV-1712345678",
    "customerId": 5,
    "customer": {
      "id": 5,
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+1234567890"
    },
    "customerName": "John Doe",
    "amount": 99.99,
    "status": "Pending",
    "paymentStatus": "pending",
    "fulfillmentStatus": "unfulfilled",
    "shippingFullName": "John Doe",
    "shippingPhone": "+1234567890",
    "shippingEmail": "john@example.com",
    "shippingAddressLine1": "123 Main St",
    "shippingAddressLine2": "Apt 4B",
    "shippingCity": "New York",
    "shippingState": "NY",
    "shippingPostalCode": "10001",
    "shippingCountry": "USA",
    "shippingAddressType": "delivery",
    "method": "Card",
    "discount": 0.00,
    "shippingCost": 10.00,
    "totalCost": 50.00,
    "grossProfit": 49.99,
    "trackingNumber": null,
    "carrier": null,
    "shippedAt": null,
    "deliveredAt": null,
    "items": [
      {
        "id": 1,
        "productId": 1,
        "productName": "T-Shirt",
        "variantId": null,
        "variantName": null,
        "quantity": 2,
        "unitPrice": 49.99,
        "totalPrice": 99.98,
        "unitCost": 25.00,
        "totalCost": 50.00
      }
    ],
    "shipments": [],
    "createdAt": "2026-04-05T10:35:00Z",
    "updatedAt": "2026-04-05T10:35:00Z"
  }
}
```

#### Error Responses
- `401`: Unauthorized (missing token)
- `404`: Order not found or belongs to different company

---

### 4. Get Order by Invoice Number

**GET** `/sells/invoice/{invoiceNo}`

Retrieve a single order by its invoice number.

#### Request Example
```bash
curl -X GET "http://localhost:8005/api/sells/invoice/INV-1712345678" \
  -H "Authorization: Bearer {token}"
```

#### Response (200 OK)
Same as "Get Order by ID" endpoint.

#### Error Responses
- `401`: Unauthorized
- `404`: Order not found

---

### 5. Update Order

**PUT** `/sells/{id}`

Partially update an order (no stock changes).

#### Request Body
All fields are optional. Only provided fields are updated:

```json
{
  "customerName": "Jane Smith Updated",
  "status": "Processing",
  "paymentStatus": "paid",
  "fulfillmentStatus": "shipped",
  "trackingNumber": "TRACK123456",
  "carrier": "FedEx",
  "shippedAt": "2026-04-05T12:00:00Z",
  "deliveredAt": null,
  "notes": "Updated notes"
}
```

#### Request Example
```bash
curl -X PUT "http://localhost:8005/api/sells/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "Processing",
    "paymentStatus": "paid"
  }'
```

#### Response (200 OK)
Returns updated order (same format as GET order).

#### Notes
- Stock is **NOT** re-deducted on update
- Shipping address **CANNOT** be updated (immutable snapshot)
- Items **CANNOT** be updated (create a new order for different items)

---

### 6. Update Order Status

**PATCH** `/sells/{id}/status`

Update only the order status. Convenience endpoint for status changes.

#### Request Body
```json
{
  "status": "Delivered"
}
```

#### Valid Status Values
- `Pending`
- `Processing`
- `Delivered`
- `Cancelled`

#### Request Example
```bash
curl -X PATCH "http://localhost:8005/api/sells/1/status" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"status": "Delivered"}'
```

#### Response (200 OK)
Returns updated order.

#### Error Responses
- `400`: Invalid status value
- `422`: Status field missing

---

### 7. Delete Order

**DELETE** `/sells/{id}`

Soft delete an order and restore all deducted stock.

#### Request Example
```bash
curl -X DELETE "http://localhost:8005/api/sells/1" \
  -H "Authorization: Bearer {token}"
```

#### Response (200 OK)
```json
{
  "success": true,
  "message": "Sell deleted successfully",
  "data": {
    "id": 1,
    "invoiceNo": "INV-1712345678",
    ...
  }
}
```

#### Stock Restoration
When an order is deleted:
1. All item quantities are restored to source location
2. For simple products: `products.stock` incremented
3. For variant products: `variant_inventory.quantity` incremented
4. Operation is atomic (all-or-nothing)

#### Notes
- Uses soft delete (order remains in database with `deleted_at` timestamp)
- Order can be queried by ID if needed for audit trail
- Stock restoration happens automatically

#### Error Responses
- `401`: Unauthorized
- `404`: Order not found

---

### 8. Get Order Statistics

**GET** `/sells/stats`

Get aggregate statistics for all orders in the company.

#### Query Parameters
| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter stats by status |
| `start_date` | date | Orders on or after this date |
| `end_date` | date | Orders on or before this date |

#### Request Example
```bash
curl -X GET "http://localhost:8005/api/sells/stats?status=Delivered" \
  -H "Authorization: Bearer {token}"
```

#### Response (200 OK)
```json
{
  "success": true,
  "message": "Stats retrieved successfully",
  "data": {
    "totalSells": 150,
    "totalRevenue": 15000.00,
    "totalCost": 7500.00,
    "grossProfit": 7500.00,
    "averageOrderValue": 100.00,
    "averageProfitMargin": 0.50
  }
}
```

#### Calculations
- `totalRevenue`: Sum of `amount`
- `totalCost`: Sum of `total_cost` (snapshot values)
- `grossProfit`: `totalRevenue - totalCost`
- `averageOrderValue`: `totalRevenue / totalSells`
- `averageProfitMargin`: `grossProfit / totalRevenue`

---

## Response Structure

All successful responses follow this format:

```json
{
  "success": true,
  "message": "Operation description",
  "data": {...},
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 100,
      "last_page": 10
    }
  }
}
```

**Notes**:
- `meta.pagination` is included only when pagination is used
- When using `limit` parameter, pagination meta is omitted
- Single resource endpoints don't include pagination

---

## Field Reference

### Order Status
- `Pending` - Order created, awaiting confirmation
- `Processing` - Order confirmed, being prepared
- `Delivered` - Order shipped and delivered
- `Cancelled` - Order cancelled

### Payment Status
- `pending` - Payment not yet received
- `paid` - Payment received in full
- `partially_paid` - Partial payment received
- `refunded` - Payment refunded
- `failed` - Payment failed

### Fulfillment Status
- `unfulfilled` - Not yet shipped
- `processing` - Being prepared for shipment
- `shipped` - Shipped, in transit
- `delivered` - Delivered to customer
- `cancelled` - Shipment cancelled

### Payment Method
- `Cash` - Cash payment
- `Card` - Credit/debit card
- `Online` - Online payment gateway

### Address Type
- `delivery` - Delivery address
- `billing` - Billing address

---

## Rate Limiting

Currently no rate limiting is enforced. Implementation recommended for production.

---

## Pagination

### Default Pagination
```
GET /api/sells?page=1&per_page=10
```

Returns items 1-10 with pagination metadata.

### No Pagination with Limit
```
GET /api/sells?limit=5
```

Returns up to 5 items without pagination metadata.

---

## Filtering Examples

### By Status
```
GET /api/sells?status=Pending
```

### By Date Range
```
GET /api/sells?start_date=2026-04-01&end_date=2026-04-30
```

### By Customer ID
```
GET /api/sells?customer_id=5
```

### Multiple Filters
```
GET /api/sells?status=Delivered&start_date=2026-04-01&per_page=20
```

---

## Company Isolation

All requests are automatically scoped to the authenticated user's company via the JWT token's `auth_company_id` claim. Resources from other companies return 404.

---

## Timestamps

All timestamps are in ISO 8601 format with timezone:

```
2026-04-05T10:35:00Z
```

---

**Last Updated**: 2026-04-05  
**API Version**: 1.0  
**Status**: Production Ready ✅
