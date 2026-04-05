# Staff Roles API - Request Format Guide

**Issue**: `permissions.X.permissionId` is required but missing

---

## The Problem

Your request is sending permissions array without the `permissionId` field:

```json
❌ WRONG
{
  "permissions": [
    { "read": true, "write": false },  // Missing permissionId!
    { "read": true, "write": true }    // Missing permissionId!
  ]
}
```

---

## The Solution

Each permission object MUST include `permissionId`:

```json
✅ CORRECT
{
  "name": "Editor",
  "permissions": [
    { "permissionId": 1, "read": true, "write": true, "delete": false },
    { "permissionId": 2, "read": true, "write": true, "delete": false },
    { "permissionId": 3, "read": true, "write": false, "delete": false }
  ]
}
```

---

## Validation Rules

From `CreateStaffRoleRequest.php`:

| Field | Rule | Description |
|-------|------|-------------|
| `name` | `required\|string\|max:255` | Role name is required |
| `permissions` | `nullable\|array` | Permissions array is optional |
| `permissions.*.permissionId` | `required_with:permissions\|integer\|exists:permissions,id` | **Required if permissions array is present** |
| `permissions.*.read` | `nullable\|boolean` | Read permission (true/false) |
| `permissions.*.write` | `nullable\|boolean` | Write permission (true/false) |
| `permissions.*.delete` | `nullable\|boolean` | Delete permission (true/false) |

### Key Points:
- `permissionId` is **REQUIRED** when `permissions` array is present
- `permissionId` must be an **integer**
- `permissionId` must **exist** in the `permissions` table
- Read/write/delete are **optional** but must be boolean

---

## Complete Valid Request

### Create Staff Role

```json
POST /api/staff-roles
Content-Type: application/json
Authorization: Bearer {token}

{
  "name": "Admin",
  "permissions": [
    {
      "permissionId": 1,
      "read": true,
      "write": true,
      "delete": true
    },
    {
      "permissionId": 2,
      "read": true,
      "write": true,
      "delete": true
    },
    {
      "permissionId": 3,
      "read": true,
      "write": true,
      "delete": false
    }
  ]
}
```

### Create Staff Role Without Permissions

```json
POST /api/staff-roles
Content-Type: application/json
Authorization: Bearer {token}

{
  "name": "Viewer"
  // permissions array is optional, can be omitted entirely
}
```

---

## Frontend Example

### What You're Probably Sending

```javascript
// ❌ WRONG - Missing permissionId
const payload = {
  name: "Editor",
  permissions: [
    { read: true, write: true, delete: false },
    { read: true, write: false, delete: false }
  ]
};

// ❌ WRONG - Array without permissionId field
const payload = {
  name: "Editor",
  permissions: [
    { id: 1, read: true, write: true },
    { id: 2, read: true, write: false }
  ]
};
```

### What You Should Send

```javascript
// ✅ CORRECT - Include permissionId
const payload = {
  name: "Editor",
  permissions: [
    { permissionId: 1, read: true, write: true, delete: false },
    { permissionId: 2, read: true, write: false, delete: false }
  ]
};

// Get permissions from API first
const permissions = await fetch('/api/permissions').then(r => r.json());

const payload = {
  name: "Custom Role",
  permissions: permissions.data.map(p => ({
    permissionId: p.id,
    read: true,
    write: p.type === 'admin',
    delete: p.type === 'admin'
  }))
};
```

---

## Step-by-Step Fix

### Step 1: Get Available Permissions

```bash
curl -X GET "http://localhost:8005/api/permissions" \
  -H "Authorization: Bearer {token}"
```

Response:
```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Dashboard", "description": "View dashboard" },
    { "id": 2, "name": "Products", "description": "Manage products" },
    { "id": 3, "name": "Orders", "description": "Manage orders" },
    ...
  ]
}
```

### Step 2: Create Role with Permissions

```bash
curl -X POST "http://localhost:8005/api/staff-roles" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Editor",
    "permissions": [
      { "permissionId": 1, "read": true, "write": true, "delete": false },
      { "permissionId": 2, "read": true, "write": true, "delete": false },
      { "permissionId": 3, "read": true, "write": false, "delete": false }
    ]
  }'
```

Response:
```json
{
  "success": true,
  "message": "Staff role created successfully",
  "data": {
    "id": 5,
    "name": "Editor",
    "permissions": [
      { "permissionId": 1, "read": true, "write": true, "delete": false },
      { "permissionId": 2, "read": true, "write": true, "delete": false },
      { "permissionId": 3, "read": true, "write": false, "delete": false }
    ],
    "createdAt": "2026-04-05T10:00:00Z"
  }
}
```

---

## Error Details

When you send permissions without `permissionId`, you get:

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "permissions.0.permissionId": ["The permissions.0.permissionId field is required when permissions is present."],
    "permissions.1.permissionId": ["The permissions.1.permissionId field is required when permissions is present."],
    ...
  }
}
```

This means:
- `permissions[0]` is missing `permissionId`
- `permissions[1]` is missing `permissionId`
- etc.

---

## Quick Reference

### Request Structure

```
{
  "name": string (required, max 255 chars),
  "permissions": [
    {
      "permissionId": integer (required, must exist in permissions table),
      "read": boolean (optional),
      "write": boolean (optional),
      "delete": boolean (optional)
    },
    ...
  ] (optional array)
}
```

### Field Names Matter

- Use `permissionId` ✅ (not `id` or `permission_id`)
- Use `read`, `write`, `delete` ✅ (lowercase)
- Both are **case-sensitive**

---

## Common Mistakes

### ❌ Wrong

```json
{
  "permissions": [
    { "id": 1 }              // id → should be permissionId
  ]
}
```

```json
{
  "permissions": [
    { "permissionid": 1 }    // permissionid → should be permissionId (camelCase)
  ]
}
```

```json
{
  "permissions": [
    { "permission_id": 1 }   // permission_id → should be permissionId (snake_case)
  ]
}
```

### ✅ Correct

```json
{
  "permissions": [
    { "permissionId": 1, "read": true, "write": true, "delete": false }
  ]
}
```

---

## Summary

**The Fix**: Add `permissionId` to each permission object

```diff
{
  "name": "Editor",
  "permissions": [
    {
+     "permissionId": 1,
      "read": true,
      "write": true
    }
  ]
}
```

That's it! The validation will pass once `permissionId` is included for each permission.
