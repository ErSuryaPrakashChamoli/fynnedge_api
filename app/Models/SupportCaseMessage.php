<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something said on a support case.
 *
 * `author_type` is not fillable. It is the field a customer would set to
 * `advisor` if they could, and a customer able to author an advisor message
 * on their own case could quote FynnEdge saying whatever they liked.
 */
class SupportCaseMessage extends Model
{
    public const AUTHOR_CUSTOMER = 'customer';

    /** Reserved. No write path exists, because no advisor system does. */
    public const AUTHOR_ADVISOR = 'advisor';

    public const AUTHOR_SYSTEM = 'system';

    protected $fillable = ['body'];

    /**
     * Who wrote it, internally. The customer sees `author` on the resource
     * as 'you' or 'FynnEdge'; the id behind it is not theirs to see.
     */
    protected $hidden = ['author_id'];

    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }

    public function isFromCustomer(): bool
    {
        return $this->author_type === self::AUTHOR_CUSTOMER;
    }
}
