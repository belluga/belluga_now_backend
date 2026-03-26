<?php

namespace Tests\Api\v1\Admin;

use App\Models\Landlord\LandlordUser;
use Illuminate\Testing\TestResponse;
use MongoDB\BSON\ObjectId;
use Tests\TestCaseAuthenticated;
use Tests\Traits\SeedsLandlordSupportRoles;

class ApiV1AdminUserTest extends TestCaseAuthenticated
{
    use SeedsLandlordSupportRoles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSupportRoles();
    }

    public function test_user_tenants_manager_create(): void
    {

        $this->landlord->user_cross_tenant_admin->name = fake()->name();
        $this->landlord->user_cross_tenant_admin->email_1 = fake()->email();
        $this->landlord->user_cross_tenant_admin->email_2 = fake()->email();
        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->userCreate([
            'name' => $this->landlord->user_cross_tenant_admin->name,
            'email' => $this->landlord->user_cross_tenant_admin->email_1,
            'password' => $this->landlord->user_cross_tenant_admin->password,
            'password_confirmation' => $this->landlord->user_cross_tenant_admin->password,
            'device_name' => 'test',
            'role_id' => $this->landlord->role_tenants_manager->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            'message',
            'data' => [
                'name',
                'id',
            ],

        ]);

        $userId = $response->json()['data']['id'];
        $createdUser = LandlordUser::where('_id', new ObjectId($userId))->firstOrFail();
        $this->assertEquals('registered', $createdUser->identity_state);
        $this->assertNotEmpty($createdUser->credentials);
        $this->assertCount(1, $createdUser->credentials);
        $this->assertCount(1, $createdUser->promotion_audit ?? []);
        $promotionAudit = $createdUser->promotion_audit[0];
        $this->assertEquals('anonymous', $promotionAudit['from_state']);
        $this->assertEquals('registered', $promotionAudit['to_state']);
        $this->assertEquals(
            $this->landlord->user_superadmin->user_id,
            (string) ($promotionAudit['operator_id'] ?? '')
        );

        $this->landlord->user_cross_tenant_admin->user_id = $userId;
    }

    public function test_user_create_again(): void
    {

        $response = $this->userCreate([
            'name' => fake()->name,
            'email' => $this->landlord->user_cross_tenant_admin->email_1,
            'password' => $this->landlord->user_cross_tenant_admin->password,
            'password_confirmation' => $this->landlord->user_cross_tenant_admin->password,
            'device_name' => 'test',
            'role_id' => $this->landlord->role_tenants_manager->id,
        ]);

        $response->assertStatus(422);

        $response->assertJsonStructure([
            'message',
            'errors' => [
                'email',
            ],
        ]);
    }

    public function test_user_visitor_create(): void
    {

        $this->landlord->user_cross_tenant_visitor->name = fake()->name();
        $this->landlord->user_cross_tenant_visitor->email_1 = fake()->email();
        $this->landlord->user_cross_tenant_visitor->email_2 = fake()->email();
        $this->landlord->user_cross_tenant_visitor->password = fake()->password(8);

        $response = $this->userCreate([
            'name' => $this->landlord->user_cross_tenant_visitor->name,
            'email' => $this->landlord->user_cross_tenant_visitor->email_1,
            'password' => $this->landlord->user_cross_tenant_visitor->password,
            'password_confirmation' => $this->landlord->user_cross_tenant_visitor->password,
            'device_name' => 'test',
            'role_id' => $this->landlord->role_visitor->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            'message',
            'data' => [
                'name',
                'id',
            ],

        ]);

        $userId = $response->json()['data']['id'];
        $createdUser = LandlordUser::where('_id', new ObjectId($userId))->firstOrFail();
        $this->assertEquals('registered', $createdUser->identity_state);
        $this->assertCount(1, $createdUser->promotion_audit ?? []);

        $this->landlord->user_cross_tenant_visitor->user_id = $userId;
    }

    public function test_user_disposable_create(): void
    {

        $this->landlord->user_disposable->name = fake()->name();
        $this->landlord->user_disposable->email_1 = fake()->email();
        $this->landlord->user_disposable->email_2 = fake()->email();
        $this->landlord->user_disposable->password = fake()->password(8);

        $response = $this->userCreate([
            'name' => $this->landlord->user_disposable->name,
            'email' => $this->landlord->user_disposable->email_1,
            'password' => $this->landlord->user_disposable->password,
            'password_confirmation' => $this->landlord->user_disposable->password,
            'device_name' => 'test',
            'role_id' => $this->landlord->role_visitor->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            'message',
            'data' => [
                'name',
                'id',
            ],

        ]);

        $userId = $response->json()['data']['id'];
        $createdUser = LandlordUser::where('_id', new ObjectId($userId))->firstOrFail();
        $this->assertEquals('registered', $createdUser->identity_state);

        $this->landlord->user_disposable->user_id = $userId;
    }

    public function test_user_list(): void
    {

        $response = $this->userListUnauthenticated();

        $response->assertStatus(401);

        $response->assertJsonStructure([
            'message',
        ]);

        $response = $this->userList();

        $response->assertStatus(200);

        $response_data = $response->json();
        $this->assertEquals(6, count($response_data['data']));
        $this->assertArrayHasKey('current_page', $response_data);
        $this->assertArrayHasKey('per_page', $response_data);
    }

    public function test_soft_delete(): void
    {
        $response = $this->userSoftDelete($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

    }

    public function test_list_archived(): void
    {
        $response = $this->userList();
        $this->assertEquals(5, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(1, count($response['data']));
    }

    public function test_user_restore(): void
    {
        $response = $this->userRestore($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(6, count($response['data']));
    }

    public function test_user_show(): void
    {
        $response = $this->userShow($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'emails',
                'name',
                'id',
                'created_at',
            ],
        ]);
    }

    public function test_user_update(): void
    {
        $new_name = fake()->name();

        $response = $this->userUpdate(
            $this->landlord->user_disposable->user_id,
            [
                'name' => $new_name,
            ]
        );

        $response->assertStatus(200);

        $response = $this->userShow($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $this->assertEquals(
            $new_name, $response->json()['data']['name']
        );
    }

    public function test_user_delete_flow(): void
    {
        $response = $this->userList();
        $this->assertEquals(6, count($response['data']));

        $response = $this->userSoftDelete($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(5, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(1, count($response['data']));

        $response = $this->userForceDelete($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(5, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(0, count($response['data']));
    }

    protected function userUpdate(string $user_id, array $data): TestResponse
    {

        return $this->json(
            method: 'patch',
            uri: "admin/api/v1/users/$user_id",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userShow(string $user_id): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/v1/users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function userRestore(string $user_id): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/v1/users/$user_id/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function userSoftDelete(string $user_id): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/v1/users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function userForceDelete(string $user_id): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/v1/users/$user_id/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function userListUnauthenticated(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: 'admin/api/v1/users',
        );
    }

    protected function userList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: 'admin/api/v1/users',
            headers: $this->getHeaders(),
        );
    }

    protected function userListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: 'admin/api/v1/users?archived=true',
            headers: $this->getHeaders(),
        );
    }

    protected function userCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/users',
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
