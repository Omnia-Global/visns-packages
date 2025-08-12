<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class CameraPlacement extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'project_name',
        'version',
        'mode',
        'image_data',
        'image_name',
        'cameras_data',
        'user_id',
    ];

    protected $casts = [
        'cameras_data' => 'array',
        'image_data' => 'array',
    ];

    protected $dates = ['deleted_at'];

    protected $appends = ['cameras_count'];

    // Note: $hidden property doesn't work reliably with DynamicController
    // Using custom methods instead

    public function loadableRelations()
    {
        return ['user'];
    }

    public function validationRules($context = 'store', $requestData = null)
    {
        $rules = [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'project_name' => 'required|string|max:255',
            'version' => 'nullable|string|max:50',
            'mode' => 'required|in:image,map',
            'image_data' => 'nullable|array',
            'image_name' => 'nullable|string|max:255',
            'cameras_data' => 'required|array',
            'user_id' => 'required|exists:users,id',
        ];

        return $rules;
    }

    /**
     * Relationship to user (creator)
     */
    public function user()
    {
        return $this->belongsTo(config('visns-packages.user_model', 'App\Models\User'));
    }

    /**
     * Get the cameras count virtual attribute
     */
    public function getCamerasCountAttribute()
    {
        // If cameras_data is loaded, count from it
        if (isset($this->attributes['cameras_data'])) {
            $cameras = is_string($this->attributes['cameras_data']) 
                ? json_decode($this->attributes['cameras_data'], true) 
                : $this->attributes['cameras_data'];
            return is_array($cameras) ? count($cameras) : 0;
        }
        
        // Fallback to 0 if cameras_data is not loaded
        return 0;
    }

    /**
     * Get lightweight representation (excluding heavy JSON fields)
     */
    public function toLightweightArray()
    {
        $array = $this->toArray();
        unset($array['image_data'], $array['cameras_data']);
        return $array;
    }

    /**
     * Get full representation (including heavy JSON fields)
     */
    public function toFullArray()
    {
        return $this->toArray();
    }

    /**
     * Scope for custom ordering (integrates with dynamic entity system)
     */
    public function scopeCustomOrder($query, $orderBy, $order)
    {
        if (isset($orderBy) && isset($order)) {
            $query->orderBy($orderBy, $order);
        }

        return $query;
    }

    /**
     * Scope for custom search (integrates with dynamic entity system)
     */
    public function scopeCustomSearch($query, $search)
    {
        $columns = ['name', 'description', 'project_name'];

        if (isset($search) && !empty($search)) {
            foreach ($columns as $key => $item) {
                if ($key == 0) {
                    $query->where($item, 'like', '%' . $search . '%');
                } else {
                    $query->orWhere($item, 'like', '%' . $search . '%');
                }
            }
        }

        return $query;
    }

    /**
     * Scope to filter by user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by mode
     */
    public function scopeByMode($query, $mode)
    {
        return $query->where('mode', $mode);
    }

    /**
     * Get formatted data for JSON export (backward compatibility)
     */
    public function getExportData()
    {
        return [
            'version' => $this->version ?? '1.0',
            'timestamp' => $this->created_at->toISOString(),
            'projectName' => $this->project_name,
            'image' => $this->image_data ? [
                'src' => $this->image_data['src'] ?? null,
                'name' => $this->image_name ?? 'uploaded-image',
            ] : null,
            'mode' => $this->mode,
            'cameras' => $this->cameras_data ?? [],
        ];
    }

    /**
     * Create from import data (for JSON file imports)
     */
    public static function createFromImport($data, $userId, $name = null)
    {
        return static::create([
            'name' => $name ?? ($data['projectName'] ?? 'Imported Project'),
            'project_name' => $data['projectName'] ?? 'Untitled Project',
            'version' => $data['version'] ?? '1.0',
            'mode' => $data['mode'] ?? 'image',
            'image_data' => $data['image'] ?? null,
            'image_name' => $data['image']['name'] ?? null,
            'cameras_data' => $data['cameras'] ?? [],
            'user_id' => $userId,
        ]);
    }

    /**
     * Duplicate this camera placement
     */
    public function duplicate($newName = null)
    {
        $newName = $newName ?? $this->name . ' (Copy)';

        return static::create([
            'name' => $newName,
            'description' => $this->description,
            'project_name' => $this->project_name . ' (Copy)',
            'version' => $this->version,
            'mode' => $this->mode,
            'image_data' => $this->image_data,
            'image_name' => $this->image_name,
            'cameras_data' => $this->cameras_data,
            'user_id' => $this->user_id,
        ]);
    }

    /**
     * Get camera placement statistics
     */
    public function getStats()
    {
        $cameras = $this->cameras_data ?? [];
        $stats = [
            'total_cameras' => count($cameras),
            'cameras_with_models' => 0,
            'camera_types' => [],
            'environments' => [],
        ];

        foreach ($cameras as $camera) {
            if (!empty($camera['verkadaModel'])) {
                $stats['cameras_with_models']++;
            }
        }

        return $stats;
    }

    /**
     * Get the model with heavy JSON fields visible (for full data loading)
     */
    public function withHeavyFields()
    {
        return $this->makeVisible(['image_data', 'cameras_data']);
    }

    /**
     * Scope to include heavy fields
     */
    public function scopeWithHeavyFields($query)
    {
        return $query->addSelect('*')->with([]);
    }

    /**
     * Fields to exclude from table/listing responses (used by DynamicController)
     * Heavy JSON fields are excluded to improve performance
     */
    public function excludedFields()
    {
        return ['image_data', 'cameras_data'];
    }
}