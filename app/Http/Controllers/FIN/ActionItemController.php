<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\ActionItemModel;
use App\Models\FIN\ConfigModel;
use App\Services\FIN\LedgerPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ActionItemController extends Controller
{
    /**
     * Display a listing of action items
     */
    public function index(Request $request)
    {
        // Get filter
        $status = $request->get('status', 'open');
        
        // Build query
        $query = ActionItemModel::with(['createdBy', 'resolvedBy', 'order', 'importLog']);
        
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        
        // Get counts by status
        $statusCounts = [
            'open' => ActionItemModel::where('status', 'open')->count(),
            'resolved' => ActionItemModel::where('status', 'resolved')->count(),
            'ignored' => ActionItemModel::where('status', 'ignored')->count(),
            'all' => ActionItemModel::count(),
        ];
        
        // Get items ordered by severity and date
        $items = $query->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
                      ->orderBy('created_at', 'desc')
                      ->paginate(20);
        
        // Check ledger posting config
        $autoPostEnabled = ConfigModel::where('config_key', 'LEDGER_AUTO_POST_ENABLED')
            ->value('config_value');
        
        return view('fin.action-items.index', compact('items', 'statusCounts', 'status', 'autoPostEnabled'));
    }
    
    /**
     * Display the specified action item
     */
    public function show($id)
    {
        $item = ActionItemModel::with(['createdBy', 'resolvedBy', 'order', 'importLog'])
                              ->findOrFail($id);
        
        return view('fin.action-items.show', compact('item'));
    }
    
    /**
     * Mark action item as resolved
     */
    public function resolve(Request $request, $id)
    {
        $request->validate([
            'resolution_notes' => 'required|string|max:1000'
        ]);
        
        try {
            $item = ActionItemModel::findOrFail($id);
            
            $item->status = ActionItemModel::STATUS_RESOLVED;
            $item->resolved_by = auth()->id();
            $item->resolved_at = now();
            $item->resolution_notes = $request->resolution_notes;
            $item->save();
            
            return redirect()->route('fin.action-items.index')
                           ->with('success', 'Action item marked as resolved!');
            
        } catch (\Exception $e) {
            Log::error("Error resolving action item: " . $e->getMessage());
            return back()->with('error', 'Error resolving action item: ' . $e->getMessage());
        }
    }
    
    /**
     * Dismiss (ignore) action item
     */
    public function dismiss(Request $request, $id)
    {
        $request->validate([
            'resolution_notes' => 'nullable|string|max:1000'
        ]);
        
        try {
            $item = ActionItemModel::findOrFail($id);
            
            $item->status = ActionItemModel::STATUS_IGNORED;
            $item->resolved_by = auth()->id();
            $item->resolved_at = now();
            $item->resolution_notes = $request->resolution_notes ?? 'Dismissed by user';
            $item->save();
            
            return redirect()->route('fin.action-items.index')
                           ->with('success', 'Action item dismissed!');
            
        } catch (\Exception $e) {
            Log::error("Error dismissing action item: " . $e->getMessage());
            return back()->with('error', 'Error dismissing action item: ' . $e->getMessage());
        }
    }
    
    /**
     * Retry posting to ledger for failed orders
     */
    public function retryPosting(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            
            $item = ActionItemModel::with('order')->findOrFail($id);
            
            if (!$item->order) {
                throw new \Exception("No order associated with this action item");
            }
            
            if ($item->item_type !== ActionItemModel::TYPE_MISSING_RIDER) {
                throw new \Exception("This action item cannot be retried");
            }
            
            // Attempt to post to ledger again
            $ledgerService = new LedgerPostingService();
            $result = $ledgerService->postInvoiceFromOrder($item->order);
            
            if ($result['success']) {
                // Mark as resolved
                $item->status = ActionItemModel::STATUS_RESOLVED;
                $item->resolved_by = auth()->id();
                $item->resolved_at = now();
                $item->resolution_notes = "Successfully posted to ledger. Ledger ID: " . ($result['ledger_id'] ?? 'N/A');
                $item->save();
                
                DB::commit();
                
                return redirect()->route('fin.action-items.index')
                               ->with('success', 'Successfully posted to ledger!');
            } else {
                DB::rollBack();
                return back()->with('error', 'Failed to post to ledger: ' . ($result['message'] ?? 'Unknown error'));
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error retrying ledger posting: " . $e->getMessage());
            return back()->with('error', 'Error retrying posting: ' . $e->getMessage());
        }
    }
    
    /**
     * Toggle automatic ledger posting on/off
     */
    public function toggleLedgerPosting(Request $request)
    {
        try {
            // Accept both boolean and string values
            $enabledInput = $request->input('enabled');
            $enabled = ($enabledInput === true || $enabledInput === 'true' || $enabledInput === 1 || $enabledInput === '1') ? '1' : '0';
            
            ConfigModel::updateOrCreate(
                ['config_key' => 'LEDGER_AUTO_POST_ENABLED'],
                [
                    'config_value' => $enabled,
                    'description' => 'Enable or disable automatic ledger posting for delivered orders.'
                ]
            );
            
            $status = $enabled === '1' ? 'enabled' : 'disabled';
            
            return response()->json([
                'success' => true,
                'message' => "Automatic ledger posting {$status}!",
                'enabled' => $enabled === '1'
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error toggling ledger posting: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
