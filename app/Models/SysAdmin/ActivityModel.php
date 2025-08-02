<?php
namespace App\Models\SysAdmin;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable; 
use Illuminate\Support\Facades\DB;

class ActivityModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_user_activity';
    protected $primaryKey = 'uact_id';
    // Rest omitted for brevity 
    public $timestamps = false; 

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */


    protected $fillable = [
        'uact_id',
        'session_id',
        'user_id',
        'menu_id',
        'created_at'
    ];

    protected $map = [
        'uact_id',
        'session_id',
        'user_id',
        'menu_id',
        'created_at'
    ];

    function List() //All record
    {
         return $activity = ActivityModel::all();
    } 

    function Get($id) //Single record
    {
        $data  = DB::table('t_sys_user_activity')->where('uact_id', $id)->get()->first();
        return $data;
    }

    function Store($data)
    { 
        $model = new ActivityModel;
    
        if($data['uact_id'] > 0){
            $model = ActivityModel::find($data['uact_id']); 
        } 
        $model->session_id = $data['session_id'];
        $model->user_id = $data['user_id'];      
        $model->is_active = $data['is_active'];
        $model->menu_id = $data['menu_id'];      
        $model->created_at = $data['created_at'];
        
        $model->save();
    }
    
    function deleteActivity($id) //DELETE
    {
        ActivityModel::find($id)->delete();
    }  
}
