// app/Models/Subnet.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subnet extends Model
{
    protected $fillable = [
        'name',
        'address',
        'ask',
        'vlan_id',
    ];

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }

    public function getIpAddressCountAttribute()
    {
        $mask = ip2long($this->mask);
        $address = ip2long($this->address);
        $count = 0;
        for ($i = 0; $i < 32; $i++) {
            if (($mask >> $i) & 1) {
                $count += pow(2, 32 - $i - 1);
            }
        }
        return $count;
    }
}