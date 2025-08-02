<?php

namespace App\Models\PDM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Str;
use DB;

class PartModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_pdm_part';
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
        'company_id',
        'part_name',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'company_id',
        'part_name',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];





    function Autocomplete($company_id, $branch_id, $value, $value1, $value2)
    {
        $this->keyword =  Str::of($value)->trim()->upper();
        try {
            if ($value1 === "STOCK") {

                $this->data = PartModel::join('t_scm_stock', 't_scm_stock.part_id', '=', 't_pdm_part.id')
                    ->where("t_pdm_part.is_active", "Y")
                    ->where("t_pdm_part.part_name", "like", '%' . $this->keyword . '%')
                    ->where("t_scm_stock.stock_value", ">", 0)
                    ->where("t_scm_stock.company_id",  $company_id)
                    ->where("t_scm_stock.branch_id",  $branch_id)
                    ->select('t_pdm_part.*', 't_scm_stock.stock_value', 't_scm_stock.sale_price')->get()->toArray();
            } else {
                $this->data =  PartModel::where("is_active", "Y")->where("description", "like", '%' . $this->keyword . '%')->get()->toArray();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }


    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = PartModel::where($this->filter)->whereLike(['part_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = PartModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new PartModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'company_id.required' => 'Please enter company',
            'part_name.required' => 'Please enter part name',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'company_id' => 'required',
            'part_name' => 'required|max:50',

        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = PartModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }

        $model->company_id = $this->data['company_id'];
        $model->part_name = $this->data['part_name'];
        $model->description = $this->data['description'];

        if (!$this->dataValidation()) {
            return $this->setResponse();
        }

        if ($model->save()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (PartModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
