<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FIN\LegacyImportService;
use App\Models\FIN\ImportLogModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\AccountModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportController extends Controller
{
    /**
     * Show import history
     */
    public function index()
    {
        $imports = ImportLogModel::with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('fin.import.index', compact('imports'));
    }

    /**
     * Show import details
     */
    public function show($id)
    {
        $import = ImportLogModel::with('creator')->findOrFail($id);

        return view('fin.import.show', compact('import'));
    }

    /**
     * Show import form
     */
    public function create()
    {
        return view('fin.import.create');
    }

    /**
     * Process legacy CSV import
     */
    public function importLegacy(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240' // 10MB max
        ]);

        try {
            $file = $request->file('csv_file');
            $path = $file->getRealPath();

            $importService = new LegacyImportService();
            $result = $importService->import($path, auth()->id());

            if ($result['success']) {
                $stats = $result['stats'];
                $unmatchedEmployees = $result['unmatched_employees'] ?? [];

                // Build HTML response
                $html = '<div class="space-y-4">';
                
                // Success summary
                $html .= '<div class="p-4 bg-green-50 border border-green-200 rounded">';
                $html .= '<div class="text-sm font-semibold text-green-800 mb-3">✅ Import Successful!</div>';
                $html .= '<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">';
                $html .= '<div><span class="font-semibold text-green-900">Invoices:</span> <span class="text-green-700">' . $stats['invoices'] . '</span></div>';
                $html .= '<div><span class="font-semibold text-green-900">Expenses:</span> <span class="text-green-700">' . $stats['expenses'] . '</span></div>';
                $html .= '<div><span class="font-semibold text-green-900">Vendor Purchases:</span> <span class="text-green-700">' . $stats['vendor_purchases'] . '</span></div>';
                $html .= '<div><span class="font-semibold text-green-900">Vendor Payments:</span> <span class="text-green-700">' . $stats['vendor_payments'] . '</span></div>';
                $html .= '<div><span class="font-semibold text-green-900">Deposits:</span> <span class="text-green-700">' . $stats['deposits'] . '</span></div>';
                $html .= '<div><span class="font-semibold text-green-900">Skipped:</span> <span class="text-green-700">' . $stats['skipped'] . '</span></div>';
                $html .= '<div><span class="font-semibold text-green-900">Total:</span> <span class="text-green-700">' . $stats['total_processed'] . '</span></div>';
                $html .= '</div>';
                $html .= '</div>';

                // Show unmatched employees if any
                if (!empty($unmatchedEmployees)) {
                    $html .= '<div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded">';
                    $html .= '<div class="text-sm font-semibold text-yellow-800 mb-2">⚠️ Unmatched Employees (' . count($unmatchedEmployees) . ')</div>';
                    $html .= '<div class="text-sm text-yellow-700">The following employees were not found in the user table. Please add them and re-import:</div>';
                    $html .= '<ul class="mt-2 text-sm text-yellow-800 list-disc list-inside">';
                    foreach ($unmatchedEmployees as $emp) {
                        $html .= '<li>' . htmlspecialchars($emp) . '</li>';
                    }
                    $html .= '</ul>';
                    $html .= '<div class="mt-2 text-xs text-yellow-600">' . $stats['skipped'] . ' records were skipped due to unmatched employees.</div>';
                    $html .= '</div>';
                }

                $html .= '</div>';

                return redirect()->back()->with('import_result', $html);
            } else {
                return redirect()->back()
                    ->with('error', 'Import failed: ' . $result['message'])
                    ->withInput();
            }

        } catch (\Exception $e) {
            Log::error('CSV Import Error: ' . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Import failed: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Clear all legacy imported data
     */
    public function clearLegacyData(Request $request)
    {
        $request->validate([
            'confirmation' => 'required|in:DELETE_ALL_LEGACY_DATA'
        ]);

        try {
            DB::beginTransaction();

            // Count records before deletion
            $ledgerCount = LedgerModel::where('external_source', 'LIKE', '%legacy%')
                ->orWhere('external_source', 'LIKE', '%appsheet%')
                ->count();

            $importLogCount = ImportLogModel::count();

            // 1. Delete ledger transactions from legacy imports
            LedgerModel::where('external_source', 'LIKE', '%legacy%')
                ->orWhere('external_source', 'LIKE', '%appsheet%')
                ->delete();

            // 2. Reset account balances (except system accounts)
            AccountModel::where('is_active', 1)
                ->whereNotIn('account_code', ['REV_SALES', 'REV_OTHER', 'EQUITY_OPENING'])
                ->update([
                    'current_balance' => 0.00,
                    'opening_balance' => 0.00
                ]);

            // 3. Delete import logs
            ImportLogModel::truncate();

            DB::commit();

            Log::info('Legacy data cleared', [
                'ledger_records_deleted' => $ledgerCount,
                'import_logs_deleted' => $importLogCount,
                'user_id' => auth()->id()
            ]);

            return redirect()->back()->with('success', 
                "✅ Legacy data cleared successfully! Deleted {$ledgerCount} ledger transactions and {$importLogCount} import logs. Account balances reset to 0. You can now re-import your CSV.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error clearing legacy data: ' . $e->getMessage());
            
            return redirect()->back()->with('error', 
                'Failed to clear legacy data: ' . $e->getMessage());
        }
    }

    /**
     * Delete specific import batch
     */
    public function deleteImport(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $import = ImportLogModel::findOrFail($id);

            // Find all ledger entries from this import
            // (This requires tracking import_id in ledger, which we'll add)
            $ledgerCount = LedgerModel::where('external_source', 'LIKE', '%legacy%')
                ->whereBetween('created_at', [
                    $import->created_at->subMinutes(5),
                    $import->created_at->addMinutes(5)
                ])
                ->count();

            // Delete ledger entries from this timeframe
            LedgerModel::where('external_source', 'LIKE', '%legacy%')
                ->whereBetween('created_at', [
                    $import->created_at->subMinutes(5),
                    $import->created_at->addMinutes(5)
                ])
                ->delete();

            // Delete import log
            $import->delete();

            // Note: Balances will be incorrect after partial deletion
            // User should use "Clear All" for clean slate

            DB::commit();

            return redirect()->route('fin.import.index')->with('success',
                "Import batch deleted. {$ledgerCount} ledger records removed. Note: Account balances may need adjustment.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting import: ' . $e->getMessage());
            
            return redirect()->back()->with('error',
                'Failed to delete import: ' . $e->getMessage());
        }
    }

    /**
     * Download template CSV
     */
    public function downloadTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="legacy_expense_template.csv"',
        ];

        $columns = [
            'date', 'Name', 'category', 'mode', 'type ', 'Amount', 
            'approval status', 'approval date', 'source', 'transaction id', 
            'ref id', 'comments', 'last updated', 'device'
        ];

        $callback = function() use ($columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            
            // Add sample data
            fputcsv($file, [
                '1/31/2025', 'Jazib', 'petrol', 'Cash', 'cash out', '1836',
                'YES', '', 'legacy_import', 'TXN001', 'REF001', 'Sample expense', '1/31/2025', ''
            ]);
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
