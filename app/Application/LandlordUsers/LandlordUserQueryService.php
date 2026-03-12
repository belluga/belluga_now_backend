<?php

declare(strict_types=1);

namespace App\Application\LandlordUsers;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Landlord\LandlordUser;
use Illuminate\Pagination\LengthAwarePaginator;

class LandlordUserQueryService extends AbstractQueryService
{
    public function paginate(
        array $queryParams,
        bool $includeArchived,
        int $perPage = 15
    ): LengthAwarePaginator {
        return $this->buildPaginator(
            LandlordUser::query(),
            $queryParams,
            $includeArchived,
            $perPage
        );
    }

    protected function baseSearchableFields(): array
    {
        return array_diff(
            (new LandlordUser)->getFillable(),
            ['password', 'credentials', 'promotion_audit']
        );
    }

    protected function stringFields(): array
    {
        return ['name', 'identity_state'];
    }

    protected function arrayFields(): array
    {
        return ['emails', 'phones'];
    }

    protected function dateFields(): array
    {
        return ['verified_at', 'created_at', 'updated_at', 'deleted_at'];
    }
}
