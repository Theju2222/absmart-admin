<?php

namespace App\Jobs;

use App\Models\ProductBulkImport;
use App\Services\ProductBulkSchema;
use App\Services\ProductBulkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Runs a validated product bulk upload/update and reports progress on its
 * ProductBulkImport row (polled by the admin panel's progress bar).
 *
 * The file was fully validated before dispatch, so failures here are runtime ones
 * (e.g. an SKU taken between validation and processing). Each product/row commits in
 * its own transaction: one bad group is recorded and skipped instead of rolling back
 * two thousand finished rows — with a queue, "all-or-nothing across the whole file"
 * would also mean holding one giant transaction open for the entire run.
 */
class ProcessProductBulkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Long files take a while; one attempt, generous timeout. */
    public $timeout = 3600;
    public $tries = 1;

    private int $importId;

    public function __construct(int $importId)
    {
        $this->importId = $importId;
    }

    public function handle(): void
    {
        $import = ProductBulkImport::find($this->importId);
        if (!$import || $import->status !== 'pending') {
            return;
        }

        $import->update(['status' => 'processing']);

        try {
            $service = app(ProductBulkService::class);
            $schema = ProductBulkSchema::make(
                (int) $import->category_id,
                array_map('intval', $import->store_ids ?? []),
                $import->type === 'update'
            );

            $read = $service->read(Storage::disk('local')->path($import->file_path), $schema->columns());
            if (!empty($read['errors'])) {
                $import->update(['status' => 'failed', 'message' => $read['errors'][0]]);
                return;
            }

            $errors = [];
            $success = 0;
            $processed = 0;

            if ($import->type === 'update') {
                $import->update(['total_rows' => count($read['rows'])]);
                foreach ($read['rows'] as $row) {
                    try {
                        DB::transaction(fn () => $service->updateRow($row, $schema));
                        $success++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $row['__row'] ?? 0, 'error' => $e->getMessage()];
                    }
                    $processed++;
                    $import->update(['processed_rows' => $processed]);
                }
            } else {
                $groups = $service->groupByHandle($read['rows']);
                $import->update(['total_rows' => count($groups)]);
                foreach ($groups as $handle => $group) {
                    try {
                        DB::transaction(function () use ($service, $handle, $group, $schema) {
                            $err = $service->createProduct((string) $handle, $group, $schema);
                            if ($err !== null) {
                                throw new \RuntimeException($err);
                            }
                        });
                        $success++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $group[0]['__row'] ?? 0, 'error' => $handle . ': ' . $e->getMessage()];
                    }
                    $processed++;
                    $import->update(['processed_rows' => $processed]);
                }
            }

            $import->update([
                'status' => 'completed',
                'success_count' => $success,
                'errors' => $errors ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Product bulk job ' . $this->importId . ' failed: ' . $e->getMessage());
            $import->update(['status' => 'failed', 'message' => $e->getMessage()]);
        } finally {
            Storage::disk('local')->delete($import->file_path);
        }
    }

    public function failed(\Throwable $e): void
    {
        ProductBulkImport::where('id', $this->importId)
            ->update(['status' => 'failed', 'message' => $e->getMessage()]);
    }
}
