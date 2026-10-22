<?php

namespace App\Models;

use App\Blocks\BlockRegistry;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Revision extends Model
{
    // Only created_at, filled in by the database.
    public $timestamps = false;

    protected $fillable = [
        'model_type',
        'model_id',
        'title',
        'content',
        'blocks',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        // Same shape as pages.blocks; null for posts and for legacy page versions.
        'blocks' => 'array',
    ];

    /** The post or page this revision belongs to. */
    public function revisionable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }

    /**
     * First 100 characters as plain text: from the text blocks for a block-page
     * revision, otherwise from the HTML content.
     */
    public function excerpt(int $length = 100): string
    {
        $text = $this->blocks !== null
            ? app(BlockRegistry::class)->plainText($this->blocks['blocks'] ?? [], onlyTypes: ['text'])
            : PlainText::fromHtml($this->content);

        return Str::limit($text, $length);
    }
}
