<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Models\Tenants\AccountProfile;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Contracts\TenantMediaScopeResolverContract;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AccountProfileMediaServiceTest extends TestCase
{
    public function test_gallery_mutation_backup_restores_only_the_targeted_item_files(): void
    {
        Storage::fake('public');
        $service = new AccountProfileMediaService(new ModelMediaService(
            new class implements TenantMediaScopeResolverContract
            {
                public function resolveTenantScope(?string $baseUrl): ?string
                {
                    return 'tenant-zeta';
                }
            },
        ));
        $profile = new AccountProfile;
        $profile->_id = 'profile-123';
        $directory = 'tenants/tenant-zeta/account_profiles/profile-123';
        $targetMaster = "{$directory}/gallery-item-target.jpg";
        $targetThumb = "{$directory}/gallery-item-target.thumb.jpg";
        $targetModal = "{$directory}/gallery-item-target.modal.jpg";
        $unrelatedMaster = "{$directory}/gallery-item-unrelated.jpg";
        $unrelatedCard = "{$directory}/gallery-item-unrelated.card.jpg";

        Storage::disk('public')->put($targetMaster, 'target-original');
        Storage::disk('public')->put($targetThumb, 'target-thumb-original');
        Storage::disk('public')->put($unrelatedMaster, 'unrelated-original');

        $backup = $service->captureGalleryItemMutationBackup(
            $profile,
            ['target'],
            'https://tenant-zeta.test',
        );

        Storage::disk('public')->put($targetMaster, 'target-replaced');
        Storage::disk('public')->delete($targetThumb);
        Storage::disk('public')->put($targetModal, 'target-new-variant');
        Storage::disk('public')->put($unrelatedMaster, 'unrelated-updated');
        Storage::disk('public')->put($unrelatedCard, 'unrelated-new-variant');

        $backup->restore();

        $this->assertSame('target-original', Storage::disk('public')->get($targetMaster));
        $this->assertSame('target-thumb-original', Storage::disk('public')->get($targetThumb));
        Storage::disk('public')->assertMissing($targetModal);
        $this->assertSame('unrelated-updated', Storage::disk('public')->get($unrelatedMaster));
        $this->assertSame('unrelated-new-variant', Storage::disk('public')->get($unrelatedCard));
    }
}
