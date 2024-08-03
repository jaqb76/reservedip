<?php

// app/Notifications/RepeatedScanFailure.php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RepeatedScanFailure extends Notification
{
    use Queueable;

    protected $subnet;
    protected $failureCount;

    public function __construct($subnet, $failureCount)
    {
        $this->subnet = $subnet;
        $this->failureCount = $failureCount;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Repeated Scan Failure Alert')
                    ->line("Subnet scan for {$this->subnet->network}/{$this->subnet->mask} has failed {$this->failureCount} times.")
                    ->action('View Subnet Details', url("/subnets/{$this->subnet->id}"))
                    ->line('Please investigate and resolve the issue.');
    }

    public function toArray($notifiable)
    {
        return [
            'subnet_id' => $this->subnet->id,
            'network' => $this->subnet->network,
            'mask' => $this->subnet->mask,
            'failure_count' => $this->failureCount,
        ];
    }
}

// app/Models/User.php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    // ... (existing code)

    public function isAdmin()
    {
        return $this->role === 'admin'; // Assume 'role' field exists in users table
    }
}

// app/Jobs/ScanSubnetJob.php
namespace App\Jobs;

use App\Models\Subnet;
use App\Models\ScanJob;
use App\Models\User;
use App\Notifications\RepeatedScanFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ScanSubnetJob implements ShouldQueue
{
    // ... (existing code)

    public function failed(\Throwable $exception)
    {
        Log::error("ScanSubnetJob failed for subnet {$this->subnet->id}: " . $exception->getMessage());
        
        $scanJob = ScanJob::updateOrCreate(
            ['subnet_id' => $this->subnet->id],
            ['status' => 'failed', 'error_message' => $exception->getMessage()]
        );

        // Increment failure count
        $scanJob->increment('failure_count');

        // Check if failure count exceeds threshold
        if ($scanJob->failure_count >= 3) {
            $this->notifyAdmins();
        }
    }

    private function notifyAdmins()
    {
        $admins = User::where('role', 'admin')->get();
        Notification::send($admins, new RepeatedScanFailure($this->subnet, $scanJob->failure_count));
    }

    // ... (existing code)
}

// database/migrations/xxxx_xx_xx_add_failure_count_to_scan_jobs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFailureCountToScanJobsTable extends Migration
{
    public function up()
    {
        Schema::table('scan_jobs', function (Blueprint $table) {
            $table->integer('failure_count')->default(0)->after('error_message');
        });
    }

    public function down()
    {
        Schema::table('scan_jobs', function (Blueprint $table) {
            $table->dropColumn('failure_count');
        });
    }
}

// database/migrations/xxxx_xx_xx_create_notifications_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('notifications');
    }
}

// app/Http/Controllers/NotificationController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = $user->unreadNotifications;

        return view('notifications.index', compact('notifications'));
    }

    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return redirect()->back()->with('success', 'Notification marked as read.');
    }
}

// routes/web.php
use App\Http\Controllers\NotificationController;

Route::middleware(['auth'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
});

// resources/views/notifications/index.blade.php
@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Notifications</h2>
    @foreach ($notifications as $notification)
        <div class="notification">
            <p>
                Subnet scan for {{ $notification->data['network'] }}/{{ $notification->data['mask'] }} 
                has failed {{ $notification->data['failure_count'] }} times.
            </p>
            <form action="{{ route('notifications.markAsRead', $notification->id) }}" method="POST">
                @csrf
                <button type="submit">Mark as Read</button>
            </form>
        </div>
    @endforeach
</div>
@endsection