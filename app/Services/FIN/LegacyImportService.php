<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\VendorModel;
use App\Models\FIN\ImportLogModel;
use App\Models\FIN\ConfigModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LegacyImportService
{
    private $importLog;
    private $accountCache = [];
    private $vendorCache = [];
    private $userCache = [];
    private $stats = [
        'invoices' => 0,
        'expenses' => 0,
        'vendor_purchases' => 0,
        'vendor_payments' => 0,
        'deposits' => 0,
        'skipped' => 0,
        'failed' => 0
    ];
    
    private $unmatchedEmployees = [];
    private $skippedRecords = [];

    /**
     * Import legacy CSV file
     */
    public function import($filePath, $userId = null)
    {
        try {
            DB::beginTransaction();

            // Start import log
            $this->importLog = ImportLogModel::startImport(
                'legacy_csv',
                basename($filePath),
                $userId
            );

            Log::info("Starting legacy import from: {$filePath}");

            // Read and parse CSV
            $rows = $this->readCSV($filePath);
            
            if (empty($rows)) {
                throw new \Exception("No data found in CSV file");
            }

            Log::info("Found " . count($rows) . " rows to process");

            // Process each row
            foreach ($rows as $index => $row) {
                try {
                    $this->processRow($row, $index);
                } catch (\Exception $e) {
                    Log::error("Error processing row {$index}: " . $e->getMessage(), ['row' => $row]);
                    $this->stats['failed']++;
                    $this->importLog->updateProgress(0, 0, 1);
                }
            }

            // Complete import
            $summary = array_merge($this->stats, [
                'unmatched_employees' => array_unique($this->unmatchedEmployees),
                'skipped_records_count' => count($this->skippedRecords)
            ]);
            
            $this->importLog->complete($summary);
            
            DB::commit();

            Log::info("Import completed successfully", $summary);

            return [
                'success' => true,
                'import_log' => $this->importLog,
                'stats' => $this->stats,
                'unmatched_employees' => array_unique($this->unmatchedEmployees),
                'skipped_records' => $this->skippedRecords
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            if ($this->importLog) {
                $this->importLog->markFailed(['error' => $e->getMessage()]);
            }

            Log::error("Import failed: " . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Read CSV file
     */
    private function readCSV($filePath)
    {
        $rows = [];
        $headers = [];
        
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            $lineNumber = 0;
            
            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                $lineNumber++;
                
                if ($lineNumber === 1) {
                    // Store headers
                    $headers = array_map('trim', $data);
                    continue;
                }
                
                // Skip empty rows
                if (empty(array_filter($data))) {
                    continue;
                }
                
                // Map data to headers
                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = isset($data[$index]) ? trim($data[$index]) : null;
                }
                
                $rows[] = $row;
            }
            
            fclose($handle);
        }
        
        return $rows;
    }

    /**
     * Process single row
     */
    private function processRow($row, $index)
    {
        // Extract row data
        $date = $this->parseDate($row['date'] ?? null);
        $name = trim($row['Name'] ?? '');
        $category = trim($row['category'] ?? '');
        $mode = strtolower(trim($row['mode'] ?? 'cash'));
        $type = trim($row['type '] ?? $row['type'] ?? ''); // Note space in header
        $amount = $this->parseAmount($row['Amount'] ?? 0);
        $approvalStatus = trim($row['approval status'] ?? 'Auto');
        $approvalDate = $this->parseDate($row['approval date'] ?? null);
        $source = trim($row['source'] ?? 'legacy_import');
        $transactionId = trim($row['transaction id'] ?? '');
        $refId = trim($row['ref id'] ?? '');
        $device = trim($row['device'] ?? '');
        $comments = trim($row['comments'] ?? '');

        // Skip if no amount or invalid
        if ($amount <= 0) {
            $this->stats['skipped']++;
            $this->importLog->updateProgress(0, 1, 0);
            return;
        }

        // Check for duplicate
        if (!empty($transactionId) && LedgerModel::transactionExists($source, $transactionId)) {
            $this->stats['skipped']++;
            $this->importLog->updateProgress(0, 1, 0);
            return;
        }

        // Determine transaction type and process
        if ($category === 'Invoice' && $type === 'cash in') {
            $this->processInvoice($date, $name, $mode, $amount, $approvalStatus, $approvalDate, $source, $transactionId, $refId, $device, $comments);
        } elseif ($category === 'Vendor' && $type === 'Purchase') {
            $this->processVendorPurchase($date, $name, $mode, $amount, $source, $transactionId, $refId, $comments);
        } elseif ($category === 'Payment' || $type === 'Vendor Payment') {
            $this->processVendorPayment($date, $name, $mode, $amount, $approvalStatus, $approvalDate, $source, $transactionId, $refId, $device, $comments);
        } elseif ($type === 'cash out' && $category !== 'Payment') {
            $this->processExpense($date, $name, $category, $mode, $amount, $source, $transactionId, $refId, $comments);
        } elseif (stripos($source, 'deposit') !== false || stripos($category, 'deposit') !== false) {
            $this->processDeposit($date, $name, $amount, $source, $transactionId, $comments);
        } else {
            // Unknown transaction type
            Log::warning("Unknown transaction type", [
                'category' => $category,
                'type' => $type,
                'name' => $name,
                'amount' => $amount
            ]);
            $this->stats['skipped']++;
            $this->importLog->updateProgress(0, 1, 0);
        }
    }

    /**
     * Process invoice transaction
     * Dr Cash – Employee / Online Bank → Cr Sales – Invoices
     */
    private function processInvoice($date, $name, $mode, $amount, $approvalStatus, $approvalDate, $source, $transactionId, $refId, $device, $comments)
    {
        $salesAccount = ConfigModel::getSalesRevenueAccount();
        
        if ($name === 'Online') {
            // Online invoice
            $toAccount = ConfigModel::getOnlineBankAccount();
        } else {
            // Employee invoice - check if user exists
            $toAccount = $this->getOrCreateEmployeeAccount($name);
            
            // If employee not matched to user, skip this record
            if (!$toAccount) {
                if (!in_array($name, $this->unmatchedEmployees)) {
                    $this->unmatchedEmployees[] = $name;
                }
                $this->skippedRecords[] = [
                    'type' => 'invoice',
                    'name' => $name,
                    'amount' => $amount,
                    'date' => $date,
                    'reason' => 'Employee not found in user table'
                ];
                $this->stats['skipped']++;
                $this->importLog->updateProgress(0, 1, 0);
                return;
            }
        }

        if (!$salesAccount || !$toAccount) {
            throw new \Exception("Required accounts not found for invoice");
        }

        // Create ledger entry
        $ledger = LedgerModel::create([
            'transaction_date' => $date,
            'transaction_type' => LedgerModel::TYPE_INVOICE,
            'description' => "Invoice: {$name} - {$refId}",
            'from_account_id' => $salesAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => $amount,
            'mode' => $mode,
            'approval_status' => $approvalStatus === 'YES' ? LedgerModel::STATUS_APPROVED : LedgerModel::STATUS_PENDING,
            'approval_date' => $approvalDate,
            'external_source' => $source,
            'external_txn_id' => $transactionId,
            'external_ref_id' => $refId,
            'device' => $device,
            'comments' => $comments,
            'created_by' => auth()->id() ?? 1
        ]);

        // Update account balances (From account decreases, To account increases)
        $salesAccount->current_balance -= $amount; // Revenue account (credit side)
        $salesAccount->save();
        
        $toAccount->current_balance += $amount; // Asset account (debit side)
        $toAccount->save();

        $this->stats['invoices']++;
        $this->importLog->updateProgress(1, 0, 0);
    }

    /**
     * Process vendor purchase
     * Dr Expense – Purchases → Cr Payable – Vendor
     */
    private function processVendorPurchase($date, $vendorName, $mode, $amount, $source, $transactionId, $refId, $comments)
    {
        $vendor = $this->getOrCreateVendor($vendorName);
        $purchaseAccount = AccountModel::getByCode('EXP_PURCHASES');

        if (!$vendor || !$vendor->account || !$purchaseAccount) {
            throw new \Exception("Required accounts not found for vendor purchase");
        }

        // Create ledger entry
        LedgerModel::create([
            'transaction_date' => $date,
            'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
            'description' => "Purchase from {$vendorName}",
            'from_account_id' => $purchaseAccount->id,
            'to_account_id' => $vendor->account->id,
            'amount' => $amount,
            'mode' => $mode,
            'approval_status' => LedgerModel::STATUS_APPROVED,
            'external_source' => $source,
            'external_txn_id' => $transactionId,
            'external_ref_id' => $refId,
            'comments' => $comments,
            'created_by' => auth()->id() ?? 1
        ]);

        // Update balances
        $purchaseAccount->current_balance += $amount; // Expense increases
        $purchaseAccount->save();
        
        $vendor->account->current_balance += $amount; // Liability increases
        $vendor->account->save();

        $this->stats['vendor_purchases']++;
        $this->importLog->updateProgress(1, 0, 0);
    }

    /**
     * Process vendor payment
     * Dr Payable – Vendor → Cr NF Cash / Online Bank
     */
    private function processVendorPayment($date, $vendorName, $mode, $amount, $approvalStatus, $approvalDate, $source, $transactionId, $refId, $device, $comments)
    {
        $vendor = $this->getOrCreateVendor($vendorName);
        
        if ($mode === 'online') {
            $fromAccount = ConfigModel::getOnlineBankAccount();
        } else {
            $fromAccount = ConfigModel::getNFCashAccount();
        }

        if (!$vendor || !$vendor->account || !$fromAccount) {
            throw new \Exception("Required accounts not found for vendor payment");
        }

        // Create ledger entry
        LedgerModel::create([
            'transaction_date' => $date,
            'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
            'description' => "Payment to {$vendorName}",
            'from_account_id' => $vendor->account->id,
            'to_account_id' => $fromAccount->id,
            'amount' => $amount,
            'mode' => $mode,
            'approval_status' => $approvalStatus === 'YES' ? LedgerModel::STATUS_APPROVED : LedgerModel::STATUS_PENDING,
            'approval_date' => $approvalDate,
            'external_source' => $source,
            'external_txn_id' => $transactionId,
            'external_ref_id' => $refId,
            'device' => $device,
            'comments' => $comments,
            'created_by' => auth()->id() ?? 1
        ]);

        // Update balances
        $vendor->account->current_balance -= $amount; // Liability decreases
        $vendor->account->save();
        
        $fromAccount->current_balance -= $amount; // Cash/Bank decreases
        $fromAccount->save();

        $this->stats['vendor_payments']++;
        $this->importLog->updateProgress(1, 0, 0);
    }

    /**
     * Process expense transaction
     * Dr Expense – Category → Cr Cash – Employee
     */
    private function processExpense($date, $employeeName, $category, $mode, $amount, $source, $transactionId, $refId, $comments)
    {
        // Get or create expense account for this category
        $expenseAccount = $this->getOrCreateExpenseAccount($category);
        $employeeAccount = $this->getOrCreateEmployeeAccount($employeeName);

        // If employee not matched to user, skip this record
        if (!$employeeAccount) {
            if (!in_array($employeeName, $this->unmatchedEmployees)) {
                $this->unmatchedEmployees[] = $employeeName;
            }
            $this->skippedRecords[] = [
                'type' => 'expense',
                'name' => $employeeName,
                'amount' => $amount,
                'category' => $category,
                'date' => $date,
                'reason' => 'Employee not found in user table'
            ];
            $this->stats['skipped']++;
            $this->importLog->updateProgress(0, 1, 0);
            return;
        }

        if (!$expenseAccount || !$employeeAccount) {
            throw new \Exception("Required accounts not found for expense");
        }

        // Create ledger entry
        LedgerModel::create([
            'transaction_date' => $date,
            'transaction_type' => LedgerModel::TYPE_EXPENSE,
            'description' => "Expense: {$category} by {$employeeName}",
            'from_account_id' => $expenseAccount->id,
            'to_account_id' => $employeeAccount->id,
            'amount' => $amount,
            'mode' => $mode,
            'approval_status' => LedgerModel::STATUS_APPROVED,
            'external_source' => $source,
            'external_txn_id' => $transactionId,
            'external_ref_id' => $refId,
            'comments' => $comments,
            'created_by' => auth()->id() ?? 1
        ]);

        // Update balances
        $expenseAccount->current_balance += $amount; // Expense increases
        $expenseAccount->save();
        
        $employeeAccount->current_balance -= $amount; // Employee cash decreases
        $employeeAccount->save();

        $this->stats['expenses']++;
        $this->importLog->updateProgress(1, 0, 0);
    }

    /**
     * Process employee deposit
     * Dr NF Cash → Cr Cash – Employee
     */
    private function processDeposit($date, $employeeName, $amount, $source, $transactionId, $comments)
    {
        $nfCash = ConfigModel::getNFCashAccount();
        $employeeAccount = $this->getOrCreateEmployeeAccount($employeeName);

        // If employee not matched to user, skip this record
        if (!$employeeAccount) {
            if (!in_array($employeeName, $this->unmatchedEmployees)) {
                $this->unmatchedEmployees[] = $employeeName;
            }
            $this->skippedRecords[] = [
                'type' => 'deposit',
                'name' => $employeeName,
                'amount' => $amount,
                'date' => $date,
                'reason' => 'Employee not found in user table'
            ];
            $this->stats['skipped']++;
            $this->importLog->updateProgress(0, 1, 0);
            return;
        }

        if (!$nfCash || !$employeeAccount) {
            throw new \Exception("Required accounts not found for deposit");
        }

        // Create ledger entry
        LedgerModel::create([
            'transaction_date' => $date,
            'transaction_type' => LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
            'description' => "Deposit from {$employeeName}",
            'from_account_id' => $employeeAccount->id,
            'to_account_id' => $nfCash->id,
            'amount' => $amount,
            'mode' => LedgerModel::MODE_CASH,
            'approval_status' => LedgerModel::STATUS_APPROVED,
            'external_source' => $source,
            'external_txn_id' => $transactionId,
            'comments' => $comments,
            'created_by' => auth()->id() ?? 1
        ]);

        // Update balances
        $employeeAccount->current_balance -= $amount; // Employee cash decreases
        $employeeAccount->save();
        
        $nfCash->current_balance += $amount; // NF Cash increases
        $nfCash->save();

        $this->stats['deposits']++;
        $this->importLog->updateProgress(1, 0, 0);
    }

    /**
     * Get or create employee cash account
     * Returns NULL if employee not found in user table
     */
    private function getOrCreateEmployeeAccount($employeeName)
    {
        if (isset($this->accountCache[$employeeName])) {
            return $this->accountCache[$employeeName];
        }

        // Normalize and try to find user by name
        $user = $this->findUserByName($employeeName);
        
        // IMPORTANT: Only create account if user exists
        if (!$user) {
            Log::warning("Employee not found in user table", ['name' => $employeeName]);
            $this->accountCache[$employeeName] = null;
            return null;
        }
        
        $account = AccountModel::createEmployeeCashAccount(
            $user->id,
            $user->fullname ?? $user->name
        );

        $this->accountCache[$employeeName] = $account;
        
        return $account;
    }

    /**
     * Get or create expense account
     */
    private function getOrCreateExpenseAccount($category)
    {
        $cacheKey = "EXP_{$category}";
        
        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $code = 'EXP_' . strtoupper(str_replace([' ', '-', '.', '/', '(', ')'], '_', $category));
        $code = substr($code, 0, 50);

        $account = AccountModel::firstOrCreate(
            ['account_code' => $code],
            [
                'account_name' => 'Expense - ' . $category,
                'account_type' => AccountModel::TYPE_EXPENSE,
                'account_category' => AccountModel::CATEGORY_EXPENSE,
                'is_active' => 1,
                'created_by' => auth()->id() ?? 1
            ]
        );

        $this->accountCache[$cacheKey] = $account;
        
        return $account;
    }

    /**
     * Get or create vendor
     */
    private function getOrCreateVendor($vendorName)
    {
        if (isset($this->vendorCache[$vendorName])) {
            return $this->vendorCache[$vendorName];
        }

        $vendor = VendorModel::getOrCreateVendor($vendorName, auth()->id() ?? 1);
        
        $this->vendorCache[$vendorName] = $vendor;
        
        return $vendor;
    }

    /**
     * Find user by name with normalization
     */
    private function findUserByName($name)
    {
        if (isset($this->userCache[$name])) {
            return $this->userCache[$name];
        }

        // Normalize name: remove suffixes, clean up
        $cleanName = $this->normalizeEmployeeName($name);

        // Try exact match first
        $user = UserModel::where('name', $cleanName)
                        ->orWhere('username', $cleanName)
                        ->orWhere('fullname', $cleanName)
                        ->first();

        // Try partial match if exact fails
        if (!$user) {
            $user = UserModel::where('name', 'LIKE', "%{$cleanName}%")
                            ->orWhere('username', 'LIKE', "%{$cleanName}%")
                            ->orWhere('fullname', 'LIKE', "%{$cleanName}%")
                            ->first();
        }

        $this->userCache[$name] = $user;
        
        return $user;
    }
    
    /**
     * Normalize employee name for matching
     */
    private function normalizeEmployeeName($name)
    {
        // Remove common suffixes and clean up
        $name = trim($name);
        
        // Remove suffixes like "- indrive", "- Indri", etc.
        $patterns = [
            '/ - indrive$/i',
            '/ - Indri$/i',
            '/ - InDrive$/i',
            '/ \(.*\)$/',  // Remove anything in parentheses at end
        ];
        
        foreach ($patterns as $pattern) {
            $name = preg_replace($pattern, '', $name);
        }
        
        return trim($name);
    }

    /**
     * Parse date from various formats
     */
    private function parseDate($dateString)
    {
        if (empty($dateString)) {
            return now();
        }

        try {
            return Carbon::parse($dateString);
        } catch (\Exception $e) {
            return now();
        }
    }

    /**
     * Parse amount
     */
    private function parseAmount($amountString)
    {
        // Remove any non-numeric characters except decimal point
        $cleaned = preg_replace('/[^0-9.]/', '', $amountString);
        
        return floatval($cleaned);
    }

    /**
     * Get import statistics
     */
    public function getStats()
    {
        return $this->stats;
    }
}

