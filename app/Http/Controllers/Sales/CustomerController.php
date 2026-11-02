<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\PhoneNormalizer;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Gate::allows('customers.view'), 403);
        $page = Customer::query()->withCount('orders')
            ->when($request->string('search')->trim()->value(), function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('phone_normalized', 'like', $like));
            })
            ->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25))))->withQueryString();

        return Inertia::render('Customers/Index', ['customers' => $page, 'filters' => $request->only('search')]);
    }

    public function show(Customer $customer): Response
    {
        abort_unless(Gate::allows('customers.view'), 403);
        $counts = $customer->orders()->reorder()->selectRaw('status, COUNT(*) c, SUM(total) s')->groupBy('status')->get()->keyBy('status');

        return Inertia::render('Customers/Show', [
            'customer' => $customer->only('id', 'name', 'phone', 'phone_normalized', 'alt_phone', 'address', 'area', 'notes'),
            'orders' => $customer->orders()->limit(50)->get(['id', 'order_number', 'status', 'total', 'created_at']),
            'stats' => [
                'total' => (int) $counts->sum('c'), 'delivered' => (int) ($counts['delivered']->c ?? 0),
                'cancelled' => (int) ($counts['cancelled']->c ?? 0), 'returned' => (int) ($counts['returned']->c ?? 0),
                'lifetime' => number_format((float) ($counts['delivered']->s ?? 0), 2, '.', ''),
            ],
            'canManage' => Gate::allows('customers.manage'),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless(Gate::allows('customers.manage'), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'alt_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'], 'area' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! empty($d['alt_phone']) && ! PhoneNormalizer::normalize($d['alt_phone'])) {
            return back()->withErrors(['alt_phone' => 'Enter a valid mobile number.']);
        }
        $customer->update($d);

        return back()->with('success', __('Customer saved.'));
    }
}
