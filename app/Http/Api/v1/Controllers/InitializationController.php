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

            // Handle file uploads for logos
            $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri'];
            foreach ($logoKeys as $key) {
                if ($request->hasFile("branding_data.logo_settings.$key")) {
                    // Store the file and get the path
                    $path = $request->file("branding_data.logo_settings.$key")->store('landlord/branding/logos', 'public');
                    $uploadedLogoPaths[$key] = Storage::url($path);
                }
            }

            // Merge uploaded file paths with other logo settings from the request
            $logoSettingsData = array_merge($logoSettingsFromRequest, $uploadedLogoPaths);


            // --- ✅ 1. CONSTRUIR O OBJETO BRANDINGDATA ---
            $brandingData = new BrandingData(
                theme_data_settings: new ThemeDataSettings(
                    dark_scheme_data: ColorSchemeData::fromArray(["brightness" => "dark", ...$brandingDataFromRequest['theme_data_settings']['dark_scheme_data']]),
                    light_scheme_data: ColorSchemeData::fromArray(["brightness" => "light", ...$brandingDataFromRequest['theme_data_settings']['light_scheme_data']])
                ),
                logo_settings: new LogoSettings(...$logoSettingsData)
            );

            // --- ✅ 2. CRIAR O LANDLORD ---
            // Cria o único documento Landlord e armazena o branding padrão nele
            $landlord = Landlord::create([
                'name' => $request->validated()['landlord']['name'],
                'branding_data' => $brandingData
            ]);

            // --- ✅ 3. CRIAR O TENANT E ASSOCIÁ-LO AO LANDLORD ---
            // Usamos a relação para criar o tenant, o que já associa o landlord_id
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
                "name" => $request->user['name'],
                "emails" => $request->user['emails'],
                "password" => $request->user['password']
            ]);

            $admin_role->users()->save($new_user);

            $new_user->tenantRoles()->create([
                ...$admin_tenant_template->attributesToArray(),
                'tenant_id' => $new_tenant->id,
            ]);

            foreach($request->user['emails'] as $email){
                $new_user->emails = [$email];
            }

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
