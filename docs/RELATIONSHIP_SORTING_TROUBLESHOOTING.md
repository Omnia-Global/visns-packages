# Relationship Sorting Troubleshooting Guide

## Common Issue: "Unknown column 'relationship.field' in 'order clause'"

### Error Example
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'clients.name' in 'order clause'
SQL: select * from `contacts` ... order by `clients.name` asc
```

### Root Cause
This error occurs when a model has a basic `scopeCustomOrder` implementation instead of using the `HasRelationshipSorting` trait.

### How to Fix

#### 1. **Identify the Model**
The error shows which table/model is affected (e.g., `contacts` table = `Contact` model).

#### 2. **Check Current Implementation**
Look for a basic `scopeCustomOrder` method like this:
```php
public function scopeCustomOrder($query, $orderBy, $order)
{
    if (isset($orderBy) && isset($order)) {
        $query->orderBy($orderBy, $order);  // ❌ This causes the error
    }
    return $query;
}
```

#### 3. **Replace with HasRelationshipSorting Trait**

**Before:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    // ... other code

    public function scopeCustomOrder($query, $orderBy, $order)
    {
        if (isset($orderBy) && isset($order)) {
            $query->orderBy($orderBy, $order);
        }
        return $query;
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_contact');
    }
}
```

**After:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class Contact extends Model
{
    use HasRelationshipSorting;

    // ... other code
    // Remove the old scopeCustomOrder method

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_contact');
    }

    // Optional: Define sortable relationship fields
    public function getSortableRelationshipFields()
    {
        return [
            'clients.name',
            'clients.email',
            'clients.created_at',
            'site.name',
            // Add other sortable relationship fields
        ];
    }
}
```

### What the Trait Does

The `HasRelationshipSorting` trait:

1. **Detects relationship sorting** when `orderBy` contains dots (e.g., `clients.name`)
2. **Validates relationships exist** by checking the relationship method
3. **Creates proper subqueries** instead of direct column references
4. **Handles different relationship types** (belongsTo, hasOne, hasMany, belongsToMany)

### Example SQL Generated

**Before (broken):**
```sql
SELECT * FROM contacts ORDER BY clients.name ASC  -- ❌ No join
```

**After (working):**
```sql
SELECT * FROM contacts 
ORDER BY (
    SELECT clients.name 
    FROM clients 
    INNER JOIN client_contact ON clients.id = client_contact.client_id 
    WHERE client_contact.contact_id = contacts.id 
    ORDER BY clients.name ASC 
    LIMIT 1
) ASC  -- ✅ Proper subquery
```

### For Different Relationship Types

#### BelongsTo Relationship
```php
// User belongsTo Company
public function company()
{
    return $this->belongsTo(Company::class);
}

// Sorting by 'company.name' generates:
// ORDER BY (SELECT companies.name FROM companies WHERE companies.id = users.company_id LIMIT 1)
```

#### HasMany Relationship  
```php
// User hasMany Posts
public function posts()
{
    return $this->hasMany(Post::class);
}

// Sorting by 'posts.title' generates:
// ORDER BY (SELECT posts.title FROM posts WHERE posts.user_id = users.id ORDER BY posts.title ASC LIMIT 1)
```

#### BelongsToMany Relationship
```php
// Contact belongsToMany Clients
public function clients()
{
    return $this->belongsToMany(Client::class, 'client_contact');
}

// Sorting by 'clients.name' generates:
// ORDER BY (SELECT clients.name FROM clients INNER JOIN client_contact ON clients.id = client_contact.client_id WHERE client_contact.contact_id = contacts.id ORDER BY clients.name ASC LIMIT 1)
```

### Testing the Fix

1. **Apply the trait** to your model
2. **Remove the old scopeCustomOrder method**
3. **Test relationship sorting** in your frontend DataGrid
4. **Check the generated SQL** in Laravel logs to confirm subqueries are used

### Additional Configuration

#### Restrict Sortable Fields (Optional)
```php
public function getSortableRelationshipFields()
{
    return [
        'clients.name',
        'clients.email',
        'company.name',
        // Only these fields will be sortable
    ];
}
```

#### Enable Debug Logging (Development)
Add to your DataGrid config:
```javascript
const config = {
    intelligentSorting: {
        logAnalysis: true  // Shows sorting decisions in console
    }
};
```

### Performance Considerations

- **Index foreign keys** and sort columns for optimal performance
- **Consider caching** for frequently sorted relationship data
- **Limit relationship depth** to 3 levels maximum
- **Use `LIMIT 1`** for hasMany relationships (sorts by first related record)

### Common Pitfalls

1. **Forgetting to remove old scopeCustomOrder** - causes conflicts
2. **Missing relationship methods** - trait falls back to basic sorting
3. **Wrong relationship types** - ensure relationship methods return correct types
4. **Complex pivot relationships** - may need custom relationship definitions