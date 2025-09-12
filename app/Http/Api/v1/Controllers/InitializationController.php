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
            $brandingDataFromRequest = $validated['brandingData'];
            $logoSettingsFromRequest = $brandingDataFromRequest['logoSettings'] ?? [];
            $uploadedLogoPaths = [];

            // Handle file uploads for logos    
            $logoKeys = ['lightLogoUri', 'darkLogoUri', 'lightIconUri', 'darkIconUri', 'faviconUri'];
            foreach ($logoKeys as $key) {
                if ($request->hasFile("brandingData.logoSettings.$key")) {
                    // Store the file and get the path
                    $path = $request->file("brandingData.logoSettings.$key")->store('landlord/branding/logos', 'public');
                    $uploadedLogoPaths[$key] = Storage::url($path);
                }
            }

            // Merge uploaded file paths with other logo settings from the request
            $logoSettingsData = array_merge($logoSettingsFromRequest, $uploadedLogoPaths);


            // --- ✅ 1. CONSTRUIR O OBJETO BRANDINGDATA ---
            $brandingData = new BrandingData(
                themeDataSettings: new ThemeDataSettings(
                    darkSchemeData: ColorSchemeData::fromArray(["brightness" => "dark", ...$brandingDataFromRequest['themeDataSettings']['darkSchemeData']]),
                    lightSchemeData: ColorSchemeData::fromArray(["brightness" => "light", ...$brandingDataFromRequest['themeDataSettings']['lightSchemeData']])
                ),
                logoSettings: new LogoSettings(...$logoSettingsData)
            );

            // --- ✅ 2. CRIAR O LANDLORD ---
            // Cria o único documento Landlord e armazena o branding padrão nele
            $landlord = Landlord::create([
                'name' => $request->validated()['landlord']['name'],
                'branding_data' => $brandingData
            ]);

            // --- ✅ 3. CRIAR O TENANT E ASSOCIÁ-LO AO LANDLORD ---
            // Usamos a relação para criar o tenant, o que já associa o landlord_id
            $new_tenant = $landlord->tenants()->create([
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
                "user" => $new_user->toArray(),
                "tenant" => [
                    ...$new_tenant->attributesToArray(),
                    "role_admin_id" => $admin_tenant_template->id,
                ],
                "role" => $admin_role->toArray(),
                "landlord" => [
                    ...$landlord->toArray(),
                    "token" => $token
                ],
            ]
        ], 201);

    }
}
