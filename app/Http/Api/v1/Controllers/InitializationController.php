<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\DataObjects\Branding\LogoSettings;
use App\DataObjects\Branding\PwaIcon;
use App\DataObjects\Branding\ThemeDataSettings;
use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class InitializationController extends Controller
{
    private bool $_isInitialized {
        get {
            return LandlordUser::query()->exists()
                || Tenant::query()->exists()
                || Landlord::query()->exists();
        }
    }

    public function isInitialized(): JsonResponse {

        if ($this->_isInitialized) {
            return response()->json(
                [
                    "message" => "Sistema já inicializado",
                    "errors" => [
                        "user" => ["Sistema já inicializado"]
                    ]],
                200);
        }

        return response()->json(status: 403);
    }

    public function initialize(InitializeRequest $request): JsonResponse
    {
        if ($this->_isInitialized) {
            return response()->json(["success" => false, "message" => "System already initialized."], 403);
        }

        $validated = $request->validated();

        DB::connection('landlord')->beginTransaction();
        try {
            $landlord = Landlord::create(['name' => $validated['landlord']['name']]);

            // Monta os 3 payloads aninhados
            $logoSettingsPayload = $this->handleLogoUploads($request, $validated['branding_data']['logo_settings']);
            $pwaIconPayload = $this->handlePwaIconUploads($request);
            $themeDataPayload = $validated['branding_data']['theme_data_settings'];

            // Cria o documento embutido com a estrutura aninhada correta
            $landlord->brandingData()->create([
                'theme_data_settings' => ThemeDataSettings::fromArray($themeDataPayload)->toArray(),
                'logo_settings'       => LogoSettings::fromArray($logoSettingsPayload)->toArray(),
                'pwa_icon'            => PwaIcon::fromArray($pwaIconPayload)->toArray(),
            ]);

            $new_tenant = Tenant::create([
                "name" => $validated['tenant']["name"],
                "subdomain" => $validated['tenant']["subdomain"]
            ]);

            if (isset($validated['tenant']["domains"])) {
                $new_tenant->addDomains($validated['tenant']["domains"]);
            }

            // O resto da sua lógica para criar roles e usuários continua a mesma
            $new_tenant->makeCurrent();
            $admin_role = LandlordRole::create([...$validated['role']]);

            $admin_tenant_template = $new_tenant->roleTemplates()->create(
                [
                    "name" => "Admin",
                    'description' => 'Administrador',
                    "permissions" => ["*"]
                ]
            );

            $new_user = LandlordUser::create([
                "name" => $validated['user']['name'],
                "emails" => $validated['user']['emails'],
                "password" => $validated['user']['password']
            ]);

            $admin_role->users()->save($new_user);

            $new_user->tenantRoles()->create([
                ...$admin_tenant_template->attributesToArray(),
                'tenant_id' => $new_tenant->id,
            ]);

            $token = $new_user->createToken("Initialization Token")->plainTextToken;

            $new_tenant->forgetCurrent();
            DB::connection('landlord')->commit();

        } catch (\Throwable $e) {
            print_r($e->getMessage());
            DB::connection('landlord')->rollBack();
            throw $e;
        }

        return response()->json([
            "data" => [
                "user" => [
                    "token" => $token,
                    ...$new_user->toArray()
                ],
                "tenant" => [
                    ...$new_tenant->attributesToArray(),
                    "role_admin_id" => $admin_tenant_template->id,
                ],
                "role" => $admin_role->toArray(),
                "landlord" => $landlord->toArray(),
            ]
        ], 201);
    }

    /**
     * Handles standard logo file uploads.
     */
    private function handleLogoUploads(InitializeRequest $request, array $logoSettingsData): array
    {
        $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri'];

        foreach ($logoKeys as $key) {
            // Use Str::snake to check the original snake_case name in the multipart form data
            $fileKey = 'branding_data.logo_settings.' . Str::snake($key);
            if ($request->hasFile($fileKey)) {
                $baseName = substr(Str::snake($key), 0, -4);
                // The key for the final array is camelCase
                $logoSettingsData[$key] = $this->storeFile($request->file($fileKey), 'landlord/logos', $baseName);
            }
        }
        return $logoSettingsData;
    }

    /**
     * Handles the single PWA source icon upload and generates the variants array.
     */
    private function handlePwaIconUploads(InitializeRequest $request): array
    {
        // Define the default empty structure
        $pwaData = [
            'source_uri' => '',
            'icon192_uri' => '',
            'icon512_uri' => '',
            'icon_maskable512_uri' => '',
        ];

        // Check for the single file at branding_data.pwa_icon
        if (!$request->hasFile('branding_data.pwa_icon')) {
            return $pwaData;
        }

        $file = $request->file('branding_data.pwa_icon');
        $pwaData['source_uri'] = $this->storeFile($file, 'landlord/logos', 'pwa_icon_source');

        $baseDir = 'landlord/pwa';
        Storage::disk('public')->makeDirectory($baseDir);
        $sourcePath = $file->getRealPath();

        $pwaData['icon192_uri'] = $this->generatePwaVariant($sourcePath, "{$baseDir}/icon-192x192.png", 192);
        $pwaData['icon512_uri'] = $this->generatePwaVariant($sourcePath, "{$baseDir}/icon-512x192.png", 512);
        $pwaData['icon_maskable512_uri'] = $this->generatePwaVariant($sourcePath, "{$baseDir}/icon-maskable-512x512.png", 512, 410);

        return $pwaData;
    }

    /**
     * Stores a file and returns its public URL.
     */
    private function storeFile(UploadedFile $file, string $directory, string $baseName): string
    {
        $extension = $baseName === 'favicon' ? 'ico' : $file->getClientOriginalExtension();
        $fileName = "{$baseName}.{$extension}";
        $path = $file->storeAs($directory, $fileName, 'public');
        return Storage::disk('public')->url($path);
    }

    /**
     * Generates a PWA icon variant with a transparent canvas.
     */
    private function generatePwaVariant(string $sourcePath, string $targetPath, int $canvasSize, ?int $contentSize = null): string
    {
        $contentSize = $contentSize ?? $canvasSize;

        $canvas = Image::create($canvasSize, $canvasSize);
        $content = Image::read($sourcePath)->contain($contentSize, $contentSize);
        $canvas->place($content, 'center');

        $canvas->save(Storage::disk('public')->path($targetPath));

        return Storage::disk('public')->url($targetPath);
    }
}
