<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_customer';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'phone',
        'first_name',
        'last_name',
        'company',
        'email',
        'address1',
        'address2',
        'city',
        'province',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'external_customer_ids',
        'first_order_date',
        'last_order_date',
        'total_orders',
        'total_spent',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'external_customer_ids' => 'json',
        'total_spent' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
        'total_orders' => 'integer'
    ];

    // Relationships
    public function orders(): HasMany
    {
        return $this->hasMany(OrderModel::class, 'customer_id');
    }

    // Helper methods
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function addExternalCustomerId(string $platform, string $externalId): void
    {
        $ids = $this->external_customer_ids ?? [];
        $ids[$platform] = $externalId;
        $this->external_customer_ids = $ids;
        $this->save();
    }

    public function getExternalCustomerId(string $platform): ?string
    {
        return $this->external_customer_ids[$platform] ?? null;
    }

    // Mutators to format datetime values for MySQL
    public function setFirstOrderDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['first_order_date'] = null;
            return;
        }
        
        try {
            // Parse the date and convert to local time for MySQL storage
            $date = \Carbon\Carbon::parse($value);
            // Convert to local time (remove timezone offset) for MySQL storage
            $this->attributes['first_order_date'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['first_order_date'] = null;
        }
    }
    
    public function setLastOrderDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['last_order_date'] = null;
            return;
        }
        
        try {
            // Parse the date and convert to local time for MySQL storage
            $date = \Carbon\Carbon::parse($value);
            // Convert to local time (remove timezone offset) for MySQL storage
            $this->attributes['last_order_date'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['last_order_date'] = null;
        }
    }

    /**
     * Get the first order date as a Carbon instance (preserves original timezone)
     */
    public function getFirstOrderDateAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        if ($value instanceof \Carbon\Carbon) {
            return $value;
        }
        
        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Get the last order date as a Carbon instance (preserves original timezone)
     */
    public function getLastOrderDateAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        if ($value instanceof \Carbon\Carbon) {
            return $value;
        }
        
        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Find or create customer by phone number
     * Update first/last order dates and statistics
     */
    public static function findOrCreateByPhone(string $phone, array $orderData, string $orderDate, float $orderTotal, bool $isUpdate = false): self
    {
        if (!$phone) {
            throw new \InvalidArgumentException('Phone number is required');
        }

        $customer = static::where('phone', $phone)->first();
        
        if (!$customer) {
            // Create new customer
            $customer = static::create([
                'phone' => $phone,
                'first_name' => $orderData['address_first_name'] ?? null,
                'last_name' => $orderData['address_last_name'] ?? null,
                'company' => $orderData['address_company'] ?? null,
                'email' => $orderData['address_email'] ?? null,
                'address1' => $orderData['address_line1'] ?? null,
                'address2' => $orderData['address_line2'] ?? null,
                'city' => $orderData['address_city'] ?? null,
                'province' => $orderData['address_province'] ?? null,
                'postal_code' => $orderData['address_postal_code'] ?? null,
                'country' => $orderData['address_country'] ?? 'Pakistan',
                'first_order_date' => $orderDate,
                'last_order_date' => $orderDate,
                'total_orders' => 1,
                'total_spent' => $orderTotal,
                'created_by' => auth()->id()
            ]);

            // Add external customer ID if provided
            if (isset($orderData['external_customer_id']) && isset($orderData['external_source'])) {
                $customer->addExternalCustomerId($orderData['external_source'], $orderData['external_customer_id']);
            }
            } else {
            // Update existing customer
            $updates = [];
            
            // Update order dates
            if (!$customer->first_order_date || $orderDate < $customer->first_order_date) {
                $updates['first_order_date'] = $orderDate;
            }
            if (!$customer->last_order_date || $orderDate > $customer->last_order_date) {
                $updates['last_order_date'] = $orderDate;
            }
            
            // Update statistics (only increment for new orders, not updates)
            if (!$isUpdate) {
                $updates['total_orders'] = $customer->total_orders + 1;
                $updates['total_spent'] = $customer->total_spent + $orderTotal;
            }
            $updates['updated_by'] = auth()->id();
            
            // Update contact info if this is the most recent order
            if (!$customer->last_order_date || $orderDate >= $customer->last_order_date) {
                $updates['email'] = $orderData['address_email'] ?? $customer->email;
                $updates['first_name'] = $orderData['address_first_name'] ?? $customer->first_name;
                $updates['last_name'] = $orderData['address_last_name'] ?? $customer->last_name;
                $updates['company'] = $orderData['address_company'] ?? $customer->company;
                $updates['address1'] = $orderData['address_line1'] ?? $customer->address1;
                $updates['address2'] = $orderData['address_line2'] ?? $customer->address2;
                $updates['city'] = $orderData['address_city'] ?? $customer->city;
                $updates['province'] = $orderData['address_province'] ?? $customer->province;
                $updates['postal_code'] = $orderData['address_postal_code'] ?? $customer->postal_code;
                $updates['country'] = $orderData['address_country'] ?? $customer->country;
            }
            
            $customer->update($updates);

            // Update external customer ID if provided
            if (isset($orderData['external_customer_id']) && isset($orderData['external_source'])) {
                $customer->addExternalCustomerId($orderData['external_source'], $orderData['external_customer_id']);
            }
        }
        
        return $customer;
    }

    /**
     * Recalculate customer statistics based on actual orders
     * Useful for fixing any inconsistencies
     */
    public function recalculateStatistics(): void
    {
        $orders = $this->orders()->orderBy('order_date')->get();
        
        if ($orders->count() > 0) {
            $this->update([
                'first_order_date' => $orders->first()->order_date,
                'last_order_date' => $orders->last()->order_date,
                'total_orders' => $orders->count(),
                'total_spent' => $orders->sum('total_price'),
                'updated_by' => auth()->id()
            ]);
        } else {
            $this->update([
                'first_order_date' => null,
                'last_order_date' => null,
                'total_orders' => 0,
                'total_spent' => 0,
                'updated_by' => auth()->id()
            ]);
        }
    }
}