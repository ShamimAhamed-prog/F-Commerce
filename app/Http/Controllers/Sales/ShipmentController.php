<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Shipping\ShipmentService;
use App\Http\Controllers\Controller;
use App\Models\CourierIntegration;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ShipmentController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Gate::allows('shipments.view'), 403);
        $page = Shipment::with('order:id,order_number,customer_name,phone,status')
            ->when($request->input('state'), fn ($q, $s) => $q->where('state', $s))
            ->when($request->string('search')->trim()->value(), function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('invoice', 'like', $like)->orWhere('tracking_code', 'like', $like)->orWhere('consignment_id', 'like', $like));
            })
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('Shipments/Index', [
            'shipments' => $page->through(fn (Shipment $s) => $this->present($s) + ['order' => $s->order?->only('id', 'order_number', 'customer_name', 'phone', 'status')]),
            'filters' => $request->only('state', 'search'),
            'canManage' => Gate::allows('shipments.manage'),
        ]);
    }

    public static function present(Shipment $s): array
    {
        return [
            'id' => $s->id, 'provider' => $s->provider, 'invoice' => $s->invoice, 'state' => $s->state, 'consignment_id' => $s->consignment_id, 'tracking_code' => $s->tracking_code,
            'provider_status' => $s->provider_status, 'message' => $s->message, 'booked_at' => $s->booked_at, 'last_synced_at' => $s->last_synced_at, 'cod_amount' => (string) $s->cod_amount,
            'can_reconcile' => app(ShipmentService::class)->canReconcile($s),
        ];
    }

    public function book(Request $request, Order $order, ShipmentService $service): RedirectResponse
    {
        abort_unless(Gate::allows('shipments.manage'), 403);
        $data = $request->validate(['provider' => ['required', 'string']]);
        $integration = CourierIntegration::where('provider', $data['provider'])->first()
            ?? back()->withErrors(['courier' => 'Connect a courier account first (Settings → Couriers).']);
        if ($integration instanceof RedirectResponse) {
            return $integration;
        }

        $shipment = $service->book($order, $integration);

        return match ($shipment->state) {
            'booked' => back()->with('success', __('Sent to :c. Tracking: :t', ['c' => ucfirst($shipment->provider), 't' => $shipment->tracking_code ?? $shipment->consignment_id])),
            'booking_unknown' => back()->with('error', $shipment->message.' '.__('Do not book again; use "Check status" first.')),
            default => back()->with('error', $shipment->message),
        };
    }

    public function refresh(Shipment $shipment, ShipmentService $service): RedirectResponse
    {
        abort_unless(Gate::allows('shipments.manage'), 403);
        $s = $service->sync($shipment);

        return back()->with('success', __('Status: :s', ['s' => str_replace('_', ' ', (string) $s->provider_status)]));
    }

    public function reconcile(Shipment $shipment, ShipmentService $service): RedirectResponse
    {
        abort_unless(Gate::allows('shipments.manage'), 403);
        $s = $service->reconcile($shipment);

        return back()->with($s->state === 'booked' ? 'success' : 'error', $s->state === 'booked' ? __('Confirmed: the courier has this parcel.') : $s->message);
    }
}
