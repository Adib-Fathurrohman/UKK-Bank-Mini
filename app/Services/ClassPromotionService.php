<?php

namespace App\Services;

use App\Exceptions\ClassPromotionException;
use App\Models\ClassPromotion;
use App\Models\Customer;
use App\Models\User;
use App\Support\SchoolYear;
use Illuminate\Support\Facades\DB;

/**
 * Kenaikan kelas: every open account moves up one class, X -> XI and XI -> XII,
 * except the students marked as staying (tinggal kelas).
 *
 * Order of the yearly routine: graduate class XII first (GraduationService),
 * then promote. Promotion refuses to run while class XII still has open
 * accounts, so last year's XII and the promoted XI never mix. It runs once per
 * school year (the year the students move into), all or nothing, with the
 * account ids confirmed in the preview. Only `class` changes: account numbers,
 * balances and history stay.
 */
class ClassPromotionService
{
    private const NEXT = ['X' => 'XI', 'XI' => 'XII'];

    /**
     * The school year the students move into: a run between January and
     * December of 2027 is for 2027/2028 (normally run in June or July).
     */
    public function targetSchoolYear(): int
    {
        return SchoolYear::startYear(now()->addMonths(6));
    }

    /**
     * @return array{customers: \Illuminate\Database\Eloquent\Collection<int, Customer>, school_year: int, open_xii_count: int, already_done: bool}
     */
    public function preview(): array
    {
        $year = $this->targetSchoolYear();

        return [
            'customers' => Customer::with('major')->whereIn('class', array_keys(self::NEXT))
                ->orderByRaw("CASE class WHEN 'XI' THEN 0 ELSE 1 END")->orderBy('major_id')->orderBy('group')->orderBy('full_name')
                ->get(),
            'school_year' => $year,
            'open_xii_count' => Customer::where('class', 'XII')->count(),
            'already_done' => ClassPromotion::where('school_year', $year)->exists(),
        ];
    }

    /**
     * @param  array<int, int>  $confirmedIds  every X and XI account shown in the preview
     * @param  array<int, int>  $retainedIds  those who stay in their class
     */
    public function execute(User $admin, array $confirmedIds, array $retainedIds = [], ?string $note = null): ClassPromotion
    {
        return DB::transaction(function () use ($admin, $confirmedIds, $retainedIds, $note) {
            $year = $this->targetSchoolYear();

            if (ClassPromotion::where('school_year', $year)->lockForUpdate()->exists()) {
                throw ClassPromotionException::alreadyDone(SchoolYear::label($year));
            }

            $customers = Customer::whereIn('class', array_keys(self::NEXT))->orderBy('id')->lockForUpdate()->get();

            if (($open = Customer::where('class', 'XII')->count()) > 0) {
                throw ClassPromotionException::graduateFirst($open);
            }

            if ($customers->isEmpty()) {
                throw ClassPromotionException::nobody();
            }

            $current = $customers->pluck('id')->map(fn ($id) => (int) $id)->all();
            $confirmed = array_map('intval', array_unique($confirmedIds));
            $retained = array_map('intval', array_unique($retainedIds));
            $missing = array_diff($current, $confirmed);
            $unexpected = array_diff(array_unique([...$confirmed, ...$retained]), $current);

            if ($missing || $unexpected) {
                throw ClassPromotionException::selectionChanged($missing, $unexpected);
            }

            $promotion = ClassPromotion::create([
                'school_year' => $year,
                'executed_by' => $admin->id,
                'promoted_count' => $customers->count() - count($retained),
                'retained_count' => count($retained),
                'note' => $note,
            ]);

            $items = [];
            foreach ($customers as $customer) {
                $stays = in_array($customer->id, $retained, true);
                $items[] = [
                    'class_promotion_id' => $promotion->id,
                    'customer_id' => $customer->id,
                    'from_class' => $customer->class,
                    'to_class' => $stays ? $customer->class : self::NEXT[$customer->class],
                    'created_at' => now(),
                ];
            }
            DB::table('class_promotion_items')->insert($items);

            // Updated by id (not by class), so a student moved from X is never moved again.
            foreach (self::NEXT as $from => $to) {
                $ids = collect($items)->where('from_class', $from)->where('to_class', $to)->pluck('customer_id');
                Customer::whereIn('id', $ids)->update(['class' => $to, 'updated_at' => now()]);
            }

            return $promotion;
        });
    }
}
