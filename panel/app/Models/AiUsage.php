<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The tokens and estimated cost of one request to Claude.
 */
#[Fillable(['user_id', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost', 'created_at'])]
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected function casts(): array
    {
        return ['cost' => 'float', 'created_at' => 'datetime'];
    }
}
