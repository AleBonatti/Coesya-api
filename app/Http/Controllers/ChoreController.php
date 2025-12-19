<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ChoreController extends Controller
{
    public function index(Request $request)
    {
        $family = $this->currentFamilyOrFail($request);

        $q = Chore::query()
            ->where('family_id', $family->id)
            ->orderByDesc('is_active')
            ->orderBy('title');

        // filtri opzionali
        if ($request->filled('active')) {
            $active = filter_var($request->string('active')->toString(), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_null($active)) {
                $q->where('is_active', $active);
            }
        }

        if ($request->filled('category')) {
            $q->where('category', $request->string('category')->toString());
        }

        if ($request->filled('frequency')) {
            $q->where('frequency', $request->string('frequency')->toString());
        }

        return response()->json([
            'success' => 'ok',
            'chores' => $q->get(),
        ]);
    }

    public function active(Request $request)
    {
        $family = $this->currentFamilyOrFail($request);

        $chores = Chore::query()
            ->where('family_id', $family->id)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderByDesc('weight')
            ->orderBy('title')
            ->get();

        // Per evitare N query: calcoliamo tutte le period_key e facciamo 1 query completions
        $now = now();
        $periodByChoreId = [];   // [choreId => ['key' => ..., 'due_at' => Carbon]]
        $keysByChoreId = [];     // [choreId => key]
        $keys = [];              // lista key

        foreach ($chores as $chore) {
            [$key, $start, $endExclusive] = $this->periodRange($chore->frequency, $now);
            $periodByChoreId[$chore->id] = [
                'key' => $key,
                'due_at' => $endExclusive, // in UI puoi mostrare "entro domenica" per weekly
            ];
            $keysByChoreId[$chore->id] = $key;
            $keys[$key] = true;
        }

        $uniqueKeys = array_keys($keys);

        $completions = ChoreCompletion::query()
            ->where('family_id', $family->id)
            ->whereIn('chore_id', $chores->pluck('id'))
            ->whereIn('period_key', $uniqueKeys)
            ->get()
            ->keyBy(fn($c) => $c->chore_id . '|' . $c->period_key);

        $dto = $chores->map(function (Chore $chore) use ($periodByChoreId, $completions) {
            $meta = $periodByChoreId[$chore->id];
            $key = $meta['key'];

            $completion = $completions->get($chore->id . '|' . $key);

            return [
                'id' => $chore->id,
                'title' => $chore->title,
                'frequency' => $chore->frequency,
                'category' => $chore->category,
                'weight' => $chore->weight,
                'priority' => $chore->priority,
                'is_active' => (bool) $chore->is_active,

                // ✅ stato periodo corrente
                'period_key' => $key,
                'due_at' => $meta['due_at']->toIso8601String(), // end-exclusive
                'is_completed' => (bool) $completion,
                'completed_at' => $completion?->completed_at?->toIso8601String(),
                'completed_by_user_id' => $completion?->completed_by_user_id,
            ];
        });

        return response()->json([
            'success' => 'ok',
            'chores' => $dto,
        ]);
    }

    public function complete(Request $request, Chore $chore)
    {
        $family = $this->currentFamilyOrFail($request);

        if ((int)$chore->family_id !== (int)$family->id || !$chore->is_active) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        [$periodKey] = $this->periodRange($chore->frequency, now());

        $completion = DB::transaction(function () use ($family, $chore, $periodKey, $user) {
            return ChoreCompletion::updateOrCreate(
                [
                    'chore_id' => $chore->id,
                    'period_key' => $periodKey,
                ],
                [
                    'family_id' => $family->id,
                    'completed_by_user_id' => $user->id,
                    'completed_at' => now(),
                ]
            );
        });

        return response()->json([
            'success' => 'ok',
            'completion' => [
                'chore_id' => $completion->chore_id,
                'period_key' => $completion->period_key,
                'completed_at' => $completion->completed_at?->toIso8601String(),
                'completed_by_user_id' => $completion->completed_by_user_id,
            ],
        ]);
    }

    public function uncomplete(Request $request, Chore $chore)
    {
        $family = $this->currentFamilyOrFail($request);

        if ((int)$chore->family_id !== (int)$family->id || !$chore->is_active) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        [$periodKey] = $this->periodRange($chore->frequency, now());

        DB::transaction(function () use ($family, $chore, $periodKey) {
            ChoreCompletion::query()
                ->where('family_id', $family->id)
                ->where('chore_id', $chore->id)
                ->where('period_key', $periodKey)
                ->delete();
        });

        return response()->json([
            'success' => 'ok',
            'completion' => null,
            'period_key' => $periodKey,
        ]);
    }

    public function store(Request $request)
    {
        $family = $this->currentFamilyOrFail($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'frequency' => ['required', 'in:daily,weekly,monthly,semiannual'],
            'category' => ['required', 'string', 'max:60'],
            'weight' => ['required', 'integer', 'min:1', 'max:5'],
            'priority' => ['required', 'integer', 'min:1', 'max:5'],
            'is_active' => ['required', 'boolean'],

            // ✅ checkbox: già completato nel periodo corrente
            'completed_current_period' => ['sometimes', 'boolean'],
        ]);

        $completedCurrent = (bool)($data['completed_current_period'] ?? false);
        unset($data['completed_current_period']);

        return DB::transaction(function () use ($request, $family, $data, $completedCurrent) {
            /** @var \App\Models\User $user */
            $user = $request->user();

            $chore = Chore::create([
                'family_id' => $family->id,
                'title' => $data['title'],
                'frequency' => $data['frequency'],
                'category' => $data['category'],
                'weight' => $data['weight'],
                'priority' => $data['priority'],
                'is_active' => $data['is_active'],
            ]);

            if ($completedCurrent) {
                $periodKey = $this->periodKey($chore->frequency);

                ChoreCompletion::updateOrCreate(
                    [
                        'chore_id' => $chore->id,
                        'period_key' => $periodKey,
                    ],
                    [
                        'family_id' => $family->id,
                        'completed_by_user_id' => $user->id,
                        'completed_at' => now(),
                    ]
                );
            }

            return response()->json([
                'success' => 'ok',
                'chore' => $chore,
            ], 201);
        });
    }

    public function update(Request $request, Chore $chore)
    {
        $family = $this->currentFamilyOrFail($request);

        // ✅ impedisci update fuori dalla famiglia corrente
        if ((int)$chore->family_id !== (int)$family->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly,semiannual'],
            'category' => ['sometimes', 'string', 'max:60'],
            'weight' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $chore->fill($data)->save();

        return response()->json([
            'success' => 'ok',
            'chore' => $chore,
        ]);
    }

    public function destroy(Request $request, Chore $chore)
    {
        $family = $this->currentFamilyOrFail($request);

        if ((int)$chore->family_id !== (int)$family->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        DB::transaction(function () use ($chore) {
            ChoreCompletion::where('chore_id', $chore->id)->delete();
            $chore->delete();
        });

        return response()->json(['success' => 'ok']);
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function currentFamilyOrFail(Request $request)
    {
        $user = $request->user();

        $family = $user->families()->wherePivot('current', 1)->first();
        if (!$family) {
            abort(422, 'User has no current family selected.');
        }

        return $family;
    }

    private function periodKey(string $frequency): string
    {
        $now = now();

        return match ($frequency) {
            'daily' => $now->toDateString(),
            'weekly' => sprintf('%d-W%02d', $now->isoWeekYear(), $now->isoWeek()),
            'monthly' => $now->format('Y-m'),
            'semiannual' => $now->month <= 6 ? $now->year . '-H1' : $now->year . '-H2',
            default => throw new \InvalidArgumentException("Invalid frequency: {$frequency}"),
        };
    }

    private function periodRange(string $frequency, ?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : now();

        return match ($frequency) {
            'daily' => [
                $now->toDateString(),                                // key
                $now->copy()->startOfDay(),                           // start
                $now->copy()->startOfDay()->addDay(),                 // endExclusive
            ],

            'weekly' => [
                sprintf('%d-W%02d', $now->isoWeekYear(), $now->isoWeek()),
                $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay(),
                $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay()->addWeek(),
            ],

            'monthly' => [
                $now->format('Y-m'),
                $now->copy()->startOfMonth()->startOfDay(),
                $now->copy()->startOfMonth()->startOfDay()->addMonth(),
            ],

            'semiannual' => (function () use ($now) {
                $half = $now->month <= 6 ? 1 : 2;
                $key = $now->year . '-H' . $half;

                $start = $half === 1
                    ? $now->copy()->month(1)->startOfMonth()->startOfDay()
                    : $now->copy()->month(7)->startOfMonth()->startOfDay();

                $endExclusive = $half === 1
                    ? $now->copy()->month(7)->startOfMonth()->startOfDay()
                    : $now->copy()->addYear()->month(1)->startOfMonth()->startOfDay();

                return [$key, $start, $endExclusive];
            })(),

            default => throw new \InvalidArgumentException("Invalid frequency: {$frequency}"),
        };
    }
}
