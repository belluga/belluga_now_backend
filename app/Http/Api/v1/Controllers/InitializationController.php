<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InitializationController extends Controller
{

    use HasLogoFiles;

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

            $logoSettingsPayload = $this->processLogoUploads($request);

            $pwaIconPayload = $this->generatePwaIconVariants(
                sourceFile: $request->file("branding_data.pwa_icon"),
            );

            $themeDataPayload = $validated['branding_data']['theme_data_settings'];

            $landlord->branding_data = [
                'theme_data_settings' => $themeDataPayload,
                'logo_settings'       => $logoSettingsPayload,
                'pwa_icon'            => $pwaIconPayload,
            ];

            $landlord->save();

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

            $emails = collect($validated['user']['emails'])
                ->map(static fn (string $email): string => strtolower($email))
                ->values()
                ->all();

            $new_user = LandlordUser::create([
                'name' => $validated['user']['name'],
                'emails' => $emails,
                'password' => $validated['user']['password'],
                'identity_state' => 'validated',
                'verified_at' => Carbon::now(),
                'promotion_audit' => [
                    [
                        'from_state' => 'registered',
                        'to_state' => 'validated',
                        'promoted_at' => Carbon::now(),
                        'operator_id' => null,
                    ],
                ],
            ]);

            foreach ($emails as $email) {
                $new_user->ensureEmail($email);
                $new_user->syncCredential('password', $email, $new_user->password);
            }

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
}
