# Relationship Sorting with HasRelationshipSorting Trait

The `HasRelationshipSorting` trait provides enhanced sorting capabilities for Laravel models, including support for sorting by relationship fields using efficient subqueries.

## Features

- **Regular Column Sorting**: Sort by any column on the model
- **Relationship Sorting**: Sort by fields on related models using dot notation
- **Nested Relationships**: Support for multi-level relationships (e.g., `user.profile.company.name`)
- **Multiple Relationship Types**: Works with `hasOne`, `belongsTo`, `hasMany`, `belongsToMany`
- **Graceful Fallbacks**: Handles invalid relationships and columns gracefully
- **Performance Optimized**: Uses efficient subqueries instead of joins to preserve eager loading

## Installation

### 1. Add the Trait to Your Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class User extends Model
{
    use HasRelationshipSorting;
    
    // Your existing model code...
}
```

### 2. Remove Existing scopeCustomOrder Methods

If your model already has a `scopeCustomOrder` method, remove it since the trait provides an enhanced version.

### 3. Optional: Define Sortable Fields

You can optionally override the `getSortableRelationshipFields()` method to restrict which relationship fields can be sorted:

```php
public function getSortableRelationshipFields()
{
    return [
        'profile.name',
        'profile.title',
        'company.name',
        'roles.name',
        'posts.title',
        'posts.created_at',
    ];
}
```

If this method returns an empty array or is not defined, all relationship fields are sortable.

## Usage

### Basic Column Sorting

```php
// Sort by regular model columns
$users = User::customOrder('name', 'asc')->get();
$users = User::customOrder('created_at', 'desc')->get();
```

### Relationship Sorting

```php
// Sort by related model fields
$users = User::customOrder('profile.name', 'asc')->get();
$users = User::customOrder('company.name', 'desc')->get();
$users = User::customOrder('roles.name', 'asc')->get();
```

### Nested Relationship Sorting

```php
// Sort by deeply nested relationships
$users = User::customOrder('profile.company.name', 'asc')->get();
$users = User::customOrder('roles.permissions.name', 'desc')->get();
```

### With DynamicController

The trait works seamlessly with the `DynamicController` for API endpoints:

```javascript
// Frontend table request
fetch('/ajax/users/table?sortBy=profile.name&sort=asc')
fetch('/ajax/users/table?sortBy=company.name&sort=desc')
```

### Error Handling

The trait handles various error conditions gracefully:

```php
// Invalid relationship - falls back to regular sorting
User::customOrder('nonexistent.field', 'asc')->get();

// Missing parameters - returns unsorted query
User::customOrder(null, null)->get();

// Malformed field names - handles gracefully
User::customOrder('valid_relation.', 'asc')->get();
```

## How It Works

### Subquery Approach

Instead of using joins (which can interfere with eager loading), the trait uses efficient subqueries:

```sql
-- Example generated SQL for User::customOrder('profile.name', 'asc')
SELECT * FROM users 
ORDER BY (
    SELECT profiles.name 
    FROM profiles 
    WHERE profiles.user_id = users.id 
    LIMIT 1
) ASC
```

### Relationship Type Handling

- **Single Relations** (`hasOne`, `belongsTo`): Uses direct subquery
- **Multiple Relations** (`hasMany`, `belongsToMany`): Orders subquery to get consistent "first" result

### Preserving Eager Loading

The approach preserves your existing eager loading configuration:

```php
// Eager loading still works normally
$users = User::with(['profile', 'posts'])
    ->customOrder('profile.name', 'asc')
    ->get();
```

## Examples by Relationship Type

### HasOne / BelongsTo

```php
// User hasOne Profile
$users = User::customOrder('profile.title', 'asc')->get();

// Post belongsTo User  
$posts = Post::customOrder('user.name', 'desc')->get();
```

### HasMany

```php
// User hasMany Posts (sorts by first post's title)
$users = User::customOrder('posts.title', 'asc')->get();
```

### BelongsToMany

```php
// User belongsToMany Roles (sorts by first role's name)
$users = User::customOrder('roles.name', 'asc')->get();
```

### Polymorphic Relations

```php
// File morphTo fileable
$files = File::customOrder('fileable.name', 'asc')->get();
```

## Performance Considerations

- **Efficient Subqueries**: No joins mean no duplicate rows or Cartesian products
- **Index Optimization**: Ensure foreign keys and sort columns are indexed
- **Limit Usage**: For `hasMany` relationships, only the first related record affects sorting
- **Caching**: Consider query caching for frequently sorted relationship data

## Migration from Basic Sorting

### Before (Basic scopeCustomOrder)

```php
public function scopeCustomOrder($query, $orderBy, $order)
{
    if (isset($orderBy) && isset($order)) {
        $query->orderBy($orderBy, $order);
    }
    return $query;
}
```

### After (Enhanced with Trait)

```php
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class User extends Model 
{
    use HasRelationshipSorting;
    
    // Remove old scopeCustomOrder method
    // Add optional getSortableRelationshipFields method
}
```

## Troubleshooting

### Common Issues

1. **"Relationship doesn't exist"**: Ensure the relationship method exists on your model
2. **"Column not found"**: Verify the column exists on the related model's table
3. **Unexpected sorting**: Check for typos in relationship or column names
4. **Performance issues**: Ensure foreign keys and sort columns are indexed

### Debug Mode

Enable query logging to see generated SQL:

```php
\DB::enableQueryLog();
$users = User::customOrder('profile.name', 'asc')->get();
dd(\DB::getQueryLog());
```

## Contributing

To extend the trait for additional relationship types or add new features, modify the `HasRelationshipSorting` trait in `src/Traits/HasRelationshipSorting.php`.