<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,heic', 'max:30720'],
            'toilet_id' => ['nullable', 'integer'],
            'exif' => ['nullable', 'string'],
            'fixed_geo' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.uploaded' => 'The file failed to upload. It likely exceeds the server upload limit (upload_max_filesize / post_max_size).',
            'file.max' => 'The file size must not exceed 30MB.',
            'file.mimes' => 'The file must be a file of type: jpg, jpeg, heic.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $file = $this->file('file');
            if (! $file || ! $file->isValid()) {
                $errorCode = $file ? $file->getError() : ($_FILES['file']['error'] ?? 'no file in $_FILES');
                $errorMsg = $file ? $file->getErrorMessage() : 'No file uploaded';
                \Illuminate\Support\Facades\Log::warning('Upload file failure diagnostics', [
                    'error_code' => $errorCode,
                    'error_message' => $errorMsg,
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
                    'files_global' => $_FILES ?? [],
                ]);
            }
        });
    }
}
