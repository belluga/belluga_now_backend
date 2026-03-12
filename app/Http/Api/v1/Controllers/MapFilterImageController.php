<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Media\MapFilterImageStorageService;
use App\Http\Api\v1\Requests\MapFilterImageUploadRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class MapFilterImageController extends Controller
{
    public function __construct(
        private readonly MapFilterImageStorageService $storageService,
    ) {}

    public function store(MapFilterImageUploadRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $image = $request->file('image');
        if (! $image instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'image' => ['The image field is required.'],
            ]);
        }

        $payload = $this->storageService->store(
            key: (string) ($validated['key'] ?? ''),
            image: $image,
            baseUrl: $request->getSchemeAndHttpHost(),
        );

        return response()->json([
            'message' => 'Map filter image uploaded successfully.',
            'data' => $payload,
        ]);
    }
}
