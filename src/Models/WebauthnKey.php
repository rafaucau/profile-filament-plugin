<?php

declare(strict_types=1);

namespace Rawilk\ProfileFilament\Models;

use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Rawilk\ProfileFilament\Exceptions\Webauthn\WrongUserHandle;
use Rawilk\ProfileFilament\Facades\ProfileFilament;
use Webauthn\PublicKeyCredentialSource;

use function Rawilk\ProfileFilament\wrapDateInTimeTag;

class WebauthnKey extends Model
{
    use HasFactory;

    protected $casts = [
        'credential_id' => 'encrypted',
        'public_key' => 'encrypted:json',
        'transports' => 'array',
        'is_passkey' => 'boolean',
        'last_used_at' => 'immutable_datetime',
    ];

    protected $hidden = [
        'public_key',
        'credential_id',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('profile-filament.table_names.webauthn_key');
    }

    public static function fromPublicKeyCredentialSource(
        PublicKeyCredentialSource $source,
        User $user,
        string $keyName,
        string $attachmentType = null,
    ): static {
        throw_unless(
            static::getUserHandle($user) === $source->userHandle,
            WrongUserHandle::class,
        );

        $data = [
            'name' => $keyName,
            'user_id' => $user->getAuthIdentifier(),
            'attachment_type' => $attachmentType,
        ];

        return tap(static::make($data), function (self $webauthnKey) use ($source) {
            $webauthnKey->transports = $source->transports;
            $webauthnKey->credential_id = $source->publicKeyCredentialId;
            $webauthnKey->public_key = $source->jsonSerialize();
        });
    }

    public static function getUsername(User $user): string
    {
        return $user->email;
    }

    public static function getUserHandle(User $user): string
    {
        return (string) $user->getAuthIdentifier();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function canUpgradeToPasskey(): bool
    {
        return $this->attachment_type === 'platform' &&
            ! $this->is_passkey;
    }

    public function scopeByCredentialId(Builder $query, string $credentialId): void
    {
        $query->where('credential_id', base64_encode($credentialId));
    }

    public function scopePasskeys(Builder $query): void
    {
        $query->where('is_passkey', true);
    }

    public function notPasskeys(Builder $query): void
    {
        $query->where('is_passkey', false);
    }

    public function lastUsed(): Attribute
    {
        return Attribute::make(
            get: function () {
                $date = blank($this->last_used_at)
                    ? __('profile-filament::pages/security.mfa.method_never_used')
                    : wrapDateInTimeTag($this->last_used_at->tz(ProfileFilament::userTimezone()), 'M d, Y g:i a');

                $translation = __('profile-filament::pages/security.mfa.method_last_used_date', ['date' => $date]);

                return new HtmlString(Str::inlineMarkdown($translation));
            },
        )->shouldCache();
    }

    protected function credentialId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => base64_decode($value),
            set: fn ($value) => base64_encode($value),
        )->shouldCache();
    }

    protected function publicKeyCredentialSource(): Attribute
    {
        return Attribute::make(
            get: fn (): PublicKeyCredentialSource => PublicKeyCredentialSource::createFromArray($this->public_key),
        )->shouldCache();
    }

    protected function registeredAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $date = $this->created_at->tz(ProfileFilament::userTimezone());

                $translation = __('profile-filament::pages/security.mfa.method_registration_date', ['date' => wrapDateInTimeTag($date)]);

                return new HtmlString(Str::inlineMarkdown($translation));
            },
        )->shouldCache();
    }
}
