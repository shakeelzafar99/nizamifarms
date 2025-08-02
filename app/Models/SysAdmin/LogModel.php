<?php

namespace App\Models\SysAdmin;

use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class LogModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_user_log';
    protected $primaryKey = 'ulog_id';
    // Rest omitted for brevity 
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */


    protected $fillable = [
        'ulog_id',
        'user_id',
        'is_active',
        'session_id',
        'terminal',
        'created_at',
        'updated_at',
        'status',
    ];

    protected $map = [
        'ulog_id',
        'user_id',
        'is_active',
        'session_id',
        'terminal',
        'created_at',
        'updated_at',
        'status',
    ];

    function List() //All record
    {
        return $log = LogModel::all();
    }

    function Get($id) //Single record
    {
        $data  = LogModel::where('ulog_id', $id)->get()->first();
        return $data;
    }

    function Store($data)
    {
        $model = new LogModel;

        if ($data['ulog_id'] > 0) {

            $model = LogModel::find($data['ulog_id']);
        }

        $model->ulog_id = $data['ulog_id'];
        $model->user_id = $data['user_id'];
        $model->is_active = $data['is_active'];
        $model->session_id = $data['session_id'];
        $model->terminal = $data['terminal'];
        $model->created_at = $data['created_at'];

        $model->save();
    }

    function deleteLog($id) //DELETE
    {
        LogModel::find($id)->delete();
    }
}
