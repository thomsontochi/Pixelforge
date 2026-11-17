<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mirrors Pixelforge's own limits so users get a clear message before anything is sent.
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(self::SIZES)],
            'quality' => ['required', Rule::in(self::QUALITIES)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ignore empty reference-image inputs from the form.
        $this->merge([
            'reference_images' => array_values(array_filter(
                (array) $this->input('reference_images', []),
                fn ($url) => is_string($url) && trim($url) !== '',
            )),
        ]);
    }
}
