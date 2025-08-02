<?php

namespace App\Traits;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use \Carbon\Carbon;

trait Utilities
{

    protected string $fileSource = "";
    protected string $uploadDir = "";
    protected string $oldFile = "";

    protected function strOnly($str)
    {
        return preg_replace('/[0-9]+/', '', Str::of($str)->trim());
    }
    protected function numOnly($str)
    {
        return preg_replace('/[^0-9]/', '', Str::of($str)->trim());
    }


    protected function buildTree(array &$elements, $parentId = 0)
    {
        $branch = array();

        foreach ($elements as $element) {
            if ($element['parent_id'] == $parentId) {
                $children = $this->buildTree($elements, $element['id']);
                if ($children) {
                    $element['submenu'] = $children;
                }
                $branch[$element['id']] = $element;
                unset($elements[$element['id']]);
            }
        }
        return $branch;
    }

    protected function base64ImageUpload()
    {

        if ($this->fileSource === "") {
            return "";
        }
        try {
            $image_parts = explode(";base64,", $this->fileSource);
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1];
            $image_base64 = base64_decode($image_parts[1]);
            $file =  $this->uploadDir . uniqid() . '.' . $image_type;
            Storage::disk('public')->put($file, $image_base64);      

            if ($this->oldFile != "") {
                Storage::disk('public')->delete($this->oldFile);
            }
            return $file;
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }

    protected function removeUploadedImage()
    {
        if ($this->oldFile === "") {
            return "";
        }
        if ($this->oldFile != "") {
            Storage::disk('public')->delete($this->oldFile);
        }
    }


    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  string  $date
     * @return string
     */
    protected function datetoDB(string $date)
    {
        $carbon = new  Carbon($date);
        return $carbon->format("Y-m-d");
    }
}
