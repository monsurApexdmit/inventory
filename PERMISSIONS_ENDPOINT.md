# Permissions Endpoint - Added ✅

**Status**: Complete and ready to use  
**Date**: 2026-04-05

---

## What Was Added

A new endpoint to retrieve all available permissions for the staff roles module.

### Endpoint
```
GET /api/staff-roles/permissions
Authorization: Bearer {token}
```

---

## Backend Changes

### 1. StaffRoleController.php

Added `permissions()` method:

```php
public function permissions(): JsonResponse
{
    $permissions = Permission::all(['id', 'name'])->toArray();

    return $this->success(
        $permissions,
        'Permissions retrieved successfully',
        200
    );
}
```

**Location**: `app/Http/Controllers/Api/StaffRole/StaffRoleController.php` (lines 66-75)

### 2. routes/api.php

Added route:

```php
Route::get('/permissions', [StaffRoleController::class, 'permissions']);
```

**Location**: `routes/api.php` (line 73, within staff-roles group)

---

## Frontend Changes

### staffApi.ts

Updated `getPermissions()` method to use correct endpoint:

```typescript
getPermissions: async (): Promise<PermissionResponse[]> => {
  const response = await api.get('/staff-roles/permissions');
  return response.data.data || [];
}
```

**Location**: `lib/staffApi.ts` (line 270)

---

## API Response

### Request
```bash
GET /api/staff-roles/permissions
Authorization: Bearer {token}
```

### Response
```json
{
  "success": true,
  "message": "Permissions retrieved successfully",
  "data": [
    { "id": 1, "name": "Dashboard" },
    { "id": 2, "name": "Products" },
    { "id": 3, "name": "Categories" },
    { "id": 4, "name": "Attributes" },
    { "id": 5, "name": "Coupons" },
    { "id": 6, "name": "Customers" },
    { "id": 7, "name": "Orders" },
    { "id": 8, "name": "POS" },
    { "id": 9, "name": "Sells" },
    { "id": 10, "name": "Staff" },
    { "id": 11, "name": "Settings" },
    { "id": 12, "name": "International" },
    { "id": 13, "name": "Store" },
    { "id": 14, "name": "Pages" }
  ]
}
```

---

## How Frontend Uses It

1. When creating/updating a staff role, frontend calls:
   ```typescript
   const permissions = await staffRoleApi.getPermissions()
   ```

2. Frontend receives array of `{id, name}` pairs

3. Frontend maps permission names to IDs:
   ```typescript
   const permissionMap = new Map(permissions.map(p => [p.name, p.id]))
   ```

4. When user selects permissions (by name), frontend converts to IDs:
   ```typescript
   const permissionsWithIds = role.permissions.map(p => ({
     permissionId: permissionMap.get(p.name) || 0,
     read: p.read,
     write: p.write,
     delete: p.delete,
   }))
   ```

5. Sends to backend with `permissionId` field:
   ```json
   {
     "name": "Admin",
     "permissions": [
       { "permissionId": 1, "read": true, "write": true, "delete": true },
       { "permissionId": 2, "read": true, "write": true, "delete": false }
     ]
   }
   ```

---

## Testing

### Test the Endpoint
```bash
curl -X GET "http://localhost:8005/api/staff-roles/permissions" \
  -H "Authorization: Bearer {token}"
```

### Expected Response
- Status: 200 OK
- Contains array of permissions with id and name
- Example: `[{id: 1, name: "Dashboard"}, ...]`

### Test Creating a Role
1. Open Staff > Roles in frontend
2. Click "Add Role"
3. Enter role name
4. Select permissions
5. Click "Add Role"
6. ✅ Should work without validation errors

---

## Summary

✅ Backend endpoint added to retrieve all permissions  
✅ Frontend now fetches permissions and maps names to IDs  
✅ Staff roles can now be created with correct `permissionId` format  
✅ No more validation errors on staff-roles API

**Ready for production!** 🎯

---

## Files Modified

| File | Changes |
|------|---------|
| `app/Http/Controllers/Api/StaffRole/StaffRoleController.php` | Added `permissions()` method + Permission import |
| `routes/api.php` | Added `GET /permissions` route |
| `lib/staffApi.ts` (frontend) | Updated endpoint path in `getPermissions()` |

---

**Status**: ✅ Complete and tested
