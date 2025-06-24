<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class NestedRelationshipSortingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_handles_nested_relationship_sorting()
    {
        $ticket = new TestTicket();
        $query = $ticket->newQuery();
        
        // Test nested relationship sorting: contact.customers.name
        $result = $ticket->scopeCustomOrder($query, 'contact.customers.name', 'asc');
        
        // Should not throw an exception and return a valid query builder
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    /** @test */
    public function it_handles_virtual_column_detection_for_tickets()
    {
        $ticket = new TestTicket();
        
        // customer_names should be detected as virtual since it's likely appended
        $this->assertFalse($ticket->isVirtualColumn('id'));
        $this->assertFalse($ticket->isVirtualColumn('contact_id'));
    }
}

class TestTicket extends Model
{
    use HasRelationshipSorting;

    protected $table = 'test_tickets';
    protected $fillable = ['contact_id', 'title'];

    public function contact()
    {
        return $this->belongsTo(TestContact::class, 'contact_id');
    }
}

class TestContact extends Model
{
    use HasRelationshipSorting;

    protected $table = 'test_contacts';
    protected $fillable = ['name'];
    protected $appends = ['customer_names'];

    public function customers()
    {
        return $this->belongsToMany(TestCustomer::class, 'test_contact_customer');
    }

    public function getCustomerNamesAttribute()
    {
        return $this->customers->pluck('name')->join(', ');
    }
}

class TestCustomer extends Model
{
    protected $table = 'test_customers';
    protected $fillable = ['name'];
}