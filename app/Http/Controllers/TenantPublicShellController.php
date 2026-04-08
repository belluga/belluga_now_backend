<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicWeb\FlutterWebShellRenderer;
use App\Application\PublicWeb\PublicWebMetadataService;
use Illuminate\Http\Response;

class TenantPublicShellController extends Controller
{
    public function __construct(
        private readonly PublicWebMetadataService $metadataService,
        private readonly FlutterWebShellRenderer $shellRenderer,
    ) {}

    public function accountProfile(string $accountProfileSlug): Response
    {
        return response(
            $this->shellRenderer->render(
                $this->metadataService->accountProfileMetadata($accountProfileSlug)
            ),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    public function event(string $eventSlug): Response
    {
        return response(
            $this->shellRenderer->render(
                $this->metadataService->eventMetadata($eventSlug)
            ),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }
}
