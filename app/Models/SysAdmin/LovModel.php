<?php
namespace App\Models\SysAdmin; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LovModel extends Model
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_lov'; 
    // Rest omitted for brevity 
    public $timestamps = false; 

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */


    protected $fillable = [
        'lov_desc',
        'lov_value',
        'lov_group',
        'sort',
    ];

    protected $map = [
        'lov_desc',
        'lov_value',
        'lov_group',
        'sort',
    ];

    function List($group) //All record
    {
         
        return LovModel::where('lov_group', $group)
               ->orderBy('sort') 
               ->get(); 
    } 
 
      
}
