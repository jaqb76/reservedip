// app/Models/Vlan.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vlan extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function subnets()
    {
        return $this->hasMany(Subnet::class);
    }

    public function getIpAddressCountAttribute()
    {
        $count = 0;
        foreach ($this->subnets as $subnet) {
            $count += $subnet->ipAddressCount;
        }
        return $count;
    }
}