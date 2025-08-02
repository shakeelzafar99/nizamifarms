<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class PaymentMethodModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_payment_method';
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
        'name',
        'description',
        'is_active',
        'is_ref_no_req',
        'is_clearing',
        'is_allowed_walk_in_cust',
        'is_allowed_acc_cust',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'

    ];

    protected $map = [
        'id',
        'name',
        'description',
        'is_active',
        'is_ref_no_req',
        'is_clearing',
        'is_allowed_walk_in_cust',
        'is_allowed_acc_cust',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];
    function ListByStatus($status)
    {
        $this->data = PaymentMethodModel::where("is_active", $status)->orderBy("name")->get()->toArray();
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = PaymentMethodModel::where($this->filter)->whereLike([
            'name',
            'description',
        ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Filter($data) //All record
    {
        $this->listRequest($data);
        $this->data = PaymentMethodModel::where($this->filter)->orderBy("name", "asc")->get()->toArray();
        return $this->setResponse();
    }



    function Get($id) //Single  
    {
        $this->data = PaymentMethodModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        try {
            //Model Initialized 
            $model = new PaymentMethodModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'name.required' => 'Please enter name',
                'name.unique' => 'The name has already been taken.',
            ];
            //Set Data Validation Rules
            $this->rules = [
                'name' => 'required|max:50|unique:t_sys_payment_method,name',
            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = PaymentMethodModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $this->rules['name'] =  $this->rules['name'] . ',' . $model->id;
                $model->updated_by = $this->CurrentUserId();
                $model->is_active   =  $this->data['is_active'];
            } else {
                $model->created_by =  $this->CurrentUserId();
            }
            $model->type = "NA";
            $model->name = $this->data['name'];
            $model->description = $this->data['description'];
            $model->is_ref_no_req   =  $this->data['is_ref_no_req'];
            $model->is_clearing   =  $this->data['is_clearing'];
            $model->is_allowed_walk_in_cust   =  $this->data['is_allowed_walk_in_cust'];
            $model->is_allowed_acc_cust   =  $this->data['is_allowed_acc_cust'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {
                $this->trxnCompleted();
                return $this->setResponse();
            }
        } catch (QueryException $e) {
            dd($e->getMessage());
            // Handle database-related exceptions
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (ModelNotFoundException $e) {
            dd($e->getMessage());
            // Handle model not found exception
            return response()->json(['error' => 'User not found'], 404);
        } catch (Exception $e) {
            dd($e->getMessage());
            // Handle general exceptions
            return response()->json(['error' => 'Something went wrong: ' . $e->getMessage()], 500);
        }

        // $this->intlSrvError();
        // return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (PaymentMethodModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
