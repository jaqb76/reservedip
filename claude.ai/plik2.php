<?php

// app/Jobs/ScanSubnetJob.php
namespace App\Jobs;

use App\Models\Subnet;
use App\Models\ScanJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanSubnetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $subnet;
    public $tries = 3;
    public $backoff = 60;

    public function __construct(Subnet $subnet)
    {
        $this->subnet = $subnet;
    }

    public function handle()
    {
        $scanJob = ScanJob::create([
            'subnet_id' => $this->subnet->id,
            'status' => 'in_progress',
            'progress' => 0,
        ]);

        $network = $this->subnet->network;
        $mask = $this->subnet->mask;

        $ips = $this->getIpsFromSubnet($network, $mask);
        $results = [];
        $totalIps = count($ips);

        foreach ($ips as $index => $ip) {
            try {
                exec("ping -c 1 -W 1 " . escapeshellarg($ip), $output, $result);
                $results[$ip] = ($result == 0);

                // Update progress
                $progress = round(($index + 1) / $totalIps * 100);
                $scanJob->update(['progress' => $progress]);
            } catch (\Exception $e) {
                Log::error("Error pinging IP {$ip}: " . $e->getMessage());
                // Continue with next IP
            }
        }

        // Store results in database
        $scanJob->update([
            'status' => 'completed',
            'results' => json_encode($results),
            'progress' => 100,
        ]);
    }

    public function failed(\Throwable $exception)
    {
        Log::error("ScanSubnetJob failed for subnet {$this->subnet->id}: " . $exception->getMessage());
        
        ScanJob::updateOrCreate(
            ['subnet_id' => $this->subnet->id],
            ['status' => 'failed', 'error_message' => $exception->getMessage()]
        );
    }

    private function getIpsFromSubnet($network, $mask)
    {
        // ... (implementation remains the same)
    }
}

// app/Models/ScanJob.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanJob extends Model
{
    protected $fillable = ['subnet_id', 'status', 'progress', 'results', 'error_message'];

    public function subnet()
    {
        return $this->belongsTo(Subnet::class);
    }
}

// app/Http/Controllers/IpManagementController.php
namespace App\Http\Controllers;

use App\Jobs\ScanSubnetJob;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Models\ScanJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IpManagementController extends Controller
{
    // ... (previous methods remain the same)

    public function scanSubnet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subnet_id' => 'required|exists:subnets,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $subnet = Subnet::findOrFail($request->subnet_id);

        // Check if there's already a scan in progress
        $existingJob = ScanJob::where('subnet_id', $subnet->id)
                              ->whereIn('status', ['in_progress', 'queued'])
                              ->first();

        if ($existingJob) {
            return response()->json(['message' => 'A scan is already in progress for this subnet'], 409);
        }

        // Create a new scan job record
        $scanJob = ScanJob::create([
            'subnet_id' => $subnet->id,
            'status' => 'queued',
            'progress' => 0,
        ]);

        // Dispatch the job
        ScanSubnetJob::dispatch($subnet)->onQueue('subnet-scans');

        return response()->json(['message' => 'Scan queued successfully', 'scan_job_id' => $scanJob->id]);
    }

    public function getScanStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scan_job_id' => 'required|exists:scan_jobs,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $scanJob = ScanJob::findOrFail($request->scan_job_id);

        return response()->json([
            'status' => $scanJob->status,
            'progress' => $scanJob->progress,
            'error_message' => $scanJob->error_message,
        ]);
    }

    public function getScanResults(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scan_job_id' => 'required|exists:scan_jobs,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $scanJob = ScanJob::findOrFail($request->scan_job_id);

        if ($scanJob->status !== 'completed') {
            return response()->json(['message' => 'Scan results not available yet'], 404);
        }

        return response()->json(json_decode($scanJob->results, true));
    }

    // ... (other methods remain the same)
}

// database/migrations/xxxx_xx_xx_create_scan_jobs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScanJobsTable extends Migration
{
    public function up()
    {
        Schema::create('scan_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subnet_id')->constrained();
            $table->enum('status', ['queued', 'in_progress', 'completed', 'failed']);
            $table->integer('progress')->default(0);
            $table->json('results')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('scan_jobs');
    }
}

// resources/js/components/ScanProgressMonitor.vue
<template>
  <div>
    <h2>Scan Progress</h2>
    <div v-if="scanJob">
      <p>Status: {{ scanJob.status }}</p>
      <div v-if="scanJob.status === 'in_progress'">
        <progress :value="scanJob.progress" max="100"></progress>
        <p>{{ scanJob.progress }}% complete</p>
      </div>
      <p v-if="scanJob.error_message" class="error">Error: {{ scanJob.error_message }}</p>
    </div>
    <button @click="refreshStatus" :disabled="isRefreshing">Refresh Status</button>
  </div>
</template>

<script>
export default {
  props: ['scanJobId'],
  data() {
    return {
      scanJob: null,
      isRefreshing: false
    }
  },
  mounted() {
    this.refreshStatus();
  },
  methods: {
    async refreshStatus() {
      if (this.isRefreshing) return;
      
      this.isRefreshing = true;
      try {
        const response = await fetch(`/api/scan-status?scan_job_id=${this.scanJobId}`);
        if (!response.ok) throw new Error('Failed to fetch scan status');
        this.scanJob = await response.json();
        
        if (this.scanJob.status === 'in_progress') {
          setTimeout(() => this.refreshStatus(), 5000); // Refresh every 5 seconds if in progress
        }
      } catch (error) {
        console.error('Error fetching scan status:', error);
      } finally {
        this.isRefreshing = false;
      }
    }
  }
}
</script>

<style scoped>
.error {
  color: red;
}
</style>

// routes/api.php
use App\Http\Controllers\IpManagementController;

// ... (previous routes remain the same)
Route::get('/scan-status', [IpManagementController::class, 'getScanStatus']);
Route::get('/scan-results', [IpManagementController::class, 'getScanResults']);