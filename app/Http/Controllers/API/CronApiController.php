<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CronApiController extends Controller
{
    /**
     * Manually runnable scheduled tasks. Keys are what the frontend posts back;
     * closures keep the artisan invocations in one auditable place.
     */
    private function runnableTasks(): array
    {
        return [
            'schedule' => [
                'command' => 'schedule:run',
                'call' => fn () => Artisan::call('schedule:run'),
            ],
            'queue' => [
                'command' => 'queue:work --stop-when-empty --max-time=55',
                'call' => fn () => Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 55]),
            ],
            'cart_notification' => [
                'command' => 'cart:notification',
                'call' => fn () => Artisan::call('cart:notification'),
            ],
            'maintenance' => [
                'command' => 'maintenance:apply',
                'call' => fn () => Artisan::call('maintenance:apply'),
            ],
            'home_layout_publish' => [
                'command' => 'home-layout:publish-scheduled',
                'call' => fn () => Artisan::call('home-layout:publish-scheduled'),
            ],
            'popup_schedule' => [
                'command' => 'popup:apply-schedule',
                'call' => fn () => Artisan::call('popup:apply-schedule'),
            ],
            'spin_wheel_schedule' => [
                'command' => 'spin-wheel:apply-schedule',
                'call' => fn () => Artisan::call('spin-wheel:apply-schedule'),
            ],
            'spin_wheel_codes' => [
                'command' => 'promo:expire-spin-codes',
                'call' => fn () => Artisan::call('promo:expire-spin-codes'),
            ],
        ];
    }

    public function index()
    {
        $heartbeat = Cache::get('cron_last_heartbeat');
        $heartbeatAge = $heartbeat ? Carbon::parse($heartbeat)->diffInSeconds(now()) : null;

        $oldestPending = DB::table('jobs')->min('created_at');

        $data = [
            // The same scheduler entry in the three shapes hosting UIs ask for:
            //  - crontab_line: full line for SSH `crontab -e` (interval included)
            //  - command_custom: command only — panels with their own interval picker
            //  - command_php: "PHP script" style — panels that ask for a PHP file + args
            'crontab_line' => '* * * * * cd ' . base_path() . ' && ' . (PHP_BINARY ?: 'php') . ' artisan schedule:run >> /dev/null 2>&1',
            'command_custom' => 'cd ' . base_path() . ' && ' . (PHP_BINARY ?: 'php') . ' artisan schedule:run >> /dev/null 2>&1',
            'command_php' => (PHP_BINARY ?: 'php') . ' ' . base_path('artisan') . ' schedule:run',
            'php_binary' => PHP_BINARY ?: 'php',
            'artisan_path' => base_path('artisan'),
            'project_path' => base_path(),

            // Is the server cron actually firing? Heartbeat is written by the
            // scheduler every minute; > ~2 min old means the crontab isn't running.
            'cron_active' => $heartbeatAge !== null && $heartbeatAge < 150,
            'last_heartbeat' => $heartbeat,
            'heartbeat_age_seconds' => $heartbeatAge,

            'queue' => [
                'driver' => config('queue.default'),
                'pending_jobs' => (int) DB::table('jobs')->count(),
                'failed_jobs' => (int) DB::table('failed_jobs')->count(),
                'oldest_pending_at' => $oldestPending ? Carbon::createFromTimestamp((int) $oldestPending)->toDateTimeString() : null,
            ],

            // Tasks schedule:run fires every minute; each can be run manually.
            'scheduled_tasks' => [
                [
                    'key' => 'schedule',
                    'name' => __('run_all_due_scheduled_tasks'),
                    'command' => 'schedule:run',
                    'schedule' => __('every_minute'),
                    'description' => __('cron_task_schedule_run_desc'),
                ],
                [
                    'key' => 'queue',
                    'name' => __('process_queued_jobs'),
                    'command' => 'queue:work --stop-when-empty',
                    'schedule' => __('every_minute'),
                    'description' => __('cron_task_queue_desc'),
                ],
                [
                    'key' => 'cart_notification',
                    'name' => __('cart_reminder_notifications'),
                    'command' => 'cart:notification',
                    'schedule' => __('every_minute'),
                    'description' => __('cron_task_cart_notification_desc'),
                ],
                [
                    'key' => 'maintenance',
                    'name' => __('scheduled_maintenance'),
                    'command' => 'maintenance:apply',
                    'schedule' => __('every_minute'),
                    'description' => __('cron_task_maintenance_desc'),
                ],
                [
                    'key' => 'home_layout_publish',
                    'name' => __('scheduled_home_layout_publish'),
                    'command' => 'home-layout:publish-scheduled',
                    'schedule' => __('every_minute'),
                    'description' => __('cron_task_home_layout_publish_desc'),
                ],
                [
                    'key' => 'popup_schedule',
                    'name' => __('scheduled_popup_offer'),
                    'command' => 'popup:apply-schedule',
                    'schedule' => __('every_minute'),
                    'description' => __('cron_task_popup_schedule_desc'),
                ],
                [
                    'key' => 'spin_wheel_schedule',
                    'name' => __('scheduled_spin_wheel_campaign'),
                    'command' => 'spin-wheel:apply-schedule',
                    'schedule' => __('every_minute'),
                    'description' => __('cron_task_spin_wheel_schedule_desc'),
                ],
                [
                    'key' => 'spin_wheel_codes',
                    'name' => __('expire_spin_wheel_coupons'),
                    'command' => 'promo:expire-spin-codes',
                    'schedule' => __('daily'),
                    'description' => __('cron_task_spin_wheel_codes_desc'),
                ],
            ],

            // Background jobs executed BY the queue — informational: they are
            // dispatched by app events, not run directly.
            'background_jobs' => [
                ['name' => __('referral_bonus_credit'), 'description' => __('cron_job_referral_bonus_desc')],
                ['name' => __('order_cashback_credit'), 'description' => __('cron_job_cashback_desc')],
                ['name' => __('order_emails'), 'description' => __('cron_job_order_emails_desc')],
                ['name' => __('order_push_notifications'), 'description' => __('cron_job_order_notifications_desc')],
                ['name' => __('bulk_promotional_emails'), 'description' => __('cron_job_bulk_emails_desc')],
                ['name' => __('bulk_push_notifications'), 'description' => __('cron_job_bulk_push_desc')],
                ['name' => __('product_bulk_import'), 'description' => __('cron_job_product_bulk_desc')],
                ['name' => __('cart_reminder_notifications'), 'description' => __('cron_job_cart_reminder_desc')],
                ['name' => __('spin_wheel_reward_notifications'), 'description' => __('cron_job_spin_wheel_reward_desc')],
                ['name' => __('back_in_stock_notifications'), 'description' => __('cron_job_back_in_stock_desc')],
            ],
        ];

        return CommonHelper::responseWithData($data);
    }

    /** Run one scheduled task now (POST task=schedule|queue|cart_notification). */
    public function run(Request $request)
    {
        $tasks = $this->runnableTasks();
        $key = (string) $request->input('task');

        if (!isset($tasks[$key])) {
            return CommonHelper::responseError('invalid_task');
        }

        try {
            // Time-boxed by each command itself (queue:work carries --max-time=55).
            $exitCode = ($tasks[$key]['call'])();
            $output = trim(Artisan::output());

            return CommonHelper::responseSuccessWithData('task_executed_successfully', [
                'task' => $key,
                'command' => $tasks[$key]['command'],
                'exit_code' => (int) $exitCode,
                'output' => mb_substr($output, 0, 5000),
            ]);
        } catch (\Throwable $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }
}
