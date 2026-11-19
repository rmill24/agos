<?php

namespace Database\Seeders;

use App\Enums\GlobalRole;
use App\Enums\TeamRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InvoiceLine;
use App\Models\Obligation;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Fresh-database-only fixture; run with `php artisan migrate:fresh --seed`. */
class AgosCoffeeHouseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
            $monthStart = $today->startOfMonth();
            $owner = $this->user('Jane Doe', 'jane.doe@agos-coffee.test');
            $manager = $this->user('Miguel Santos', 'miguel.santos@agos-coffee.test');
            $bookkeeper = $this->user('Aira Cruz', 'aira.cruz@agos-coffee.test');
            $this->user('Paolo Reyes', 'paolo.reyes@agos-coffee.test', GlobalRole::SystemAdministrator);

            $team = Team::create([
                'name' => 'AGOS Coffee House', 'slug' => 'agos-coffee-house', 'legal_name' => 'AGOS Coffee House', 'business_type' => 'Coffee shop and catering',
                'contact_email' => 'hello@agos-coffee.test', 'contact_phone' => '+63 917 555 0188', 'address_line1' => '28 Maginhawa Street',
                'city' => 'Quezon City', 'province' => 'Metro Manila', 'postal_code' => '1101', 'country_code' => 'PH', 'currency_code' => 'PHP',
                'timezone' => 'Asia/Manila', 'onboarding_completed_at' => $today, 'cash_safety_threshold' => '30000.00',
                'opening_cash_balance' => '65000.00', 'opening_cash_balance_date' => $monthStart, 'opening_cash_balance_verified_at' => $today,
                'opening_cash_balance_verified_by' => $owner->id,
            ]);
            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
            $team->members()->attach($manager, ['role' => TeamRole::Manager->value]);
            $team->members()->attach($bookkeeper, ['role' => TeamRole::Bookkeeper->value]);
            $owner->update(['current_team_id' => $team->id]);
            $manager->update(['current_team_id' => $team->id]);
            $bookkeeper->update(['current_team_id' => $team->id]);

            $categories = $this->categories($team);
            $customers = $this->customers($team);
            $suppliers = $this->suppliers($team);
            [$invoices, $bills] = $this->documents($team, $customers, $suppliers, $monthStart, $today);
            $this->payments($team, $owner, $invoices, $bills, $monthStart, $today, $categories);
            $this->directTransactions($team, $bookkeeper, $monthStart, $today, $categories);
        });
    }

    private function user(string $name, string $email, ?GlobalRole $role = null): User
    {
        return User::create(['name' => $name, 'email' => $email, 'email_verified_at' => now(), 'password' => Hash::make('password'), 'global_role' => $role]);
    }

    /** @return array<string, Category> */
    private function categories(Team $team): array
    {
        $definitions = ['Retail coffee sales' => 'income', 'Catering and events' => 'income', 'Delivery-app payouts' => 'income', 'Coffee beans' => 'expense', 'Milk and dairy' => 'expense', 'Bakery and pastries' => 'expense', 'Packaging and supplies' => 'expense', 'Rent' => 'expense', 'Payroll' => 'expense', 'Utilities' => 'expense', 'Repairs and maintenance' => 'expense', 'Marketing' => 'expense'];
        $categories = [];
        foreach ($definitions as $name => $type) {
            $categories[$name] = Category::create(['team_id' => $team->id, 'name' => $name, 'type' => $type]);
        }

        return $categories;
    }

    /** @return array<int, Customer> */
    private function customers(Team $team): array
    {
        $names = ['Amihan Design Studio', 'Bayanihan Book Club', 'Campus Creatives PH', 'Dalisay Dental Clinic', 'Eastwood Learning Hub', 'Flora & Co. Events', 'Good Neighbor Bakery', 'Haraya Wellness Studio', 'Isla Travel Desk', 'Jade Coworking Space', 'Kape Konseho', 'Luntian Architects', 'Mabuhay Media House', 'Narra Consulting Group', 'Obra Community Center', 'Pili Pilates Studio', 'Quezon City Makers', 'Rizal Review Center', 'Sampaguita Floral Shop', 'Tala Tech Solutions'];

        return array_map(fn (int $index, string $name): Customer => Customer::create(['team_id' => $team->id, 'name' => $name, 'email' => 'accounts.'.($index + 1).'@agos-demo.test', 'phone' => '+63 917 555 '.(1100 + $index)]), array_keys($names), $names);
    }

    /** @return array<int, Supplier> */
    private function suppliers(Team $team): array
    {
        $names = ['Benguet Bean Roasters', 'DairyBest Philippines', 'Golden Crust Bakehouse', 'PackRight Food Solutions', 'Meralco Business Services'];

        return array_map(fn (int $index, string $name): Supplier => Supplier::create(['team_id' => $team->id, 'name' => $name, 'email' => 'orders.'.($index + 1).'@agos-supplier.test', 'phone' => '+63 917 556 '.(1200 + $index)]), array_keys($names), $names);
    }

    /**
     * @param  array<int, Customer>  $customers
     * @param  array<int, Supplier>  $suppliers
     * @return array{0: array<int, Obligation>, 1: array<int, Obligation>}
     */
    private function documents(Team $team, array $customers, array $suppliers, CarbonImmutable $monthStart, CarbonImmutable $today): array
    {
        $invoices = [];
        $products = [['Cold brew bottles', 14500], ['Catering coffee station', 48000], ['Pastry box', 62000], ['Iced latte tray', 11000], ['Coffee beans retail pack', 39500]];
        foreach ($customers as $customerIndex => $customer) {
            for ($sequence = 1; $sequence <= 5; $sequence++) {
                $product = $products[($customerIndex + $sequence) % count($products)];
                $quantity = 2 + (($customerIndex + $sequence) % 4);
                $totalCents = $quantity * $product[1];
                $issueDate = $monthStart->addDays(($customerIndex * 2 + $sequence) % $this->daysInWindow($monthStart, $today));
                $invoice = Obligation::create(['team_id' => $team->id, 'kind' => 'invoice', 'customer_id' => $customer->id, 'number' => sprintf('ACH-INV-%s-%03d', $today->format('Ym'), count($invoices) + 1), 'description' => 'Coffee catering and wholesale order', 'issue_date' => $issueDate, 'due_date' => $issueDate->addDays(7 + ($sequence % 8)), 'total' => $this->money($totalCents)]);
                InvoiceLine::create(['obligation_id' => $invoice->id, 'description' => $product[0], 'quantity' => $quantity, 'unit_price' => $this->money($product[1]), 'line_total' => $this->money($totalCents)]);
                $invoices[] = $invoice;
            }
        }
        $bills = [];
        $descriptions = ['Arabica coffee bean delivery', 'Fresh milk and oat milk delivery', 'Daily pastry supply', 'Cups, lids, and takeaway bags', 'Electricity and store service charge'];
        foreach ($suppliers as $supplierIndex => $supplier) {
            for ($sequence = 1; $sequence <= 5; $sequence++) {
                $issueDate = $monthStart->addDays(($supplierIndex * 3 + $sequence) % $this->daysInWindow($monthStart, $today));
                $bill = Obligation::create(['team_id' => $team->id, 'kind' => 'bill', 'supplier_id' => $supplier->id, 'number' => sprintf('ACH-BILL-%s-%03d', $today->format('Ym'), count($bills) + 1), 'description' => $descriptions[$supplierIndex], 'issue_date' => $issueDate, 'due_date' => $issueDate->addDays(10 + ($sequence % 6)), 'total' => $this->money(180000 + (($supplierIndex * 5 + $sequence) * 27500))]);
                $bills[] = $bill;
            }
        }

        return [$invoices, $bills];
    }

    /**
     * @param  array<int, Obligation>  $invoices
     * @param  array<int, Obligation>  $bills
     * @param  array<string, Category>  $categories
     */
    private function payments(Team $team, User $owner, array $invoices, array $bills, CarbonImmutable $monthStart, CarbonImmutable $today, array $categories): void
    {
        foreach (array_slice($invoices, 0, 35) as $index => $document) {
            $this->payment($team, $owner, $document, $index < 25 ? (string) $document->total : $this->partial((string) $document->total), $monthStart, $today, $categories['Catering and events']);
        }
        foreach (array_slice($bills, 0, 10) as $index => $document) {
            $this->payment($team, $owner, $document, $index < 7 ? (string) $document->total : $this->partial((string) $document->total), $monthStart, $today, $categories[$index % 2 === 0 ? 'Coffee beans' : 'Milk and dairy']);
        }
    }

    private function payment(Team $team, User $owner, Obligation $document, string $amount, CarbonImmutable $monthStart, CarbonImmutable $today, Category $category): void
    {
        $date = $monthStart->addDays(($document->id * 3) % $this->daysInWindow($monthStart, $today));
        $transaction = Transaction::create(['team_id' => $team->id, 'category_id' => $category->id, 'recorded_by' => $owner->id, 'direction' => $document->kind === 'invoice' ? 'income' : 'expense', 'amount' => $amount, 'transaction_date' => $date, 'description' => ucfirst($document->kind).' payment '.$document->number, 'source_type' => 'payment', 'source_reference' => $document->number, 'currency_code' => 'PHP']);
        Payment::create(['team_id' => $team->id, 'obligation_id' => $document->id, 'transaction_id' => $transaction->id, 'submission_key' => (string) Str::uuid()]);
    }

    /** @param array<string, Category> $categories */
    private function directTransactions(Team $team, User $bookkeeper, CarbonImmutable $monthStart, CarbonImmutable $today, array $categories): void
    {
        $entries = [
            ['Retail coffee sales', 'income', 185000, 'Counter sales and card settlements'], ['Retail coffee sales', 'income', 224000, 'Morning coffee and pastry sales'], ['Delivery-app payouts', 'income', 96000, 'Food delivery platform payout'], ['Retail coffee sales', 'income', 210000, 'Weekend coffee sales'], ['Retail coffee sales', 'income', 178000, 'Walk-in sales'], ['Catering and events', 'income', 135000, 'Office coffee cart deposit'], ['Rent', 'expense', 850000, 'Monthly shop rent'], ['Payroll', 'expense', 420000, 'Barista payroll'], ['Utilities', 'expense', 68000, 'Electricity and water bill'], ['Packaging and supplies', 'expense', 47000, 'Cleaning and store supplies'], ['Bakery and pastries', 'expense', 89000, 'Fresh pastry replenishment'], ['Coffee beans', 'expense', 126000, 'Emergency bean purchase'], ['Milk and dairy', 'expense', 74000, 'Milk and oat milk replenishment'], ['Marketing', 'expense', 35000, 'Local social-media promotion'], ['Repairs and maintenance', 'expense', 28000, 'Espresso machine maintenance'], ['Packaging and supplies', 'expense', 39000, 'Cups and takeaway bags'], ['Utilities', 'expense', 12000, 'Internet service'], ['Payroll', 'expense', 310000, 'Part-time staff payroll'], ['Retail coffee sales', 'income', 198000, 'Afternoon coffee sales'], ['Delivery-app payouts', 'income', 104000, 'Food delivery platform payout'], ['Retail coffee sales', 'income', 231000, 'Holiday coffee sales'], ['Coffee beans', 'expense', 118000, 'Coffee bean replenishment'], ['Bakery and pastries', 'expense', 76000, 'Pastry replenishment'], ['Packaging and supplies', 'expense', 33000, 'Napkins and cup sleeves'], ['Retail coffee sales', 'income', 205000, 'Daily walk-in sales'], ['Marketing', 'expense', 22000, 'Community event flyers'], ['Delivery-app payouts', 'income', 88000, 'Food delivery platform payout'], ['Milk and dairy', 'expense', 69000, 'Dairy replenishment'], ['Retail coffee sales', 'income', 193000, 'Counter sales'], ['Utilities', 'expense', 15000, 'Water service payment'],
        ];
        foreach ($entries as $index => [$category, $direction, $cents, $description]) {
            Transaction::create(['team_id' => $team->id, 'category_id' => $categories[$category]->id, 'recorded_by' => $bookkeeper->id, 'direction' => $direction, 'amount' => $this->money($cents), 'transaction_date' => $monthStart->addDays(($index * 5 + 1) % $this->daysInWindow($monthStart, $today)), 'description' => $description, 'source_type' => 'manual', 'currency_code' => 'PHP']);
        }
    }

    private function partial(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $this->money(intdiv($cents * 55, 100));
    }

    private function daysInWindow(CarbonImmutable $monthStart, CarbonImmutable $today): int
    {
        // Both values are midnight Manila dates, making the day difference integral.
        return max(1, (int) $today->diffInDays($monthStart) + 1);
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
