<?php

namespace Database\Seeders;

use App\Models\Landlord\LandlordUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class LandlordUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        collect([
            [
                'name' => 'Administrador do Sistema',
                'emails' => ['admin@system.com'],
                'password' => 'password',
            ],
            [
                'name' => 'Gerente de Contas',
                'emails' => ['manager@system.com'],
                'password' => 'password',
            ],
        ])->each(static function (array $data) use ($now): void {
            $emails = collect($data['emails'])
                ->map(static fn (string $email): string => strtolower($email))
                ->values()
                ->all();

            $user = LandlordUser::create([
                'name' => $data['name'],
                'emails' => $emails,
                'password' => $data['password'],
                'identity_state' => 'validated',
                'verified_at' => $now,
                'promotion_audit' => [
                    [
                        'from_state' => 'registered',
                        'to_state' => 'validated',
                        'promoted_at' => $now,
                        'operator_id' => null,
                    ],
                ],
            ]);

            foreach ($emails as $email) {
                $user->ensureEmail($email);
                $user->syncCredential('password', $email, $user->password);
            }
        });
    }
}
