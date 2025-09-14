<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\DataObjects\Branding\BrandingData;
use App\DataObjects\Branding\ColorSchemeData;
use App\DataObjects\Branding\LogoSettings;
use App\DataObjects\Branding\ThemeDataSettings;
use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class InitializationController extends Controller
{

    public function isInitialized(): JsonResponse {
        $is_initialized = LandlordUser::query()->exists()
            || Tenant::query()->exists()
            || Landlord::query()->exists();

        if ($is_initialized) {
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

    public function initialize(InitializeRequest $request): JsonResponse {

        $validated = $request->validated();

        $is_initialized = LandlordUser::query()->exists()
            || Tenant::query()->exists()
            || Landlord::query()->exists();

        // --- ✅ CHECAGEM ATUALIZADA ---
        // Adicionamos a verificação do Landlord para garantir que a inicialização não ocorra
        if ($is_initialized) {
            return response()->json([
                "success" => false,
                "message" => "Sistema já inicializado",
            ], 403);
        }

        DB::connection('landlord')->beginTransaction();
        try {
            $brandingDataFromRequest = $validated['branding_data'];
            $logoSettingsFromRequest = $brandingDataFromRequest['logo_settings'] ?? [];
            $uploadedLogoPaths = [];
            $pwaSourceTempPath = null;
            $pwaIconData = [];

            // Handle file uploads for logos (store and collect public URLs)
            $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri', 'pwa_icon'];
            foreach ($logoKeys as $key) {
                if ($request->hasFile("branding_data.logo_settings.$key")) {
                    $file = $request->file("branding_data.logo_settings.$key");

                    // Decide paths: keep consistent with landlord storage layout
                    $directory = 'landlord/logos';
                    $baseName = str_ends_with($key, '_uri') ? substr($key, 0, -4) : $key;
                    $extension = $key === 'favicon_uri' ? 'ico' : ($file->getClientOriginalExtension() ?: 'png');
                    $fileName = "{$baseName}.{$extension}";
                    $path = "{$directory}/{$fileName}";

                    if (Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                    }

                    $file->storeAs($directory, $fileName, 'public');
                    $publicUrl = Storage::disk('public')->url($path);

                    if ($key === 'pwa_icon') {
                        // store source URL and keep temp path for variants
                        $uploadedLogoPaths[$key] = $publicUrl;
                        $pwaSourceTempPath = $file->getRealPath();
                        $pwaIconData['source_uri'] = $publicUrl;
                    } else {
                        $uploadedLogoPaths[$key] = $publicUrl;
                    }
                }
            }

            // Generate PWA icon sizes without cropping and collect URLs
            if ($pwaSourceTempPath) {
                $baseDir = 'landlord/branding/pwa';
                Storage::disk('public')->makeDirectory($baseDir);

                $icon192 = "{$baseDir}/icon-192x192.png";
                $icon512 = "{$baseDir}/icon-512x512.png";
                $iconMaskable512 = "{$baseDir}/icon-maskable-512x512.png";

                // 192x192: contain within 192 and center on a transparent 192 canvas
                $tmp192 = Image::read($pwaSourceTempPath)->contain(192, 192);
                $canvas192 = Image::create(192, 192);
                $canvas192->place($tmp192, 'center')
                    ->save(Storage::disk('public')->path($icon192));
                $pwaIconData['icon_192_uri'] = Storage::disk('public')->url($icon192);

                // 512x512: contain within 512 and center on a transparent 512 canvas
                $tmp512 = Image::read($pwaSourceTempPath)->contain(512, 512);
                $canvas512 = Image::create(512, 512);
                $canvas512->place($tmp512, 'center')
                    ->save(Storage::disk('public')->path($icon512));
                $pwaIconData['icon_512_uri'] = Storage::disk('public')->url($icon512);

                // Maskable 512x512: safe area ~80% (410px)
                $canvas = Image::create(512, 512);
                $content = Image::read($pwaSourceTempPath)->contain(410, 410);
                $canvas->place($content, 'center')
                    ->save(Storage::disk('public')->path($iconMaskable512));
                $pwaIconData['icon_maskable_512_uri'] = Storage::disk('public')->url($iconMaskable512);
            }

            // Merge uploaded file paths with other logo settings from the request
            $logoSettingsData = array_merge($logoSettingsFromRequest, $uploadedLogoPaths);

            // Ensure nested pwa_icon structure (merge request + generated)
            if (!empty($pwaIconData) || !empty($logoSettingsFromRequest['pwa_icon'] ?? [])) {
                $logoSettingsData['pwa_icon'] = array_merge(
                    [
                        'source_uri' => '',
                        'icon_192_uri' => '',
                        'icon_512_uri' => '',
                        'icon_maskable_512_uri' => '',
                    ],
                    (array)($logoSettingsFromRequest['pwa_icon'] ?? []),
                    $pwaIconData
                );
            } else {
                // even if no upload, keep structure if backend expects it
                $logoSettingsData['pwa_icon'] = [
                    'source_uri' => (string)($logoSettingsFromRequest['pwa_icon']['source_uri'] ?? ''),
                    'icon_192_uri' => (string)($logoSettingsFromRequest['pwa_icon']['icon_192_uri'] ?? ''),
                    'icon_512_uri' => (string)($logoSettingsFromRequest['pwa_icon']['icon_512_uri'] ?? ''),
                    'icon_maskable_512_uri' => (string)($logoSettingsFromRequest['pwa_icon']['icon_maskable_512_uri'] ?? ''),
                ];
            }

            // --- ✅ 1. CONSTRUIR O OBJETO BRANDINGDATA ---
            $brandingData = new BrandingData(
                theme_data_settings: new ThemeDataSettings(
                    dark_scheme_data: ColorSchemeData::fromArray(["brightness" => "dark", ...$brandingDataFromRequest['theme_data_settings']['dark_scheme_data']]),
                    light_scheme_data: ColorSchemeData::fromArray(["brightness" => "light", ...$brandingDataFromRequest['theme_data_settings']['light_scheme_data']])
                ),
                logo_settings: LogoSettings::fromArray($logoSettingsData)
            );

            // --- ✅ 2. CRIAR O LANDLORD ---
            $landlord = Landlord::create([
                'name' => $request->validated()['landlord']['name'],
                'branding_data' => $brandingData
            ]);

            // --- ✅ 3. CRIAR O TENANT ---
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
}
