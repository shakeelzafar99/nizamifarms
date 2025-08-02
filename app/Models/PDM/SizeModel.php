<?php

namespace App\Models\PDM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;


class SizeModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_pdm_size';
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
        'size_desc',
        'size_desc_alt',
        'size_num',
        'size',
        'diameter',
        'section_width_inch',
        'section_width_mm',
        'profile',
        'wheel',
        'wheel_wide',
        'sidewall',
        'circum',
        'revs_mile',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'size_desc',
        'size_desc_alt',
        'size_num',
        'size',
        'diameter',
        'section_width_inch',
        'section_width_mm',
        'profile',
        'wheel',
        'wheel_wide',
        'sidewall',
        'circum',
        'revs_mile',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function ListByStatus($status)
    {
        $this->data = SizeModel::where("is_active", $status)->orderBy("size_num")->get()->toArray();
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            if ($this->column === "size_desc") {
                $this->column = "size_num";
            }
            $this->data = SizeModel::where($this->filter)->whereLike(['size_desc', 'size_num', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = SizeModel::where($this->filter)->whereLike(['size_desc', 'size_num', 'description'], $this->searchTerm)->orderBy("size_num", "asc")->get()->toArray();
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = SizeModel::find($id)->toArray();
        return $this->setResponse();
    }
    function GetBySizeNum($size_num) //Single  
    {
        $size = $this->numOnly($size_num);
        $this->data =  SizeModel::where("size_num", "=", $size)->get()->toArray();
        return $this->setResponse();
    }



    function Autocomplete($value)
    {
        $this->keyword = $this->numOnly($value);
        try {
            $this->data =  SizeModel::where("is_active", "Y")->where("size_num", "like", '%' . $this->keyword . '%')->get()->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }


    function Store($data)
    {
        //Model Initialized 
        $model = new SizeModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'size_desc.required' => 'Please enter size desc',
            'size_desc.unique' => 'The size has already been taken.',
            'size_desc.max' => 'The size must be less then 50 character.',
            'size_desc_alt.required' => 'Please enter size desc alt',
            'size.required' => 'Please enter size',
            'diameter.required' => 'Please enter diameter',
            'section_width_mm.required' => 'Please enter width',
            'profile.required' => 'Please enter profile',
            'wheel.required' => 'Please enter wheel',
            'sidewall.required' => 'Please enter sidewall',
            'circum.required' => 'Please enter circum',
            'revs_mile.required' => 'Please enter revs_mile',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'size_desc' => 'required|max:50|unique:t_pdm_size,size_desc',
            'size_desc_alt' => 'required',
            'size' => 'required',
            'diameter' => 'required',
            'section_width_mm' => 'required',
            'profile' => 'required',
            'wheel' => 'required',
            'sidewall' => 'required',
            'circum' => 'required',
            'revs_mile' => 'required',
        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = SizeModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['size_desc'] =  $this->rules['size_desc'] . ',' . $model->id;
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }

        $model->size_desc   =  $this->data['size_desc'];
        $model->size_desc_alt   =  $this->data['size_desc_alt'];
        $model->size_num    =  $this->numOnly($this->data['size_desc']);
        $model->size   =  $this->data['size'];
        $model->diameter            =  $this->data['diameter'];
        $model->section_width_mm   =  $this->data['section_width_mm'];
        $model->section_width_inch   =  round($this->data['section_width_mm'] / 25.4, 1);
        $model->profile   =  $this->data['profile'];
        $model->wheel   =  $this->data['wheel'];
        $model->wheel_wide   =  $this->data['wheel_wide'];
        $model->sidewall   =  $this->data['sidewall'];
        $model->circum   =  $this->data['circum'];
        $model->revs_mile   =  $this->data['revs_mile'];


        $model->description =  $model->size_desc . ' tires have a diameter of ' . $model->diameter . '", a section width of ' . $model->section_width_inch . '", and a wheel diameter of ' . $model->size . '". The circumference is ' . $model->circum . '" and they have ' . $model->revs_mile . ' revolutions per mile.';
        if ($model->wheel_wide != "") {
            $model->description .= 'Generally they are approved to be mounted on ' . $model->wheel_wide . '" wide wheels.';
        }

        if (!$this->dataValidation()) {
            return $this->setResponse();
        }

        if ($model->save()) {
            $this->data["id"] = $model->id;
            $this->trxnCompleted();
            return $this->setResponse();
        }

        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {

        if (SizeModel::find($id)->delete()) {

            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
