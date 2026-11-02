<?php

namespace App\Domain\Accounting;

use App\Domain\Tenancy\CurrentTenant;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;

/** Default per-tenant chart of accounts (spec §6.2). Created lazily and idempotently. */
class ChartOfAccounts
{
    public const CASH = '1010';
    public const COD_RECEIVABLE = '1200';
    public const INVENTORY = '1300';
    public const TAX_PAYABLE = '2050';
    public const OWNER_CAPITAL = '3010';
    public const OWNER_DRAWINGS = '3040';
    public const OPENING_EQUITY = '3050';
    public const SALES = '4010';
    public const DELIVERY_INCOME = '4020';
    public const OTHER_INCOME = '4030';
    public const SALES_RETURNS = '4040';
    public const SALES_DISCOUNTS = '4050';
    public const COGS = '5010';
    public const STOCK_ADJUSTMENT = '5030';
    public const COURIER_EXPENSE = '6020';
    public const ADVERTISING = '6010';
    public const LOANS_PAYABLE = '2060';
    public const INTEREST = '6120';

    /** code => [name, type] */
    public const DEFAULTS = [
        '1010' => ['Cash in hand', 'asset'], '1100' => ['Payment gateway clearing', 'asset'], '1200' => ['Courier COD receivable', 'asset'],
        '1210' => ['Customer receivables', 'asset'], '1300' => ['Inventory', 'asset'], '1310' => ['Supplier advances', 'asset'],
        '1320' => ['Prepaid expenses', 'asset'], '1500' => ['Equipment', 'asset'], '1510' => ['Accumulated depreciation', 'asset'],
        '2010' => ['Supplier payables', 'liability'], '2020' => ['Courier charges payable', 'liability'], '2030' => ['Salary payable', 'liability'],
        '2040' => ['Customer advances', 'liability'], '2050' => ['Tax / VAT payable', 'liability'], '2060' => ['Loans payable', 'liability'],
        '2090' => ['Other accrued expenses', 'liability'],
        '3010' => ['Owner capital', 'equity'], '3030' => ['Retained earnings', 'equity'], '3040' => ['Owner drawings', 'equity'], '3050' => ['Opening balance equity', 'equity'],
        '4010' => ['Product sales', 'income'], '4020' => ['Delivery income', 'income'], '4030' => ['Other operating income', 'income'],
        '4040' => ['Sales returns and allowances', 'income'], '4050' => ['Sales discounts', 'income'],
        '5010' => ['Cost of goods sold', 'cogs'], '5020' => ['Inventory write-off', 'cogs'], '5030' => ['Stock loss / damage / adjustments', 'cogs'],
        '6010' => ['Advertising and marketing', 'expense'], '6020' => ['Courier expense', 'expense'], '6030' => ['Packaging', 'expense'],
        '6040' => ['Salaries', 'expense'], '6050' => ['Rent', 'expense'], '6060' => ['Utilities', 'expense'], '6070' => ['Internet and telephone', 'expense'],
        '6080' => ['Software and hosting', 'expense'], '6090' => ['Gateway and bank charges', 'expense'], '6100' => ['Transport', 'expense'],
        '6110' => ['Depreciation', 'expense'], '6120' => ['Interest expense', 'expense'], '6190' => ['Other operating expenses', 'expense'],
    ];

    /** Expense category => account code */
    public const CATEGORIES = [
        'Advertising / marketing' => '6010', 'Courier and return charges' => '6020', 'Packaging' => '6030', 'Salaries' => '6040', 'Rent' => '6050',
        'Utilities' => '6060', 'Internet / telephone' => '6070', 'Software, hosting, domain' => '6080', 'Gateway, bank and MFS fees' => '6090',
        'Transport' => '6100', 'Equipment / depreciation' => '6110', 'Interest' => '6120', 'Other' => '6190',
    ];

    private static array $ensured = [];

    public static function ensure(): void
    {
        $tid = app(CurrentTenant::class)->id() ?? throw new AccountingException('No active workspace.');
        if (isset(self::$ensured[$tid]) && Account::count() > 0) {
            return;
        }
        foreach (self::DEFAULTS as $code => [$name, $type]) {
            Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_system' => true]);
        }
        $cash = Account::where('code', self::CASH)->first();
        FinancialAccount::firstOrCreate(['account_id' => $cash->id], ['name' => 'Cash in hand', 'type' => 'cash']);
        foreach (self::CATEGORIES as $name => $code) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['account_id' => Account::where('code', $code)->value('id')]);
        }
        self::$ensured[$tid] = true;
    }

    public static function id(string $code): int
    {
        self::ensure();

        return Account::where('code', $code)->value('id') ?? throw new AccountingException("Account {$code} is missing.");
    }

    public static function forget(): void
    {
        self::$ensured = [];
    }
}
