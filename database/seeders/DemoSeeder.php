<?php

namespace Database\Seeders;

use App\Domain\Inventory\StockService;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\RoleCatalog;
use App\Domain\Tenancy\TenantProvisioner;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\Poster;
use App\Domain\Sales\OrderService;
use App\Models\CapitalContribution;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Local demo data only. Refuses to run in production. Run: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@example.com';

    public const PASSWORD = 'Demo12345';

    public function run(): void
    {
        abort_if(app()->environment('production'), 1, 'DemoSeeder must not run in production.');
        RoleCatalog::sync();

        $user = User::firstOrCreate(['email' => self::EMAIL], ['name' => 'Demo Owner', 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now()])->save();
        mt_srand(7); // deterministic demo data

        if ($existing = $user->tenants()->where('slug', 'like', 'demo-shop%')->first()) {
            // Top up an older demo workspace in place (never touches other workspaces).
            app(CurrentTenant::class)->run($existing, function () use ($user) {
                auth()->setUser($user);
                if (Order::count() === 0) {
                    $this->history($user);
                }
            });

            return;
        }

        $tenant = app(TenantProvisioner::class)->create($user, ['name' => 'Demo Shop', 'phone' => '01700000000', 'address' => 'Dhanmondi, Dhaka']);
        app(CurrentTenant::class)->run($tenant, function () use ($user) {
            auth()->setUser($user);
            $stock = app(StockService::class);
            foreach ([['Cotton Panjabi', 'PNJ-001', '1500', '900', 12], ['Silk Saree', 'SRE-001', '4500', '3000', 5], ['Ceramic Mug', 'MUG-001', '250', '100', 40]] as [$name, $sku, $price, $cost, $qty]) {
                $p = Product::create(['name' => $name, 'sku' => $sku, 'selling_price' => $price, 'purchase_cost' => $cost, 'status' => 'active', 'reorder_threshold' => 6]);
                $stock->record($p->id, null, 'opening', $qty * 4, $cost, 'Opening stock');
            }
            $this->history($user);
        });
    }

    /** Two weeks of orders, expenses and owner capital so every dashboard and report has something to show. */
    private function history(User $user): void
    {
        $orders = app(OrderService::class);
        $poster = app(Poster::class);
        $cash = FinancialAccount::first();
        $products = Product::all();
        $names = [['Rahim Uddin', '01711000001', 'Dhanmondi'], ['Karim Hasan', '01811000002', 'Chawkbazar'], ['Nusrat Jahan', '01911000003', 'Uttara'], ['Sadia Islam', '01611000004', 'Mirpur'], ['Tanvir Ahmed', '01511000005', 'Sylhet']];

        $cap = CapitalContribution::create(['contribution_date' => now()->subDays(14)->toDateString(), 'amount' => '150000', 'financial_account_id' => $cash->id, 'note' => 'Initial investment', 'created_by' => $user->id]);
        $cap->update(['entry_id' => $poster->capital($cap->load('financialAccount.account'))->id]);

        $statuses = ['delivered', 'delivered', 'delivered', 'shipped', 'confirmed', 'pending_confirmation', 'cancelled'];
        for ($day = 13; $day >= 0; $day--) {
            for ($n = 0, $count = mt_rand(1, 4); $n < $count; $n++) {
                [$name, $phone, $area] = $names[array_rand($names)];
                $p = $products[array_rand($products->all())];
                $qty = mt_rand(1, 2);
                $o = $orders->create(['customer_name' => $name, 'phone' => $phone, 'address' => "House ".mt_rand(1, 40).", {$area}", 'area' => $area,
                    'shipping_charge' => '80', 'advance' => mt_rand(0, 1) ? '100' : '0', 'items' => [['product_id' => $p->id, 'qty' => $qty]]]);
                $target = $statuses[array_rand($statuses)];
                $path = ['confirmed', 'processing', 'ready_to_ship', 'shipped', 'delivered'];
                try {
                    if ($target === 'cancelled') {
                        $orders->transition($o, 'confirmed');
                        $orders->transition($o, 'cancelled');
                    } elseif ($target !== 'pending_confirmation') {
                        foreach ($path as $step) {
                            $orders->transition($o, $step);
                            if ($step === $target) {
                                break;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // out of demo stock for this product: leave the order as it is
                }
                Order::whereKey($o->id)->update(['created_at' => now()->subDays($day)->subHours(mt_rand(0, 8))]);
            }
        }

        foreach ([['Advertising / marketing', '6500', 'Meta Ads'], ['Packaging', '1800', 'Packaging House'], ['Courier and return charges', '950', 'Steadfast'], ['Rent', '12000', 'Landlord'], ['Internet / telephone', '1200', 'ISP']] as $i => [$cat, $amt, $vendor]) {
            $e = Expense::create(['expense_date' => now()->subDays(10 - $i * 2)->toDateString(), 'expense_category_id' => ExpenseCategory::where('name', $cat)->value('id'),
                'amount' => $amt, 'financial_account_id' => $cash->id, 'vendor' => $vendor, 'status' => 'paid', 'created_by' => $user->id]);
            $e->update(['entry_id' => $poster->expense($e->load(['category.account', 'financialAccount.account']))->id]);
        }
    }
}
