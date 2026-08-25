<?php

namespace App\Http\Requests;

use App\Rules\SafeRasterImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreImageRequest extends FormRequest
{
    /**
     * Four independent checks, because any one of them can be fooled on its own:
     * the client extension, the extension implied by the sniffed mime type, the
     * sniffed mime type itself, and finally the actual decoded image header.
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                File::types(config('images.allowed_extensions'))
                    ->max(config('images.max_upload_kilobytes')),
                'extensions:'.implode(',', config('images.allowed_extensions')),
                'mimetypes:'.implode(',', config('images.allowed_mime_types')),
                new SafeRasterImage,
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $mb = round(((int) config('images.max_upload_kilobytes')) / 1024, 2);

        return [
            'image.required' => 'An image file is required under the "image" field.',
            'image.max' => "The image may not be larger than {$mb} MB.",
            'image.mimetypes' => 'Only PNG and JPEG images are accepted.',
            'image.extensions' => 'Only .png, .jpg and .jpeg files are accepted.',
        ];
    }

    public function attributes(): array
    {
        return ['image' => 'image'];
    }
}
