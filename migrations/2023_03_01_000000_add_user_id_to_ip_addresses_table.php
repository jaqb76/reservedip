// database/migrations/2023_03_01_000000_add_user_id_to_ip_addresses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class AddUserIdToIpAddressesTable extends Migration
{
    public function up()
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down()
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropForeign('ip_addresses_user_id_foreign');
            $table->dropColumn('user_id');
        });
    }
}