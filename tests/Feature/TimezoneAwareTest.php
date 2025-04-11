<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Carbon\Carbon;
use Visnsstudio\VisnsPackages\Examples\Models\ExampleTimezoneModel;

class TimezoneAwareTest extends TestCase
{
    /**
     * Test that dates are preserved in the application timezone when serialized to JSON.
     *
     * @return void
     */
    public function test_timezone_preservation_in_json()
    {
        // Create a model instance
        $model = new ExampleTimezoneModel();
        
        // Set a UTC date
        $utcDate = '2025-04-10T16:00:00.000Z';
        $model->due_date = $utcDate;
        
        // Convert to array (which triggers serialization)
        $array = $model->toArray();
        
        // Get the application timezone
        $appTimezone = config('app.timezone');
        
        // Output debug information
        echo "Original UTC date: {$utcDate}\n";
        echo "Serialized date: {$array['due_date']}\n";
        echo "Application timezone: {$appTimezone}\n";
        
        // Parse the serialized date to verify it's in the correct timezone
        $parsedDate = Carbon::parse($array['due_date']);
        echo "Parsed date timezone: {$parsedDate->timezone->getName()}\n";
        
        // If Australia/Perth (GMT+8), the time should be midnight on April 11
        if ($appTimezone === 'Australia/Perth') {
            $this->assertEquals('2025-04-11', $parsedDate->format('Y-m-d'));
            $this->assertEquals('00:00:00', $parsedDate->format('H:i:s'));
        }
        
        // The timezone information should be preserved
        $this->assertStringContainsString($appTimezone === 'UTC' ? 'Z' : '+', $array['due_date']);
    }
}
