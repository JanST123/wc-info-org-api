<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreToiletPropertiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            '*' => ['array'],
            '*.type' => ['required', 'string', 'in:address,website,euro_key,comment,place_opening_hours,is_unisex,is_gender_separated,has_wheelchair_access,has_changing_table,accessible_outside_opening_times,public_accessible,storage_space'],
            '*.value' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.type.required' => 'Field `type` is required in row :index',
            '*.type.in' => 'Field `type` allows only valid property types in row :index',
            '*.value.required' => 'Field `value` is required in row :index',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->all();
            if (is_array($rows)) {
                foreach ($rows as $index => $row) {
                    if (is_array($row) && isset($row['type']) && $row['type'] === 'euro_key') {
                        if (isset($row['value']) && ! in_array($row['value'], ['yes', 'no', 'unknown'], true)) {
                            $validator->errors()->add("{$index}.value", "Field `value` allows only values: \"yes\", \"no\", \"unknown\" for euro_key in row {$index}");
                        }
                    }
                    if (is_array($row) && isset($row['type']) && $row['type'] === 'storage_space') {
                        if (isset($row['value']) && ! in_array($row['value'], ['none', 'little', 'much'], true)) {
                            $validator->errors()->add("{$index}.value", "Field `value` allows only values: \"none\", \"little\", \"much\" for storage_space in row {$index}");
                        }
                    }
                }
            }
        });
    }
}
