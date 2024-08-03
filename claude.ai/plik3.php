<?php

// app/Jobs/ScanSubnetJob.php
namespace App\Jobs;

use App\Models\Subnet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanSubnetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $subnet;

    public function __construct(Subnet $subnet)
    {
        $this->subnet = $subnet;
    }

    public function handle()
    {
        $network = $this->subnet->network;
        $mask = $this->subnet->mask;

        $ips = $this->getIpsFromSubnet($network, $mask);
        $results = [];

        foreach ($ips as $ip) {
            exec("ping -c 1 -W 1 " . escapeshellarg($ip), $output, $result);
            $results[$ip] = ($result == 0);
        }

        // Store results in database or cache
        \Cache::put('scan_results_' . $this->subnet->id, $results, now()->addHours(1));
    }

    private function getIpsFromSubnet($network, $mask)
    {
        $networkLong = ip2long($network);
        $maskLong = ip2long($mask);
        $broadcastLong = $networkLong | (~$maskLong);

        $ips = [];
        for ($i = $networkLong + 1; $i < $broadcastLong; $i++) {
            $ips[] = long2ip($i);
        }

        return $ips;
    }
}

// app/Http/Controllers/IpManagementController.php
namespace App\Http\Controllers;

use App\Jobs\ScanSubnetJob;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\Vlan;
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

        // Dispatch the job
        ScanSubnetJob::dispatch($subnet);

        return response()->json(['message' => 'Scan queued successfully']);
    }

    public function getScanResults(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subnet_id' => 'required|exists:subnets,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $results = \Cache::get('scan_results_' . $request->subnet_id);

        if (!$results) {
            return response()->json(['message' => 'Scan results not available'], 404);
        }

        return response()->json($results);
    }

    public function getVlanOccupancy()
    {
        $vlans = Vlan::with('subnets.ipAddresses')->get();

        $occupancyData = $vlans->map(function ($vlan) {
            $totalIps = $vlan->subnets->sum(function ($subnet) {
                return $this->getIpCount($subnet->network, $subnet->mask);
            });

            $reservedIps = $vlan->subnets->flatMap->ipAddresses->where('is_reserved', true)->count();

            return [
                'vlan_id' => $vlan->vlan_id,
                'name' => $vlan->name,
                'total_ips' => $totalIps,
                'reserved_ips' => $reservedIps,
                'occupancy_rate' => $totalIps > 0 ? round(($reservedIps / $totalIps) * 100, 2) : 0
            ];
        });

        return response()->json($occupancyData);
    }

    private function getIpCount($network, $mask)
    {
        $networkLong = ip2long($network);
        $maskLong = ip2long($mask);
        return (~$maskLong) - 1;  // Subtract 1 to exclude network and broadcast addresses
    }
}

// routes/api.php
use App\Http\Controllers\IpManagementController;

// ... (previous routes remain the same)
Route::get('/scan-results', [IpManagementController::class, 'getScanResults']);
Route::get('/vlan-occupancy', [IpManagementController::class, 'getVlanOccupancy']);

// resources/js/components/VlanOccupancyChart.vue
<template>
  <div>
    <canvas ref="chart"></canvas>
  </div>
</template>

<script>
import Chart from 'chart.js/auto';

export default {
  data() {
    return {
      chart: null
    }
  },
  mounted() {
    this.fetchData();
  },
  methods: {
    async fetchData() {
      try {
        const response = await fetch('/api/vlan-occupancy');
        const data = await response.json();
        this.createChart(data);
      } catch (error) {
        console.error('Error fetching VLAN occupancy data:', error);
      }
    },
    createChart(data) {
      const ctx = this.$refs.chart.getContext('2d');
      this.chart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.map(item => `VLAN ${item.vlan_id}`),
          datasets: [
            {
              label: 'Reserved IPs',
              data: data.map(item => item.reserved_ips),
              backgroundColor: 'rgba(75, 192, 192, 0.6)'
            },
            {
              label: 'Total IPs',
              data: data.map(item => item.total_ips),
              backgroundColor: 'rgba(54, 162, 235, 0.6)'
            }
          ]
        },
        options: {
          responsive: true,
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Number of IPs'
              }
            },
            x: {
              title: {
                display: true,
                text: 'VLANs'
              }
            }
          },
          plugins: {
            title: {
              display: true,
              text: 'VLAN Occupancy'
            },
            tooltip: {
              callbacks: {
                afterBody: function(tooltipItems) {
                  const dataIndex = tooltipItems[0].dataIndex;
                  const occupancyRate = data[dataIndex].occupancy_rate;
                  return `Occupancy Rate: ${occupancyRate}%`;
                }
              }
            }
          }
        }
      });
    }
  }
}
</script>