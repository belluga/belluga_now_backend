<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Account extends Model {

    use HasSlug, HasFactory, UsesTenantConnection;

    protected $keyType = "ObjectId";

    protected $fillable = [
        'name',
        'document',
        'address'
    ];

     public function users(): BelongsToMany {
         return $this->belongsToMany(
             related: User::class
         );
     }

    public function getDocumentNumber(): string {
        return $this->document ?? "";
    }

    public function getDocumentFormated(): string {
        $CPF_LENGTH = 11;
        $cnpj_cpf = preg_replace("/\D/", '', $this->getDocumentNumber());

        if (strlen($cnpj_cpf) === $CPF_LENGTH) {
            return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
        }

        return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
    }

    public function getName(): string {
        return $this->name ?? "";
    }

    public function getAddress(): string {
        return $this->address ?? "";
    }


    public function getSlugOptions() : SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    protected function casts(): array
    {
        return [];
    }
}
