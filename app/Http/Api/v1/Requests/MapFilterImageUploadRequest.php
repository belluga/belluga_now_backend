<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MapFilterImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'image' => [
                'required',
                'file',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:2048',
                'dimensions:ratio=1/1,max_width=1024,max_height=1024',
            ],
        ];
    }
}

