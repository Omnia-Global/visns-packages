<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class VirtualColumnSortingTest extends TestCase
{

    /** @test */
    public function it_detects_virtual_columns_from_appends_array()
    {
        $model = new TestModelWithVirtualColumns();
        
        $this->assertTrue($model->isVirtualColumnPublic('customer_names'));
        $this->assertTrue($model->isVirtualColumnPublic('full_name'));
        $this->assertFalse($model->isVirtualColumnPublic('name'));
    }

    /** @test */
    public function it_detects_virtual_columns_from_accessor_methods()
    {
        $model = new TestModelWithVirtualColumns();
        
        $this->assertTrue($model->isVirtualColumnPublic('display_name'));
        $this->assertFalse($model->isVirtualColumnPublic('nonexistent_field'));
    }

    /** @test */
    public function it_handles_virtual_column_sorting_basic()
    {
        $model = new TestModelWithVirtualColumns();
        
        // Test basic virtual column detection
        $this->assertTrue($model->isVirtualColumnPublic('customer_names'));
        $this->assertTrue($model->isVirtualColumnPublic('display_name'));
        $this->assertFalse($model->isVirtualColumnPublic('name'));
    }
}

class TestModelWithVirtualColumns extends Model
{
    use HasRelationshipSorting;

    protected $table = 'test_contacts';
    
    protected $fillable = ['name', 'email'];
    
    protected $appends = ['customer_names', 'full_name'];

    public function getCustomerNamesAttribute()
    {
        return 'Test Customer Names';
    }

    public function getFullNameAttribute()
    {
        return $this->name . ' (Full)';
    }

    public function getDisplayNameAttribute()
    {
        return $this->name . ' - Display';
    }

    public function getVirtualFieldNoAltAttribute()
    {
        return 'Virtual field with no alternative';
    }

    public function getVirtualColumnAlternatives()
    {
        return [
            'customer_names' => 'name',
            'full_name' => 'name',
            'display_name' => 'email',
        ];
    }

    // Helper method to test protected method
    public function isVirtualColumnPublic($field)
    {
        return $this->isVirtualColumn($field);
    }
}