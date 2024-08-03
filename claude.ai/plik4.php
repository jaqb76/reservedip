<?php

// app/Http/Controllers/IpManagementController.php
namespace App\Http\Controllers;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IpManagementController extends Controller
{
    public function reserveIp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ip' => 'required|ip'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $ip = IpAddress::where('address', $request->ip)->first();
        if ($ip && !$ip->is_reserved) {
            $ip->update(['is_reserved' => true]);
            return response()->json(['message' => 'IP zarezerwowane']);
        }
        return response()->json(['message' => 'Nie można zarezerwować IP'], 400);
    }

    public function releaseIp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ip' => 'required|ip'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $ip = IpAddress::where('address', $request->ip)->first();
        if ($ip && $ip->is_reserved) {
            $ip->update(['is_reserved' => false]);
            return response()->json(['message' => 'IP zwolnione']);
        }
        return response()->json(['message' => 'Nie można zwolnić IP'], 400);
    }

    public function pingIp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ip' => 'required|ip'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        exec("ping -c 4 " . escapeshellarg($request->ip), $output, $result);
        $isReachable = ($result == 0);

        return response()->json([
            'ip' => $request->ip,
            'isReachable' => $isReachable,
            'output' => $output
        ]);
    }

    public function scanSubnet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subnet_id' => 'required|exists:subnets,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $subnet = Subnet::findOrFail($request->subnet_id);
        $network = $subnet->network;
        $mask = $subnet->mask;

        $ips = $this->getIpsFromSubnet($network, $mask);
        $results = [];

        foreach ($ips as $ip) {
            exec("ping -c 1 -W 1 " . escapeshellarg($ip), $output, $result);
            $results[$ip] = ($result == 0);
        }

        return response()->json($results);
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

    public function addSubnet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'network' => 'required|ip',
            'mask' => 'required|ip',
            'vlan_id' => 'required|exists:vlans,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $subnet = Subnet::create($request->all());
        return response()->json($subnet, 201);
    }

    public function addVlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'vlan_id' => 'required|integer|between:1,4094|unique:vlans,vlan_id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $vlan = Vlan::create($request->all());
        return response()->json($vlan, 201);
    }
}

// tests/Feature/IpManagementTest.php
namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpManagementTest extends TestCase
{
    use RefreshDatabase;

    public function testReserveIp()
    {
        $vlan = Vlan::factory()->create();
        $subnet = Subnet::factory()->create(['vlan_id' => $vlan->id]);
        $ip = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'is_reserved' => false]);

        $response = $this->postJson('/api/reserve-ip', ['ip' => $ip->address]);

        $response->assertStatus(200)
                 ->assertJson(['message' => 'IP zarezerwowane']);

        $this->assertTrue(IpAddress::find($ip->id)->is_reserved);
    }

    public function testReleaseIp()
    {
        $vlan = Vlan::factory()->create();
        $subnet = Subnet::factory()->create(['vlan_id' => $vlan->id]);
        $ip = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'is_reserved' => true]);

        $response = $this->postJson('/api/release-ip', ['ip' => $ip->address]);

        $response->assertStatus(200)
                 ->assertJson(['message' => 'IP zwolnione']);

        $this->assertFalse(IpAddress::find($ip->id)->is_reserved);
    }

    public function testPingIp()
    {
        $response = $this->getJson('/api/ping-ip?ip=8.8.8.8');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'ip',
                     'isReachable',
                     'output'
                 ]);
    }

    public function testScanSubnet()
    {
        $vlan = Vlan::factory()->create();
        $subnet = Subnet::factory()->create(['vlan_id' => $vlan->id]);

        $response = $this->getJson("/api/scan-subnet?subnet_id={$subnet->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure(['*' => []]);
    }

    public function testAddSubnet()
    {
        $vlan = Vlan::factory()->create();

        $response = $this->postJson('/api/add-subnet', [
            'name' => 'Test Subnet',
            'network' => '192.168.1.0',
            'mask' => '255.255.255.0',
            'vlan_id' => $vlan->id
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'id',
                     'name',
                     'network',
                     'mask',
                     'vlan_id'
                 ]);
    }

    public function testAddVlan()
    {
        $response = $this->postJson('/api/add-vlan', [
            'name' => 'Test VLAN',
            'vlan_id' => 100
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'id',
                     'name',
                     'vlan_id'
                 ]);
    }
}

// tests/Unit/IpManagementTest.php
namespace Tests\Unit;

use App\Http\Controllers\IpManagementController;
use PHPUnit\Framework\TestCase;

class IpManagementTest extends TestCase
{
    public function testGetIpsFromSubnet()
    {
        $controller = new IpManagementController();
        $method = new \ReflectionMethod($controller, 'getIpsFromSubnet');
        $method->setAccessible(true);

        $ips = $method->invoke($controller, '192.168.1.0', '255.255.255.0');

        $this->assertCount(254, $ips);
        $this->assertEquals('192.168.1.1', $ips[0]);
        $this->assertEquals('192.168.1.254', $ips[253]);
    }
}

// database/factories/VlanFactory.php
namespace Database\Factories;

use App\Models\Vlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class VlanFactory extends Factory
{
    protected $model = Vlan::class;

    public function definition()
    {
        return [
            'name' => $this->faker->word,
            'vlan_id' => $this->faker->unique()->numberBetween(1, 4094),
        ];
    }
}

// database/factories/SubnetFactory.php
namespace Database\Factories;

use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubnetFactory extends Factory
{
    protected $model = Subnet::class;

    public function definition()
    {
        return [
            'name' => $this->faker->word,
            'network' => $this->faker->ipv4,
            'mask' => '255.255.255.0',
            'vlan_id' => Vlan::factory(),
        ];
    }
}

// database/factories/IpAddressFactory.php
namespace Database\Factories;

use App\Models\IpAddress;
use App\Models\Subnet;
use Illuminate\Database\Eloquent\Factories\Factory;

class IpAddressFactory extends Factory
{
    protected $model = IpAddress::class;

    public function definition()
    {
        return [
            'address' => $this->faker->unique()->ipv4,
            'is_reserved' => $this->faker->boolean,
            'subnet_id' => Subnet::factory(),
        ];
    }
}