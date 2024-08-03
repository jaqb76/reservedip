// app/Http/Controllers/VlanController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vlan;

class VlanController extends Controller
{
    public function index()
    {
        $vlans = Vlan::with('subnets', 'ubnets.ipAddresses')->get();
        return view('vlans.index', compact('vlans'));
    }

    public function create()
    {
        return view('vlans.create');
    }

    public function store(Request $request)
    {
        $vlan = new Vlan();
        $vlan->name = $request->input('name');
        $vlan->description = $request->input('description');
        $vlan->save();
        return redirect()->route('vlans.index');
    }

    public function show($id)
    {
        $vlan = Vlan::find($id);
        return view('vlans.show', compact('vlan'));
    }

    public function edit($id)
    {
        $vlan = Vlan::find($id);
        return view('vlans.edit', compact('vlan'));
    }

    public function update(Request $request, $id)
    {
        $vlan = Vlan::find($id);
        $vlan->name = $request->input('name');
        $vlan->description = $request->input('description');
        $vlan->save();
        return redirect()->route('vlans.index');
    }

    public function destroy($id)
    {
        $vlan = Vlan::find($id);
        $vlan->delete();
        return redirect()->route('vlans.index');
    }
}