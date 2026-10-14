<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BrandController extends Controller
{
    public function index(Request $r)
    {
        return view('brands.index', ['brands' => Brand::withCount('products')->when($r->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$r->string('q').'%'))->orderBy('name')->paginate(20)]);
    }

    public function create()
    {
        return view('brands.form', ['brand' => new Brand(['active' => true])]);
    }

    public function edit(Brand $brand)
    {
        return view('brands.form', compact('brand'));
    }

    private function data(Request $r, ?Brand $b = null)
    {
        $r->merge(['name' => trim((string) $r->input('name'))]);

        return $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('brands')->ignore($b?->id)], 'active' => ['required', 'boolean']]);
    }

    public function store(Request $r)
    {
        Brand::create($this->data($r));

        return redirect()->route('brands.index')->with('success', 'Marca creada.');
    }

    public function update(Request $r, Brand $brand)
    {
        $brand->update($this->data($r, $brand));

        return redirect()->route('brands.index')->with('success', 'Marca actualizada.');
    }

    public function destroy(Brand $brand)
    {
        DB::transaction(function () use ($brand) {
            $b = Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
            if ($b->products()->exists()) {
                throw ValidationException::withMessages(['brand' => 'La marca tiene productos asociados. Desactivala para conservar el historial.']);
            }
            $b->delete();
        });

        return back()->with('success', 'Marca eliminada.');
    }
}
