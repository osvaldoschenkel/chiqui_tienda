<?php

namespace App\Http\Controllers;

use App\Models\StoreSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class StoreSettingController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $name = $request->input('name');
        $tagline = $request->input('tagline');
        $tagline = is_string($tagline) ? trim($tagline) : $tagline;
        $request->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'tagline' => $tagline === '' ? null : $tagline,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'color' => ['required', 'string', Rule::in(array_keys(config('store.colors')))],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        $removeLogo = $request->boolean('remove_logo');
        if ($request->hasFile('logo') && $removeLogo) {
            throw ValidationException::withMessages(['logo' => 'Elegí subir un logo nuevo o quitar el actual, una opción a la vez.']);
        }

        $newLogoPath = null;
        if ($request->hasFile('logo')) {
            $newLogoPath = $request->file('logo')->store('branding', 'public');
            if ($newLogoPath === false) {
                throw ValidationException::withMessages(['logo' => 'No se pudo guardar el logo. Revisá los permisos de almacenamiento.']);
            }
        }
        $oldLogoPath = null;

        try {
            DB::transaction(function () use ($data, $removeLogo, $newLogoPath, &$oldLogoPath) {
                DB::table('store_settings')->insertOrIgnore([
                    'id' => StoreSetting::SINGLETON_ID,
                    ...config('store.defaults'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $setting = StoreSetting::query()->whereKey(StoreSetting::SINGLETON_ID)->lockForUpdate()->firstOrFail();
                $setting->fill([
                    'name' => $data['name'],
                    'tagline' => $data['tagline'] ?? null,
                    'color' => $data['color'],
                ]);
                if ($newLogoPath !== null || $removeLogo) {
                    $oldLogoPath = $setting->logo_path;
                    $setting->logo_path = $newLogoPath;
                }
                $setting->save();
            }, 3);
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }
            throw $exception;
        }

        // Run only after the outermost transaction commits; never delete a logo still in use.
        if ($oldLogoPath !== null && $oldLogoPath !== $newLogoPath) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($oldLogoPath));
        }
        app()->forgetInstance('store.brand');

        return redirect()->route('settings.edit')->with('success', 'La identidad de tu tienda se actualizó correctamente.');
    }
}
