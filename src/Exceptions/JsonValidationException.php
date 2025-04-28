<?php

namespace Visnsstudio\VisnsPackages\Exceptions;

use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;

class JsonValidationException extends ValidationException
{
    /**
     * Create a new JSON response for validation errors.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function render($request)
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'errors' => $this->errors(),
        ], $this->status);
    }
}
