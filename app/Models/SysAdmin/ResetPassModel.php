<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;

class ResetPassModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_user_password_resets';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [

        'id',
        'email',
        'token',
        'created_at'
    ];

    protected $map = [
        'id',
        'email',
        'token',
        'created_at'
    ];
}
