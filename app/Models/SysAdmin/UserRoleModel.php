<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel; 

class UserRoleModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_user_role';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
        'id',
        'role_id',
        'user_id'
    ];

    protected $map = [
        'id',
        'role_id',
        'user_id'
    ]; 
      
}
