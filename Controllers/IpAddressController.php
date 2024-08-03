// app/Http/Controllers/IpAddressController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IpAddress;

class IpAddressController extends Controller
{
    public function store(Request $request)
    {
        $ipAddress = new IpAddress();
        $ipAddress->address = $request->input('address');
        $ipAddress->reserved = $request->input('reserved');
        $ipAddress->description = $request->input('description');
        $ipAddress->user_id = auth()->id();
        $ipAddress->save();
        return redirect()->route('ip_addresses.index');
    }

    public function update(Request $request, $id)
    {
        $ipAddress = IpAddress::find($id);
        $ipAddress->address = $request->input('address');
        $ipAddress->reserved = $request->input('reserved');
        $ipAddress->description = $request->input('description');
        $ipAddress->user_id = auth()->id();
        $ipAddress->save();
        return redirect()->route('ip_addresses.index');
    }
}