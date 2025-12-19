<?php

namespace App\Http\Controllers;

use App\Models\Family;
use Illuminate\Http\Request;

class ChoreController extends Controller
{
    public function index(Request $request)
    {
        sleep(1);

        $user = $request->user();

        $family = Family::where(['user_id' => $user->id, 'main' => 1])
            ->with('chores')
            ->first();

        return response()->json(['success' => 'ok', 'family' => $family]);
    }


    public function store(Request $request)
    {
        sleep(1);

        $request->validate([
            'name' => 'required'
        ]);

        $user = $request->user();

        $family = new Family();
        $family->user_id = $user->id;
        $family->name = $request->name;
        $family->main = $user->families->count() ? 0 : 1;
        $family->save();

        if (is_null($user->has_completed_wizard)) {
            $user->has_completed_wizard = 1;
            $user->save();
        }

        return response()->json(['success' => 'ok']);
    }
}
