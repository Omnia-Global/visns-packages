<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Job;
use App\Models\JobGood;
use App\Models\Priority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStageRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a priority for job goods
        Priority::factory()->create(['id' => 1]);
    }

    /** @test */
    public function customer_can_access_jobs_relationship()
    {
        $customer = Customer::factory()->create();
        $job = Job::factory()->create(['customer_id' => $customer->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $customer->jobs);
        $this->assertEquals(1, $customer->jobs->count());
        $this->assertEquals($job->id, $customer->jobs->first()->id);
    }

    /** @test */
    public function customer_can_access_job_goods_through_jobs()
    {
        $customer = Customer::factory()->create();
        $job = Job::factory()->create(['customer_id' => $customer->id]);
        $jobGood = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $customer->jobGoods);
        $this->assertEquals(1, $customer->jobGoods->count());
        $this->assertEquals($jobGood->id, $customer->jobGoods->first()->id);
    }

    /** @test */
    public function customer_can_filter_job_goods_by_receivables_stage()
    {
        $customer = Customer::factory()->create();
        $job = Job::factory()->create(['customer_id' => $customer->id]);
        
        // Create job goods at different stages
        $pendingReceivables = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'receivables_status' => 0
        ]);
        
        $completedReceivables = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'receivables_status' => 1
        ]);

        // Test pending receivables
        $pendingJobGoods = $customer->jobGoodsAtReceivablesStage;
        $this->assertEquals(1, $pendingJobGoods->count());
        $this->assertEquals($pendingReceivables->id, $pendingJobGoods->first()->id);

        // Test completed receivables
        $completedJobGoods = $customer->jobGoodsWithCompletedReceivables;
        $this->assertEquals(1, $completedJobGoods->count());
        $this->assertEquals($completedReceivables->id, $completedJobGoods->first()->id);
    }

    /** @test */
    public function customer_can_filter_job_goods_by_pickling_stage()
    {
        $customer = Customer::factory()->create();
        $job = Job::factory()->create(['customer_id' => $customer->id]);
        
        $pendingPickling = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'pickling_status' => 0
        ]);
        
        $completedPickling = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'pickling_status' => 1
        ]);

        $this->assertEquals(1, $customer->jobGoodsAtPicklingStage->count());
        $this->assertEquals(1, $customer->jobGoodsWithCompletedPickling->count());
        $this->assertEquals($pendingPickling->id, $customer->jobGoodsAtPicklingStage->first()->id);
        $this->assertEquals($completedPickling->id, $customer->jobGoodsWithCompletedPickling->first()->id);
    }

    /** @test */
    public function customer_can_filter_job_goods_by_galvanizing_stage()
    {
        $customer = Customer::factory()->create();
        $job = Job::factory()->create(['customer_id' => $customer->id]);
        
        $pendingGalvanizing = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'galvanizing_status' => 0
        ]);
        
        $completedGalvanizing = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'galvanizing_status' => 1
        ]);

        $this->assertEquals(1, $customer->jobGoodsAtGalvanizingStage->count());
        $this->assertEquals(1, $customer->jobGoodsWithCompletedGalvanizing->count());
    }

    /** @test */
    public function customer_can_filter_job_goods_by_despatch_stage()
    {
        $customer = Customer::factory()->create();
        $job = Job::factory()->create(['customer_id' => $customer->id]);
        
        $pendingDespatch = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'despatch_status' => 0
        ]);
        
        $completedDespatch = JobGood::factory()->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'despatch_status' => 1
        ]);

        $this->assertEquals(1, $customer->jobGoodsAtDespatchStage->count());
        $this->assertEquals(1, $customer->jobGoodsWithCompletedDespatch->count());
    }

    /** @test */
    public function whereHas_works_with_stage_relationships()
    {
        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        
        $job1 = Job::factory()->create(['customer_id' => $customer1->id]);
        $job2 = Job::factory()->create(['customer_id' => $customer2->id]);
        
        // Customer 1 has job goods at pickling stage
        JobGood::factory()->create([
            'job_id' => $job1->id,
            'priority_id' => 1,
            'pickling_status' => 0
        ]);
        
        // Customer 2 has completed pickling
        JobGood::factory()->create([
            'job_id' => $job2->id,
            'priority_id' => 1,
            'pickling_status' => 1
        ]);

        // Test whereHas with pending pickling
        $customersAtPickling = Customer::whereHas('jobGoodsAtPicklingStage')->get();
        $this->assertEquals(1, $customersAtPickling->count());
        $this->assertEquals($customer1->id, $customersAtPickling->first()->id);

        // Test whereHas with completed pickling
        $customersWithCompletedPickling = Customer::whereHas('jobGoodsWithCompletedPickling')->get();
        $this->assertEquals(1, $customersWithCompletedPickling->count());
        $this->assertEquals($customer2->id, $customersWithCompletedPickling->first()->id);
    }

    /** @test */
    public function withCount_works_with_stage_relationships()
    {
        $customer = Customer::factory()->create();
        $job = Job::factory()->create(['customer_id' => $customer->id]);
        
        // Create multiple job goods at different stages
        JobGood::factory()->count(3)->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'pickling_status' => 0
        ]);
        
        JobGood::factory()->count(2)->create([
            'job_id' => $job->id,
            'priority_id' => 1,
            'galvanizing_status' => 0
        ]);

        $customerWithCounts = Customer::withCount([
            'jobGoodsAtPicklingStage',
            'jobGoodsAtGalvanizingStage'
        ])->find($customer->id);

        $this->assertEquals(3, $customerWithCounts->job_goods_at_pickling_stage_count);
        $this->assertEquals(2, $customerWithCounts->job_goods_at_galvanizing_stage_count);
    }
}
