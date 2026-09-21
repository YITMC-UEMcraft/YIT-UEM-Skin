<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SetAppLocale
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function handle($event)
    {
        /** @var User */
        $user = $event->user;

        if ($this->request->has('lang')) {
            // bs-community/blessing-skin-server#684: persisting an illegal
            // locale breaks every page render for the account (500), so only
            // whitelisted values may be written.
            $locale = $this->validatedLocale($this->request->input('lang'));
            if ($locale) {
                $user->locale = $locale;
                $user->save();
            }

            return;
        }

        $locale = $this->validatedLocale($user->locale);
        if ($locale) {
            app()->setLocale($locale);
        } elseif ($user->locale) {
            // Heal accounts poisoned before this fix: ignore the illegal
            // value (fallback locale applies) and reset the stored one.
            $user->locale = null;
            $user->save();
        }
    }

    protected function validatedLocale($locale): ?string
    {
        if (!is_string($locale) || $locale === '') {
            return null;
        }

        $info = Arr::get(config('locales'), $locale);
        if (is_array($info) && $alias = Arr::get($info, 'alias')) {
            $locale = $alias;
        }

        return Arr::has(config('locales'), $locale) ? $locale : null;
    }
}
