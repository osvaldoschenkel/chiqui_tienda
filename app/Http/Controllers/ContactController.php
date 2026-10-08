<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\CommerceService;
use App\Services\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

abstract class ContactController extends Controller
{
    protected string $model;
    protected string $kind;
    protected string $title;
    protected string $foreignKey;

    public function index(Request $request): View
    {
        $query = $this->model::query();
        $search = trim($request->string('q')->toString());
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('document', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }
        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('active', $request->input('status') === 'active');
        }

        return view('contacts.index', [
            'contacts' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'kind' => $this->kind,
            'title' => $this->title,
        ]);
    }

    public function create(): View
    {
        $contact = new $this->model;
        $contact->active = true;

        return $this->form($contact);
    }

    public function store(Request $request): RedirectResponse
    {
        $contact = $this->model::create($this->validated($request));

        return redirect()->route($this->kind.'.show', $contact)->with('success', 'Registro creado correctamente.');
    }

    public function show(Request $request, string $id): View
    {
        $contact = $this->model::findOrFail($id);

        return view('contacts.show', [
            'contact' => $contact,
            'kind' => $this->kind,
            'title' => $this->title,
            'balanceCents' => $contact->balance_cents,
            'requestId' => (string) Str::uuid(),
            'movements' => $contact->ledgerEntries()->with('user')->orderByDesc('date')->orderByDesc('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function edit(string $id): View
    {
        return $this->form($this->model::findOrFail($id));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $contact = $this->model::findOrFail($id);
        $contact->update($this->validated($request, $contact));

        return redirect()->route($this->kind.'.show', $contact)->with('success', 'Registro actualizado correctamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $contact = $this->model::query()->lockForUpdate()->findOrFail($id);
                $hasHistory = LedgerEntry::where($this->foreignKey, $id)->exists();
                $hasHistory = $hasHistory || ($this->kind === 'customers'
                    ? Sale::where('customer_id', $id)->exists()
                    : Purchase::where('supplier_id', $id)->exists() || Product::where('supplier_id', $id)->exists());

                if ($hasHistory) {
                    throw ValidationException::withMessages([
                        'contact' => 'Este registro tiene operaciones o productos asociados. Desactivalo para conservar el historial.',
                    ]);
                }
                $contact->delete();
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw $exception;
            }
            throw ValidationException::withMessages(['contact' => 'El registro tiene datos relacionados. Desactivalo en lugar de eliminarlo.']);
        }

        return redirect()->route($this->kind.'.index')->with('success', 'Registro eliminado correctamente.');
    }

    public function movement(Request $request, string $id, CommerceService $commerce): RedirectResponse
    {
        $contact = $this->model::findOrFail($id);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['debit', 'credit'])],
            'amount' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'description' => ['required', 'string', 'max:500'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'transfer'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $data[$this->foreignKey] = $contact->id;
        $commerce->ledger($data, $request->user()->id);

        return redirect()->route($this->kind.'.show', $contact)->with('success', 'Movimiento registrado. El historial de cuenta corriente es permanente.');
    }

    protected function form(Model $contact): View
    {
        return view('contacts.form', ['contact' => $contact, 'kind' => $this->kind, 'title' => $this->title]);
    }

    protected function validated(Request $request, ?Model $contact = null): array
    {
        $request->merge([
            'document' => trim((string) $request->input('document')) ?: null,
            'email' => mb_strtolower(trim((string) $request->input('email'))) ?: null,
        ]);
        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'document' => ['nullable', 'string', 'max:30', Rule::unique($this->kind, 'document')->ignore($contact?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['nullable', 'boolean'],
        ];
        if ($this->kind === 'customers') {
            $rules['credit_limit'] = ['nullable', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'];
        }
        $data = $request->validate($rules);
        $data['active'] = $request->boolean('active');
        if ($this->kind === 'customers') {
            $data['credit_limit_cents'] = Money::cents($data['credit_limit'] ?? '0');
            unset($data['credit_limit']);
        }

        return $data;
    }
}
