<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportBuilder extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'label',
        'detail',
        'user_id',
        'is_public',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'detail' => 'array',
        'is_public' => 'boolean',
    ];

    /**
     * Get the user that owns the report.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('visns-packages.user_model', 'App\\Models\\User'));
    }

    /**
     * Get the report configuration.
     *
     * @return array
     */
    public function getConfiguration(): array
    {
        return $this->detail ?? [];
    }

    /**
     * Set the report configuration.
     *
     * @param array $config
     * @return self
     */
    public function setConfiguration(array $config): self
    {
        $this->detail = $config;
        return $this;
    }

    /**
     * Get the selected tables from the report configuration.
     *
     * @return array
     */
    public function getSelectedTables(): array
    {
        return $this->detail['tables'] ?? [];
    }

    /**
     * Get the selected columns from the report configuration.
     *
     * @return array
     */
    public function getSelectedColumns(): array
    {
        return $this->detail['columns'] ?? [];
    }

    /**
     * Get the joins from the report configuration.
     *
     * @return array
     */
    public function getJoins(): array
    {
        return $this->detail['joins'] ?? [];
    }

    /**
     * Get the filters from the report configuration.
     *
     * @return array
     */
    public function getFilters(): array
    {
        return $this->detail['filters'] ?? [];
    }

    /**
     * Get the sorting from the report configuration.
     *
     * @return array
     */
    public function getSorting(): array
    {
        return $this->detail['sorting'] ?? [];
    }
}
