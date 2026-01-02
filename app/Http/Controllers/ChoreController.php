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
            ->orderByDesc('is_active')
            ->orderBy('title')
            ->where('family_id', $family->id);

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
            'chores' => $q->with('category')->get(),
        ]);
    }

    public function active(Request $request)
    {
        $family = $this->currentFamilyOrFail($request);

        $chores = Chore::query()
            ->where('family_id', $family->id)
            ->where('is_active', true)
            ->with('category')
            ->orderByDesc('priority')
            ->orderByDesc('weight')
            ->orderBy('title')
            ->get();

        // Calcolo period_key per ogni chore
        $now = now();
        $periodByChoreId = []; // [choreId => ['key' => ..., 'due_at' => Carbon]]
        $keys = [];

        foreach ($chores as $chore) {
            [$key, $start, $endExclusive] = $this->periodRange($chore->frequency, $now);

            $periodByChoreId[$chore->id] = [
                'key' => $key,
                'due_at' => $endExclusive,
            ];

            $keys[$key] = true;
        }

        $uniqueKeys = array_keys($keys);

        // Completions del periodo corrente (per capire cosa è già fatto)
        $periodCompletions = ChoreCompletion::query()
            ->where('family_id', $family->id)
            ->whereIn('chore_id', $chores->pluck('id'))
            ->whereIn('period_key', $uniqueKeys)
            ->get()
            ->keyBy(fn($c) => $c->chore_id . '|' . $c->period_key);

        // ✅ 1) SOLO PENDING: escludo quelli che hanno completion per la period_key corrente
        $prending = [];

        foreach ($chores as $chore) {
            $meta = $periodByChoreId[$chore->id];
            $key = $meta['key'];

            $completionKey = $chore->id . '|' . $key;
            if ($periodCompletions->has($completionKey)) {
                continue; // già completato in questo periodo => NON lo mostro tra gli attivi
            }

            $prending[] = [
                'id' => $chore->id,
                'title' => $chore->title,
                'frequency' => $chore->frequency,
                'category_id' => $chore->category_id,
                'weight' => $chore->weight,
                'priority' => $chore->priority,
                'is_active' => (bool) $chore->is_active,
                'category' => $chore->category,

                // stato periodo corrente
                'period_key' => $key,
                'due_at' => $meta['due_at']->toIso8601String(),
                'is_completed' => false,
                'completed_at' => null,
                'completed_by' => null,
            ];
        }

        // ✅ 2) ULTIMI 3 COMPLETATI (storico breve)
        $completions = ChoreCompletion::query()
            ->where('family_id', $family->id)
            ->with(['chore.category'])
            ->orderByDesc('completed_at')
            ->limit(3)
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'chore_id' => $c->chore_id,
                'family_id' => $c->family_id,
                'completed_by' => $c->completed_by,
                'period_key' => $c->period_key,
                'completed_at' => optional($c->completed_at)->toDateString(), // o toIso8601String()
                'chore' => $c->chore ? [
                    'id' => $c->chore->id,
                    'family_id' => $c->chore->family_id,
                    'assigned_to_user_id' => $c->chore->assigned_to_user_id,
                    'category_id' => $c->chore->category_id,
                    'title' => $c->chore->title,
                    'description' => $c->chore->description,
                    'frequency' => $c->chore->frequency,
                    'weight' => $c->chore->weight,
                    'priority' => $c->chore->priority,
                    'is_active' => (int) $c->chore->is_active,
                    'due_at' => optional($c->chore->due_at)?->toIso8601String(),
                    'created_at' => optional($c->chore->created_at)?->toIso8601String(),
                    'updated_at' => optional($c->chore->updated_at)?->toIso8601String(),
                    'category' => $c->chore->category,
                ] : null,
            ])
            ->values();

        return response()->json([
            'success' => 'ok',
            'pending' => $prending,
            'completions' => $completions,
        ]);
    }

    public function completed(Request $request)
    {
        $family = $this->currentFamilyOrFail($request);

        $completions = ChoreCompletion::query()
            ->orderByDesc('completed_at')
            ->where('family_id', $family->id)
            ->with('chore.category')
            ->get();

        return response()->json([
            'success' => 'ok',
            'completions' => $completions,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $family = $this->currentFamilyOrFail($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'frequency' => ['required', 'in:daily,weekly,monthly,semiannual'],
            'category_id' => ['required'],
            'weight' => ['required', 'integer', 'min:1', 'max:5'],
            'priority' => ['required', 'integer', 'min:1', 'max:5'],
            'is_active' => ['required', 'boolean'],

            // ✅ checkbox: già completato nel periodo corrente
            'completed_current_period' => ['sometimes', 'boolean'],
        ]);

        $completedCurrent = (bool)($data['completed_current_period'] ?? false);
        unset($data['completed_current_period']);

        return DB::transaction(function () use ($request, $user, $family, $data, $completedCurrent) {
            $user = $request->user();

            $chore = Chore::create([
                'family_id' => $family->id,
                'assigned_to_user_id' => $user->id,
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'frequency' => $data['frequency'],
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
                        'completed_by' => $user->id,
                        'completed_at' => now(),
                    ]
                );
            }

            //$chore->load('category');

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
            'title' => ['required', 'string', 'max:120'],
            'frequency' => ['required', 'in:daily,weekly,monthly,semiannual'],
            'category_id' => ['required', 'integer'],
            'weight' => ['required', 'integer', 'min:1', 'max:5'],
            'priority' => ['required', 'integer', 'min:1', 'max:5'],
            'is_active' => ['required', 'boolean'],
        ]);

        $chore->fill($data)->save();

        $chore->load('category');

        return response()->json([
            'success' => 'ok',
            'chore' => $chore,
        ]);
    }

    public function complete(Request $request, Chore $chore)
    {
        $user = $request->user();
        $family = $this->currentFamilyOrFail($request);

        if ((int)$chore->family_id !== (int)$family->id || !$chore->is_active) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        [$periodKey] = $this->periodRange($chore->frequency, now());

        $completion = DB::transaction(function () use ($family, $chore, $periodKey, $user) {
            return ChoreCompletion::updateOrCreate(
                [
                    'chore_id' => $chore->id,
                    'period_key' => $periodKey,
                    'family_id' => $family->id,
                ],
                [
                    'completed_by' => $user->id,
                    'completed_at' => now(),
                ]
            );
        });

        $completion->load('chore');

        return response()->json([
            'success' => 'ok',
            'completion' => $completion,
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
