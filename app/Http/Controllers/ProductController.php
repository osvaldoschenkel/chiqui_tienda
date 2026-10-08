<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $query = Product::with('supplier');
        $search = trim($request->string('q')->toString());
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('barcode', 'like', '%'.$search.'%');
            });
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('active', $request->input('status') === 'active');
        }
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        return view('products.index', [
            'products' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'categories' => Product::whereNotNull('category')->where('category', '<>', '')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function create(): View
    {
        $product = new Product;
        $product->active = true;
        $product->stock = 0;
        $product->min_stock = 0;
        $product->cost_cents = 0;
        $product->price_cents = 0;

        return $this->form($product);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $imagePath = $this->storeImage($request);
        if ($imagePath !== null) {
            $data['image_path'] = $imagePath;
        }

        try {
            $product = DB::transaction(function () use ($data, $request) {
                $product = Product::create($data);
                if ($product->stock !== 0) {
                    StockMovement::create([
                        'product_id' => $product->id,
                        'delta' => $product->stock,
                        'reference_type' => 'initial',
                        'reference_id' => $product->id,
                        'note' => 'Stock inicial al crear el producto.',
                        'user_id' => $request->user()->id,
                    ]);
                }

                return $product;
            });
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $exception;
        }

        return redirect()->route('products.index')->with('success', 'Producto creado correctamente.');
    }

    public function edit(Product $product): View
    {
        return $this->form($product);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        $expectedStock = (int) $data['expected_stock'];
        unset($data['expected_stock']);
        $imagePath = $this->storeImage($request);
        $oldImagePath = null;

        try {
            DB::transaction(function () use ($data, $expectedStock, $imagePath, $request, $product, &$oldImagePath) {
                $current = Product::query()->lockForUpdate()->findOrFail($product->id);
                if ((int) $current->stock !== $expectedStock) {
                    throw ValidationException::withMessages([
                        'stock' => 'El stock cambió desde que abriste este formulario. Volvé a abrir la edición para consultar la cantidad actual antes de guardar.',
                    ]);
                }
                $delta = (int) $data['stock'] - (int) $current->stock;
                if ($imagePath !== null) {
                    $oldImagePath = $current->image_path;
                    $data['image_path'] = $imagePath;
                }
                $current->update($data);
                if ($delta !== 0) {
                    StockMovement::create([
                        'product_id' => $current->id,
                        'delta' => $delta,
                        'reference_type' => 'adjustment',
                        'reference_id' => $current->id,
                        'note' => 'Ajuste manual desde la edición del producto.',
                        'user_id' => $request->user()->id,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $exception;
        }
        if ($oldImagePath !== null) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return redirect()->route('products.index')->with('success', 'Producto actualizado. Los cambios de stock quedaron registrados.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $imagePath = null;
        try {
            DB::transaction(function () use ($product, &$imagePath) {
                $current = Product::query()->lockForUpdate()->findOrFail($product->id);
                if (SaleItem::where('product_id', $current->id)->exists()
                    || PurchaseItem::where('product_id', $current->id)->exists()
                    || StockMovement::where('product_id', $current->id)->exists()) {
                    throw ValidationException::withMessages([
                        'product' => 'Este producto tiene operaciones o movimientos de stock. Desactivalo para conservar el historial.',
                    ]);
                }
                $imagePath = $current->image_path;
                $current->delete();
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw $exception;
            }
            throw ValidationException::withMessages(['product' => 'El producto tiene datos relacionados. Desactivalo en lugar de eliminarlo.']);
        }
        if ($imagePath !== null) {
            Storage::disk('public')->delete($imagePath);
        }

        return redirect()->route('products.index')->with('success', 'Producto eliminado correctamente.');
    }

    private function form(Product $product): View
    {
        return view('products.form', [
            'product' => $product,
            'suppliers' => Supplier::where('active', true)->when($product->supplier_id, function ($query) use ($product) {
                $query->orWhere('id', $product->supplier_id);
            })->orderBy('name')->get(),
        ]);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $request->merge([
            'sku' => trim((string) $request->input('sku')),
            'barcode' => trim((string) $request->input('barcode')) ?: null,
        ]);
        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->ignore($product?->id)],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'cost' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'price' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'stock' => ['required', 'integer', 'min:0', 'max:100000000'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:100000000'],
            'active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
        if ($product !== null) {
            $rules['expected_stock'] = ['required', 'integer', 'min:0', 'max:100000000'];
        }
        $data = $request->validate($rules);
        $data['cost_cents'] = Money::cents($data['cost']);
        $data['price_cents'] = Money::cents($data['price']);
        $data['active'] = $request->boolean('active');
        unset($data['cost'], $data['price'], $data['image']);

        return $data;
    }

    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }
        $path = $request->file('image')->store('products', 'public');
        if ($path === false) {
            throw ValidationException::withMessages(['image' => 'No se pudo guardar la imagen. Revisá los permisos de almacenamiento.']);
        }

        return $path;
    }
}
