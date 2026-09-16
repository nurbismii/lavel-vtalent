<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function valueFor(string $key): mixed
    {
        $request = request();
        if (! $request->attributes->has('portal_settings')) {
            $request->attributes->set('portal_settings', self::all()->pluck('value', 'key')->all());
        }

        $value = $request->attributes->get('portal_settings')[$key] ?? config('submissions.'.$key);
        if ($key === 'uploads') {
            // File formats follow the application policy, including settings saved before a policy change.
            foreach (config('submissions.uploads') as $purpose => $limits) {
                $value[$purpose]['extensions'] = $limits['extensions'];
            }
        }

        return $value;
    }

    protected static function booted(): void
    {
        static::saved(fn () => request()->attributes->remove('portal_settings'));
        static::deleted(fn () => request()->attributes->remove('portal_settings'));
    }
}
